<?php

namespace App\Jobs;

use App\Enums\ArtifactType;
use App\Enums\ProcessingStatus;
use App\Models\DerivedArtifact;
use App\Models\EvidenceVersion;
use App\Services\Evidence\EvidenceStorage;
use App\Support\MediaType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Document text extraction (spec §7).
 *
 * PDFs are extracted page by page rather than as one blob, because a citation
 * has to resolve to an exact page — `{"page": 18}` is only meaningful if we
 * know where page 18 starts and ends.
 */
class ExtractDocumentText implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 600;

    public function __construct(public readonly string $evidenceVersionId)
    {
        $this->onQueue('media');
    }

    public function handle(EvidenceStorage $storage): void
    {
        $version = EvidenceVersion::with('evidenceItem.resource')->find($this->evidenceVersionId);

        if ($version === null) {
            return;
        }

        $version->forceFill(['extracted_text_status' => ProcessingStatus::Processing])->save();

        $localPath = null;

        try {
            $extension = MediaType::extensionOf($version->original_filename);
            $localPath = $storage->pullToTempFile($version->storage_key, $extension ?: 'bin');

            if ($localPath === null) {
                throw new \RuntimeException('Could not retrieve the original for extraction.');
            }

            $content = match ($extension) {
                'pdf'        => $this->extractPdf($localPath),
                'docx'       => $this->extractDocx($localPath),
                'txt', 'md'  => $this->extractPlainText($localPath),
                default      => throw new \RuntimeException("No document extractor for .{$extension}"),
            };

            DerivedArtifact::create([
                'circle_id'            => $version->evidenceItem->resource->circle_id,
                'parent_resource_type' => 'evidence_version',
                'parent_resource_id'   => $version->id,
                'artifact_type'        => ArtifactType::Extraction,
                'content_json'         => $content,
                'model_provider'       => $content['extractor'],
                'status'               => 'ready',
                'source_manifest_json' => [
                    'evidence_version_id' => $version->id,
                    'sha256'              => $version->sha256,
                    'storage_key'         => $version->storage_key,
                ],
            ]);

            $version->forceFill(['extracted_text_status' => ProcessingStatus::Ready])->save();
        } catch (\Throwable $e) {
            Log::warning('Document extraction failed', ['version' => $version->id, 'error' => $e->getMessage()]);

            $version->forceFill(['extracted_text_status' => ProcessingStatus::Failed])->save();
        } finally {
            if ($localPath !== null) {
                @unlink($localPath);
            }
        }
    }

    /**
     * pdftotext emits a form feed (\f) between pages. Splitting on it gives a
     * per-page index with character offsets, which is what a
     * `{"page": n, "start_char": x}` locator resolves against.
     */
    private function extractPdf(string $path): array
    {
        $process = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('pdftotext failed: ' . trim($process->getErrorOutput()));
        }

        $raw   = $process->getOutput();
        $pages = explode("\f", $raw);

        // pdftotext appends a trailing form feed, producing one empty tail.
        if (end($pages) === '') {
            array_pop($pages);
        }

        $indexed = [];
        $fullText = '';

        foreach ($pages as $i => $pageText) {
            $indexed[] = [
                'page'       => $i + 1,
                'start_char' => mb_strlen($fullText),
                'end_char'   => mb_strlen($fullText) + mb_strlen($pageText),
                'text'       => $pageText,
                'char_count' => mb_strlen($pageText),
            ];

            $fullText .= $pageText;
        }

        return [
            'extractor'  => 'pdftotext',
            'page_count' => count($indexed),
            'pages'      => $indexed,
            'text'       => $fullText,
            'char_count' => mb_strlen($fullText),
        ];
    }

    private function extractDocx(string $path): array
    {
        $reader = \PhpOffice\PhpWord\IOFactory::createReader('Word2007');
        $doc    = $reader->load($path);

        $paragraphs = [];

        foreach ($doc->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text = $this->textOfElement($element);

                if (trim($text) !== '') {
                    $paragraphs[] = $text;
                }
            }
        }

        $fullText = implode("\n\n", $paragraphs);

        return [
            'extractor'       => 'phpword',
            'paragraph_count' => count($paragraphs),
            'paragraphs'      => $paragraphs,
            'text'            => $fullText,
            'char_count'      => mb_strlen($fullText),
        ];
    }

    /** PhpWord elements nest, so text is gathered recursively. */
    private function textOfElement(object $element): string
    {
        if (method_exists($element, 'getText')) {
            $text = $element->getText();

            if (is_string($text)) {
                return $text;
            }
        }

        if (method_exists($element, 'getElements')) {
            return implode('', array_map($this->textOfElement(...), $element->getElements()));
        }

        return '';
    }

    private function extractPlainText(string $path): array
    {
        $text = (string) file_get_contents($path);

        // Normalise to valid UTF-8 so the text survives JSON encoding.
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return [
            'extractor'  => 'plaintext',
            'text'       => $text,
            'char_count' => mb_strlen($text),
        ];
    }
}
