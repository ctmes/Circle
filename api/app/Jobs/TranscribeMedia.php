<?php

namespace App\Jobs;

use App\Enums\ArtifactType;
use App\Enums\ProcessingStatus;
use App\Models\DerivedArtifact;
use App\Models\EvidenceVersion;
use App\Services\Evidence\EvidenceStorage;
use App\Services\Transcription\Transcriber;
use App\Support\MediaType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Produces a timestamped transcript for audio and video (spec §7).
 *
 * The transcript is a derived artifact with an explicit accuracy caveat — it is
 * a machine reading of the recording, not a verified record of what was said.
 */
class TranscribeMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 1800;

    public function __construct(public readonly string $evidenceVersionId)
    {
        $this->onQueue('media');
    }

    public function handle(EvidenceStorage $storage, Transcriber $transcriber): void
    {
        $version = EvidenceVersion::with('evidenceItem.resource')->find($this->evidenceVersionId);

        if ($version === null) {
            return;
        }

        // An unconfigured provider is a deployment state, not a failure. Say so
        // plainly rather than leaving the version pending indefinitely.
        if (! $transcriber->isConfigured()) {
            $version->forceFill(['transcript_status' => ProcessingStatus::Skipped])->save();

            return;
        }

        $version->forceFill(['transcript_status' => ProcessingStatus::Processing])->save();

        $localPath = null;
        $audioPath = null;

        try {
            $extension = MediaType::extensionOf($version->original_filename) ?: 'bin';
            $localPath = $storage->pullToTempFile($version->storage_key, $extension);

            if ($localPath === null) {
                throw new \RuntimeException('Could not retrieve the original media.');
            }

            $audioPath = $this->extractAudio($localPath);
            $result    = $transcriber->transcribe($audioPath);

            DerivedArtifact::create([
                'circle_id'            => $version->evidenceItem->resource->circle_id,
                'parent_resource_type' => 'evidence_version',
                'parent_resource_id'   => $version->id,
                'artifact_type'        => ArtifactType::Transcript,
                'content_json'         => $result->toArray(),
                'model_provider'       => $result->provider,
                'model_name'           => $result->model,
                'status'               => 'ready',
                'source_manifest_json' => [
                    'evidence_version_id' => $version->id,
                    'sha256'              => $version->sha256,
                    'storage_key'         => $version->storage_key,
                ],
            ]);

            $version->forceFill(['transcript_status' => ProcessingStatus::Ready])->save();
        } catch (\Throwable $e) {
            Log::warning('Transcription failed', ['version' => $version->id, 'error' => $e->getMessage()]);

            $version->forceFill(['transcript_status' => ProcessingStatus::Failed])->save();
        } finally {
            foreach ([$localPath, $audioPath] as $path) {
                if ($path !== null) {
                    @unlink($path);
                }
            }
        }
    }

    /** 16 kHz mono WAV is what speech models expect and keeps uploads small. */
    private function extractAudio(string $sourcePath): string
    {
        $audioPath = $sourcePath . '.audio.wav';

        $process = new Process([
            'ffmpeg', '-y', '-i', $sourcePath,
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le',
            $audioPath,
        ]);
        $process->setTimeout(900);
        $process->run();

        if (! $process->isSuccessful() || ! file_exists($audioPath)) {
            throw new \RuntimeException('Audio extraction failed: ' . trim($process->getErrorOutput()));
        }

        return $audioPath;
    }
}
