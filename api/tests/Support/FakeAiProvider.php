<?php

namespace Tests\Support;

use App\Services\Ai\AiProvider;
use App\Services\Ai\AiResult;

/**
 * A scripted provider, so the Steward's *handling* of model output can be
 * tested exactly — including the cases a live model only produces occasionally,
 * such as citing an evidence version it was never shown.
 */
class FakeAiProvider implements AiProvider
{
    public ?string $lastSystemPrompt = null;
    public ?string $lastUserPrompt = null;
    public ?array $lastSchema = null;
    public int $calls = 0;

    public function __construct(
        private array $response = [],
        private readonly ?\Throwable $throws = null,
    ) {}

    public function setResponse(array $response): void
    {
        $this->response = $response;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-model-1';
    }

    public function generateStructured(string $systemPrompt, string $userPrompt, array $jsonSchema): AiResult
    {
        $this->calls++;
        $this->lastSystemPrompt = $systemPrompt;
        $this->lastUserPrompt = $userPrompt;
        $this->lastSchema = $jsonSchema;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return new AiResult(
            data: $this->response,
            provider: $this->name(),
            model: $this->model(),
            inputTokens: 1234,
            outputTokens: 567,
            stopReason: 'end_turn',
        );
    }
}
