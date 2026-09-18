<?php

namespace App\Services\Ai;

/**
 * A structured model response plus the provenance every derived artifact must
 * carry (spec §9): which provider, which model, and what it cost.
 *
 * Cost is four numbers rather than two because cached input is billed at a
 * different rate from fresh input, and a run that reports only `inputTokens`
 * under-reports what it actually spent. Anything that meters an agent reads
 * these, so they have to describe the whole bill.
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
        /** Tokens served from the prompt cache, billed at ~0.1x input. */
        public readonly ?int $cacheReadInputTokens = null,
        /** Tokens written to the prompt cache, billed at ~1.25x input. */
        public readonly ?int $cacheCreationInputTokens = null,
    ) {}

    /**
     * Every input token this run was billed for, cached or not.
     *
     * `inputTokens` alone is the *uncached* portion — on a run that reads a
     * warm cache it can be a small fraction of what was actually sent, which
     * makes it the wrong number to bill or budget against on its own.
     */
    public function totalInputTokens(): ?int
    {
        if ($this->inputTokens === null) {
            return null;
        }

        return $this->inputTokens
            + ($this->cacheReadInputTokens ?? 0)
            + ($this->cacheCreationInputTokens ?? 0);
    }
}
