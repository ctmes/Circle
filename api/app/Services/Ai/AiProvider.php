<?php

namespace App\Services\Ai;

/**
 * Provider abstraction for the agent (spec §13).
 *
 * The contract is deliberately narrow: given a system prompt, a user prompt and
 * a JSON schema, return data matching that schema. The model never gets to
 * "call" anything — it returns a structured proposal, and the application
 * decides what, if anything, to persist. That is what keeps the agent read-only
 * no matter what the model emits.
 *
 * Implementations exist per provider (Anthropic today; an OpenAI adapter
 * implements this same interface without any caller changes).
 */
interface AiProvider
{
    public function isConfigured(): bool;

    public function name(): string;

    public function model(): string;

    /**
     * @param  array  $jsonSchema  A JSON Schema object the response must satisfy.
     * @throws \RuntimeException when the provider fails or returns unusable output.
     */
    public function generateStructured(string $systemPrompt, string $userPrompt, array $jsonSchema): AiResult;
}
