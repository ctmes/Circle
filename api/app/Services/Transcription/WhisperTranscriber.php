<?php

namespace App\Services\Transcription;

use Illuminate\Support\Facades\Http;

/**
 * Any OpenAI-compatible /audio/transcriptions endpoint, including a self-hosted
 * Whisper server. `verbose_json` is requested specifically to get the segment
 * timestamps that audio/video citations depend on.
 */
class WhisperTranscriber implements Transcriber
{
    public function __construct(
        private readonly string $endpoint,
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    public function name(): string
    {
        return 'whisper';
    }

    public function transcribe(string $audioPath): TranscriptResult
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Transcription provider is not configured.');
        }

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeoutSeconds)
            ->attach('file', fopen($audioPath, 'rb'), basename($audioPath))
            ->post($this->endpoint, [
                'model'           => $this->model,
                'response_format' => 'verbose_json',
                'timestamp_granularities[]' => 'segment',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Transcription request failed: ' . $response->body());
        }

        $body = $response->json();

        $segments = array_map(fn (array $s) => [
            'start' => round((float) ($s['start'] ?? 0), 2),
            'end'   => round((float) ($s['end'] ?? 0), 2),
            'text'  => trim((string) ($s['text'] ?? '')),
        ], $body['segments'] ?? []);

        return new TranscriptResult(
            text: trim((string) ($body['text'] ?? '')),
            segments: $segments,
            provider: 'whisper',
            model: $this->model,
            language: $body['language'] ?? null,
        );
    }
}
