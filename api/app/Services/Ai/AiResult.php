<?php

namespace App\Services\Ai;

/**
 * A structured model response plus the provenance every derived artifact must
 * carry (spec §9): which provider, which model, and what it cost.
 */
final class AiResult
{
    public function __construct(
        public readonly array $data,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?string $stopReason = null,
    ) {}
}
