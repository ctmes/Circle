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
     * The same provider, configured for one named task.
     *
     * Not every job in this system needs the same model. Reading a contract
     * into a fixed schema whose every field is re-checked in PHP is a different
     * job from finding the contradiction between two documents, and paying the
     * same rate for both is a choice nobody made deliberately. Tasks are named
     * in config/circle.php, and a name with no entry falls back to the default
     * — an unknown task must not be a fatal error in a code path whose whole
     * purpose is to produce a brief.
     *
     * Returns a configured copy; the provider itself is immutable, so a task
     * cannot leak its model onto the next caller.
     */
    public function forTask(string $task): self;

    /**
     * @param  array  $jsonSchema  A JSON Schema object the response must satisfy.
     * @throws \RuntimeException when the provider fails or returns unusable output.
     */
    public function generateStructured(string $systemPrompt, string $userPrompt, array $jsonSchema): AiResult;
}
