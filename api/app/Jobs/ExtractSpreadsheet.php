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
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Spreadsheet extraction (spec §7).
 *
 * Cells are preserved with their sheet name and A1 coordinates so a citation
 * like `{"sheet": "Load Schedule", "range": "F12:H12"}` resolves to the exact
 * cells a claim depends on — which is the whole point of the crane-load
 * conflict in the pilot scenario.
 */
class ExtractSpreadsheet implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 600;

    /** Guards against a runaway sheet exhausting memory in the worker. */
    private const MAX_CELLS_PER_SHEET = 50000;

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
            $extension = MediaType::extensionOf($version->original_filename) ?: 'xlsx';
            $localPath = $storage->pullToTempFile($version->storage_key, $extension);

            if ($localPath === null) {
                throw new \RuntimeException('Could not retrieve the original for extraction.');
            }

            $reader = IOFactory::createReaderForFile($localPath);
            // Formatting is irrelevant to evidence; values are what get cited.
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($localPath);

            $sheets = [];
            $textParts = [];

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $title = $sheet->getTitle();
                $cells = [];
                $rows  = [];
                $count = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $rowValues = [];
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(true);

                    foreach ($cellIterator as $cell) {
                        if ($count >= self::MAX_CELLS_PER_SHEET) {
                            break 2;
                        }

                        $value = $cell->getCalculatedValue();

                        if ($value === null || $value === '') {
                            continue;
                        }

                        $reference = $cell->getCoordinate();
                        $value = is_scalar($value) ? (string) $value : json_encode($value);

                        $cells[] = [
                            'ref'    => $reference,
                            'row'    => $cell->getRow(),
                            'column' => $cell->getColumn(),
                            'value'  => $value,
                        ];

                        $rowValues[$reference] = $value;
                        $count++;
                    }

                    if ($rowValues !== []) {
                        $rows[] = ['row' => $row->getRowIndex(), 'cells' => $rowValues];
                    }
                }

                $sheets[] = [
                    'name'       => $title,
                    'cell_count' => count($cells),
                    'cells'      => $cells,
                    'rows'       => $rows,
                ];

                // A flat text rendering makes the sheet searchable and gives the
                // agent something readable without inventing a table format.
                $textParts[] = "# Sheet: {$title}\n" . implode("\n", array_map(
                    fn (array $r) => implode("\t", array_map(
                        fn ($ref, $val) => "{$ref}={$val}",
                        array_keys($r['cells']),
                        $r['cells'],
                    )),
                    $rows,
                ));
            }

            $spreadsheet->disconnectWorksheets();

            $fullText = implode("\n\n", $textParts);

            DerivedArtifact::create([
                'circle_id'            => $version->evidenceItem->resource->circle_id,
                'parent_resource_type' => 'evidence_version',
                'parent_resource_id'   => $version->id,
                'artifact_type'        => ArtifactType::Extraction,
                'content_json'         => [
                    'extractor'   => 'phpspreadsheet',
                    'sheet_count' => count($sheets),
                    'sheets'      => $sheets,
                    'text'        => $fullText,
                    'char_count'  => mb_strlen($fullText),
                ],
                'model_provider'       => 'phpspreadsheet',
                'status'               => 'ready',
                'source_manifest_json' => [
                    'evidence_version_id' => $version->id,
                    'sha256'              => $version->sha256,
                    'storage_key'         => $version->storage_key,
                ],
            ]);

            $version->forceFill(['extracted_text_status' => ProcessingStatus::Ready])->save();
        } catch (\Throwable $e) {
            Log::warning('Spreadsheet extraction failed', ['version' => $version->id, 'error' => $e->getMessage()]);

            $version->forceFill(['extracted_text_status' => ProcessingStatus::Failed])->save();
        } finally {
            if ($localPath !== null) {
                @unlink($localPath);
            }
        }
    }
}
