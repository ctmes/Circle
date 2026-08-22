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
 * Video and audio processing (spec §7): duration/metadata, poster frames and
 * the audio track that transcription runs against.
 *
 * Timestamps matter more than text here — a claim citing
 * `{"start_seconds": 133}` has to land on the right moment of a site walkthrough.
 */
class ProcessTimeBasedMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 1800;

    /** Poster frames extracted across the duration, for the context grid. */
    private const FRAME_COUNT = 3;

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
        $lane      = $version->lane();
        $localPath = null;

        $version->forceFill(['preview_status' => ProcessingStatus::Processing])->save();

        try {
            $extension = MediaType::extensionOf($version->original_filename) ?: 'bin';
            $localPath = $storage->pullToTempFile($version->storage_key, $extension);

            if ($localPath === null) {
                throw new \RuntimeException('Could not retrieve the original media.');
            }

            $probe = $this->probe($localPath);
            $duration = (float) ($probe['format']['duration'] ?? 0);

            $metadata = $version->metadata_json ?? [];
            $metadata['duration_seconds'] = $duration;
            $metadata['format']           = $probe['format']['format_name'] ?? null;
            $metadata['streams']          = array_map(fn (array $s) => [
                'type'        => $s['codec_type'] ?? null,
                'codec'       => $s['codec_name'] ?? null,
                'width'       => $s['width'] ?? null,
                'height'      => $s['height'] ?? null,
                'sample_rate' => $s['sample_rate'] ?? null,
                'channels'    => $s['channels'] ?? null,
            ], $probe['streams'] ?? []);

            $hasAudio = collect($probe['streams'] ?? [])->contains(fn ($s) => ($s['codec_type'] ?? null) === 'audio');
            $metadata['has_audio_stream'] = $hasAudio;

            $version->forceFill(['metadata_json' => $metadata])->save();

            if ($lane === MediaType::LANE_VIDEO && $duration > 0) {
                $this->extractFrames($version, $storage, $localPath, $circleId, $duration);
            } else {
                $version->forceFill(['preview_status' => ProcessingStatus::Skipped])->save();
            }

            if ($hasAudio) {
                // Transcription is a separate job so a slow or unavailable
                // provider never blocks metadata and previews.
                TranscribeMedia::dispatch($version->id);
            } else {
                $version->forceFill(['transcript_status' => ProcessingStatus::Skipped])->save();
            }
        } catch (\Throwable $e) {
            Log::warning('Media processing failed', ['version' => $version->id, 'error' => $e->getMessage()]);

            $version->forceFill([
                'preview_status'    => ProcessingStatus::Failed,
                'transcript_status' => ProcessingStatus::Failed,
            ])->save();
        } finally {
            if ($localPath !== null) {
                @unlink($localPath);
            }
        }
    }

    private function probe(string $path): array
    {
        $process = new Process([
            'ffprobe', '-v', 'quiet', '-print_format', 'json',
            '-show_format', '-show_streams', $path,
        ]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('ffprobe failed: ' . trim($process->getErrorOutput()));
        }

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function extractFrames(
        EvidenceVersion $version,
        EvidenceStorage $storage,
        string $localPath,
        string $circleId,
        float $duration,
    ): void {
        $captured = 0;

        for ($i = 1; $i <= self::FRAME_COUNT; $i++) {
            // Sample at even intervals, avoiding the very first and last frame
            // which are often black.
            $at = $duration * ($i / (self::FRAME_COUNT + 1));
            $framePath = $localPath . ".frame{$i}.jpg";

            $process = new Process([
                'ffmpeg', '-y', '-ss', (string) round($at, 2), '-i', $localPath,
                '-frames:v', '1', '-vf', 'scale=640:-2', $framePath,
            ]);
            $process->setTimeout(180);
            $process->run();

            if (! $process->isSuccessful() || ! file_exists($framePath)) {
                continue;
            }

            $objectId = (string) Str::ulid();
            $key = $storage->derivedKey($circleId, 'frame', $objectId, 'jpg');
            $storage->putFile($key, $framePath, 'image/jpeg');
            @unlink($framePath);

            DerivedArtifact::create([
                'circle_id'            => $circleId,
                'parent_resource_type' => 'evidence_version',
                'parent_resource_id'   => $version->id,
                'artifact_type'        => $i === 1 ? ArtifactType::Thumbnail : ArtifactType::Frame,
                'storage_key'          => $key,
                'content_json'         => ['at_seconds' => round($at, 2)],
                'model_provider'       => 'ffmpeg',
                'status'               => 'ready',
                'source_manifest_json' => ['evidence_version_id' => $version->id, 'sha256' => $version->sha256],
            ]);

            $captured++;
        }

        $version->forceFill([
            'preview_status' => $captured > 0 ? ProcessingStatus::Ready : ProcessingStatus::Failed,
        ])->save();
    }
}
