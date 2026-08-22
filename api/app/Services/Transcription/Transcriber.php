<?php

namespace App\Services\Transcription;

/**
 * Transcription provider abstraction (spec §13). Implementations must return
 * timestamped segments — a transcript without timestamps cannot be cited.
 */
interface Transcriber
{
    public function isConfigured(): bool;

    public function name(): string;

    /** @throws \RuntimeException when the provider fails */
    public function transcribe(string $audioPath): TranscriptResult;
}
