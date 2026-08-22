<?php

namespace App\Services\Transcription;

/**
 * Used when no transcription provider is configured. It reports that clearly
 * rather than throwing, so an unconfigured deployment marks transcripts
 * "skipped" instead of leaving them pending forever.
 */
class NullTranscriber implements Transcriber
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'null';
    }

    public function transcribe(string $audioPath): TranscriptResult
    {
        throw new \RuntimeException('No transcription provider is configured.');
    }
}
