<?php

namespace App\Services\Transcription;

/**
 * A transcript plus the timestamped segments a citation can point at.
 *
 * @param list<array{start: float, end: float, text: string}> $segments
 */
final class TranscriptResult
{
    public function __construct(
        public readonly string $text,
        public readonly array $segments,
        public readonly string $provider,
        public readonly ?string $model = null,
        public readonly ?string $language = null,
    ) {}

    public function toArray(): array
    {
        return [
            'text'       => $this->text,
            'segments'   => $this->segments,
            'provider'   => $this->provider,
            'model'      => $this->model,
            'language'   => $this->language,
            'char_count' => mb_strlen($this->text),
            'caveat'     => 'Machine transcription. Wording may be inaccurate, especially for '
                . 'names, figures and technical terms. Verify against the source audio before relying on it.',
        ];
    }
}
