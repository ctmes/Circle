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
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Image processing (spec §7): thumbnail, EXIF and OCR where available.
 *
 * Two rules from the spec are load-bearing here:
 *  - EXIF may be *displayed* but is never treated as proof of place or time, so
 *    it is stored with an explicit caveat attached.
 *  - OCR output is a derived artifact, never presented as verified truth.
 */
class ProcessImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 600;

    private const THUMBNAIL_MAX_EDGE = 640;

    public const EXIF_CAVEAT = 'EXIF metadata is supplied by the capturing device and can be absent, '
        . 'wrong, or edited. It is not proof of location or capture time.';

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

        $circleId  = $version->evidenceItem->resource->circle_id;
        $localPath = null;

        $version->forceFill([
            'preview_status'        => ProcessingStatus::Processing,
            'extracted_text_status' => ProcessingStatus::Processing,
        ])->save();

        try {
            $extension = MediaType::extensionOf($version->original_filename) ?: 'jpg';
            $localPath = $storage->pullToTempFile($version->storage_key, $extension);

            if ($localPath === null) {
                throw new \RuntimeException('Could not retrieve the original image.');
            }

            $this->recordMetadata($version, $localPath);
            $this->makeThumbnail($version, $storage, $localPath, $circleId);
            $this->runOcr($version, $storage, $localPath, $circleId);
        } catch (\Throwable $e) {
            Log::warning('Image processing failed', ['version' => $version->id, 'error' => $e->getMessage()]);

            $version->forceFill([
                'preview_status'        => ProcessingStatus::Failed,
                'extracted_text_status' => ProcessingStatus::Failed,
            ])->save();
        } finally {
            if ($localPath !== null) {
                @unlink($localPath);
            }
        }
    }

    private function recordMetadata(EvidenceVersion $version, string $localPath): void
    {
        $metadata = $version->metadata_json ?? [];
        $size = @getimagesize($localPath);

        if ($size !== false) {
            $metadata['width']  = $size[0];
            $metadata['height'] = $size[1];
        }

        $exif = @exif_read_data($localPath);

        if ($exif !== false && $exif !== null) {
            // Keep a useful subset; the full blob is noisy and can be huge.
            $keep = array_intersect_key($exif, array_flip([
                'Make', 'Model', 'DateTimeOriginal', 'Orientation', 'ExifImageWidth',
                'ExifImageLength', 'GPSLatitude', 'GPSLongitude', 'GPSLatitudeRef', 'GPSLongitudeRef',
            ]));

            $metadata['exif'] = $this->stringifyExif($keep);
            $metadata['exif_caveat'] = self::EXIF_CAVEAT;
        }

        $version->forceFill(['metadata_json' => $metadata])->save();
    }

    /** EXIF values can be binary or nested; force them into JSON-safe scalars. */
    private function stringifyExif(array $exif): array
    {
        $clean = [];

        foreach ($exif as $key => $value) {
            if (is_array($value)) {
                $clean[$key] = array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $value);
                continue;
            }

            if (is_scalar($value)) {
                $string = (string) $value;
                $clean[$key] = mb_check_encoding($string, 'UTF-8') ? $string : null;
            }
        }

        return array_filter($clean, fn ($v) => $v !== null);
    }

    private function makeThumbnail(EvidenceVersion $version, EvidenceStorage $storage, string $localPath, string $circleId): void
    {
        // ffmpeg handles HEIC/WEBP and odd colour spaces that GD refuses.
        $thumbPath = $localPath . '.thumb.jpg';

        $process = new Process([
            'ffmpeg', '-y', '-i', $localPath,
            '-vf', sprintf('scale=%d:%d:force_original_aspect_ratio=decrease', self::THUMBNAIL_MAX_EDGE, self::THUMBNAIL_MAX_EDGE),
            '-frames:v', '1', $thumbPath,
        ]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful() || ! file_exists($thumbPath)) {
            $version->forceFill(['preview_status' => ProcessingStatus::Failed])->save();

            return;
        }

        // A fresh object id for the storage key. It is only a filename
        // component — the artifact row generates its own primary key.
        $objectId = (string) Str::ulid();
        $key = $storage->derivedKey($circleId, 'thumbnail', $objectId, 'jpg');
        $storage->putFile($key, $thumbPath, 'image/jpeg');
        @unlink($thumbPath);

        DerivedArtifact::create([
            'circle_id'            => $circleId,
            'parent_resource_type' => 'evidence_version',
            'parent_resource_id'   => $version->id,
            'artifact_type'        => ArtifactType::Thumbnail,
            'storage_key'          => $key,
            'model_provider'       => 'ffmpeg',
            'status'               => 'ready',
            'source_manifest_json' => ['evidence_version_id' => $version->id, 'sha256' => $version->sha256],
        ]);

        $version->forceFill(['preview_status' => ProcessingStatus::Ready])->save();
    }

    private function runOcr(EvidenceVersion $version, EvidenceStorage $storage, string $localPath, string $circleId): void
    {
        $process = new Process(['tesseract', $localPath, 'stdout', '-l', 'eng']);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            // OCR is best-effort; a photo of a muddy site is legitimately
            // unreadable and that is not a processing failure of the evidence.
            $version->forceFill(['extracted_text_status' => ProcessingStatus::Skipped])->save();

            return;
        }

        $text = trim($process->getOutput());

        if ($text === '') {
            $version->forceFill(['extracted_text_status' => ProcessingStatus::Skipped])->save();

            return;
        }

        DerivedArtifact::create([
            'circle_id'            => $circleId,
            'parent_resource_type' => 'evidence_version',
            'parent_resource_id'   => $version->id,
            'artifact_type'        => ArtifactType::Ocr,
            'content_json'         => [
                'extractor'  => 'tesseract',
                'text'       => $text,
                'char_count' => mb_strlen($text),
                'caveat'     => 'OCR output is a machine reading of an image. It is not a verification of the image contents.',
            ],
            'model_provider'       => 'tesseract',
            'status'               => 'ready',
            'source_manifest_json' => ['evidence_version_id' => $version->id, 'sha256' => $version->sha256],
        ]);

        $version->forceFill(['extracted_text_status' => ProcessingStatus::Ready])->save();
    }
}
