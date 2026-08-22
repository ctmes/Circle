<?php

namespace App\Jobs;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\IntegrityStatus;
use App\Enums\ProcessingStatus;
use App\Models\EvidenceVersion;
use App\Services\Audit\AuditChain;
use App\Services\Evidence\EvidenceStorage;
use App\Support\MediaType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Step 4 of the ingest pipeline (spec §7): independently establish what was
 * actually uploaded.
 *
 * Everything the client told us — filename, MIME, size — is treated as a claim.
 * This job records the facts: the real byte size, the detected MIME type and the
 * SHA-256 of the stored object. Only then is the version marked ready and the
 * extraction lanes dispatched.
 */
class ProcessEvidenceVersion implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 900;

    public function __construct(public readonly string $evidenceVersionId)
    {
        $this->onQueue('media');
    }

    public function handle(EvidenceStorage $storage, AuditChain $audit): void
    {
        $version = EvidenceVersion::with('evidenceItem.resource.circle')->find($this->evidenceVersionId);

        if ($version === null) {
            return;
        }

        $item   = $version->evidenceItem;
        $circle = $item->resource->circle;

        $version->forceFill(['processing_status' => ProcessingStatus::Processing])->save();

        try {
            if (! $storage->exists($version->storage_key)) {
                throw new \RuntimeException('The uploaded object is not present in storage.');
            }

            $sha256   = $storage->sha256($version->storage_key);
            $size     = $storage->size($version->storage_key);
            $detected = $storage->detectedMimeType($version->storage_key);

            if ($sha256 === null) {
                throw new \RuntimeException('The stored object could not be read for hashing.');
            }

            $metadata = $version->metadata_json ?? [];

            // Re-processing an already-hashed version must never silently
            // rewrite the hash — a difference means the bytes changed under us,
            // which is exactly what the integrity status exists to surface.
            if ($version->sha256 !== null && ! hash_equals($version->sha256, $sha256)) {
                $metadata['integrity_mismatch']  = true;
                $metadata['previous_sha256']     = $version->sha256;
                $metadata['mismatch_detected_at'] = now()->toISOString();

                $version->forceFill([
                    'metadata_json'     => $metadata,
                    'processing_status' => ProcessingStatus::Failed,
                    'processing_error'  => 'Stored bytes no longer match the recorded SHA-256.',
                ])->save();

                $item->forceFill(['integrity_status' => IntegrityStatus::Changed])->save();

                $audit->record(
                    AuditEventType::ResourceProcessingFailed, $circle, ActorType::System, null,
                    'evidence_version', $version->id, (string) $version->version_number,
                    ['reason' => 'integrity_mismatch', 'expected' => $version->sha256, 'actual' => $sha256],
                );

                return;
            }

            $metadata['detected_mime_type'] = $detected;
            $metadata['declared_mime_type'] = $version->mime_type;
            $metadata['lane'] = $lane = MediaType::laneFor($version->original_filename, $detected);

            $version->forceFill([
                'sha256'        => $sha256,
                'byte_size'     => $size,
                // Prefer the detected type; the declared one is retained in
                // metadata so a mismatch stays visible.
                'mime_type'     => $detected ?: $version->mime_type,
                'metadata_json' => $metadata,
            ])->save();

            $item->forceFill(['integrity_status' => IntegrityStatus::Intact])->save();

            $this->dispatchExtraction($version, $lane);

            // "Ready" means the original is verified and preserved. Each
            // extraction lane reports its own progress separately, so a failed
            // OCR never implies the evidence itself is in doubt.
            $version->forceFill([
                'processing_status' => ProcessingStatus::Ready,
                'processing_error'  => null,
            ])->save();

            $audit->record(
                AuditEventType::ResourceProcessingCompleted, $circle, ActorType::System, null,
                'evidence_version', $version->id, (string) $version->version_number,
                ['sha256' => $sha256, 'byte_size' => $size, 'mime_type' => $detected, 'lane' => $lane],
            );
        } catch (\Throwable $e) {
            Log::error('Evidence processing failed', ['version' => $version->id, 'error' => $e->getMessage()]);

            $version->forceFill([
                'processing_status' => ProcessingStatus::Failed,
                'processing_error'  => $e->getMessage(),
            ])->save();

            $audit->record(
                AuditEventType::ResourceProcessingFailed, $circle, ActorType::System, null,
                'evidence_version', $version->id, (string) $version->version_number,
                ['error' => $e->getMessage()],
            );

            throw $e;
        }
    }

    private function dispatchExtraction(EvidenceVersion $version, string $lane): void
    {
        match ($lane) {
            MediaType::LANE_DOCUMENT    => ExtractDocumentText::dispatch($version->id),
            MediaType::LANE_SPREADSHEET => ExtractSpreadsheet::dispatch($version->id),
            MediaType::LANE_IMAGE       => ProcessImage::dispatch($version->id),
            MediaType::LANE_VIDEO,
            MediaType::LANE_AUDIO       => ProcessTimeBasedMedia::dispatch($version->id),
            // ZIP/PPTX/EML are preserved without extraction (spec §7).
            default                     => null,
        };
    }
}
