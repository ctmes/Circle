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

    /**
     * Replies for successive calls, in order — for a flow that asks two
     * different questions, such as routing a transcript and then reading it.
     * When the queue runs out, the single response set above answers.
     *
     * @var list<array>
     */
    private array $queued = [];

    /** @var list<string|null> which task each call was made for */
    public array $tasks = [];

    public function queueResponses(array ...$responses): void
    {
        array_push($this->queued, ...$responses);
    }

    /** Which task asked, so a test can assert the routing without a real model. */
    public ?string $lastTask = null;

    public function forTask(string $task): AiProvider
    {
        $this->lastTask = $task;

        return $this;
    }

    /** The response for this call: the next queued one, or the standing one. */
    private function nextResponse(): array
    {
        return $this->queued !== [] ? array_shift($this->queued) : $this->response;
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

        $this->tasks[] = $this->lastTask;

        return new AiResult(
            data: $this->nextResponse(),
            provider: $this->name(),
            model: $this->model(),
            inputTokens: 1234,
            outputTokens: 567,
            stopReason: 'end_turn',
        );
    }
}
