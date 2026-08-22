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
        throw new \RuntimeException('No AI provider is configured. Set ANTHROPIC_API_KEY to enable the Circle Steward.');
    }
}
