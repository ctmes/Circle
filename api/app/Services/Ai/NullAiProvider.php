<?php

namespace App\Services\Ai;

/**
 * Used when no API key is configured. Runs fail loudly and are recorded as
 * failed agent runs rather than silently producing an empty brief.
 */
class NullAiProvider implements AiProvider
{
    public function isConfigured(): bool
    {
        return false;
    }

    /** There is no model to choose between when there is no provider. */
    public function forTask(string $task): AiProvider
    {
        return $this;
    }

    public function name(): string
    {
        return 'null';
    }

    public function model(): string
    {
        return 'none';
    }

    public function generateStructured(string $systemPrompt, string $userPrompt, array $jsonSchema): AiResult
    {
        // Named generally now that any authored agent reaches this, not only
        // the Steward — a contractor told to configure the Steward when their
        // own agent failed would look in the wrong place.
        throw new \RuntimeException('No AI provider is configured. Set ANTHROPIC_API_KEY to let agents run.');
    }
}
