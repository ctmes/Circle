<?php

namespace App\Services\Ai;

use Anthropic\Client;

/**
 * Claude via the official Anthropic PHP SDK.
 *
 * Uses structured outputs (`outputConfig.format` with a JSON schema) rather than
 * tool use: the Steward is read-only, so there is nothing for the model to call.
 * Constraining the response to a schema means the application parses a known
 * shape instead of trusting free text — the model proposes, the app disposes.
 */
class AnthropicProvider implements AiProvider
{
    private ?Client $client = null;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens,
        private readonly int $timeoutSeconds,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generateStructured(string $systemPrompt, string $userPrompt, array $jsonSchema): AiResult
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $message = $this->client()->messages->create(
            model: $this->model,
            maxTokens: $this->maxTokens,
            system: [
                // The mandate is stable across runs, so it is the cache prefix.
                ['type' => 'text', 'text' => $systemPrompt, 'cacheControl' => ['type' => 'ephemeral']],
            ],
            messages: [
                ['role' => 'user', 'content' => $userPrompt],
            ],
            outputConfig: [
                'format' => [
                    'type'   => 'json_schema',
                    'schema' => $jsonSchema,
                ],
            ],
        );

        // A safety refusal is a legitimate outcome, not a crash. Surface it so
        // the run is recorded as failed with a readable reason.
        if ($message->stopReason === 'refusal') {
            $explanation = $message->stopDetails?->explanation ?? 'no explanation given';

            throw new \RuntimeException("The model declined to answer: {$explanation}");
        }

        $json = null;

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = $block->text;
                break;
            }
        }

        if ($json === null) {
            throw new \RuntimeException('The model returned no text content.');
        }

        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The model returned malformed JSON: ' . $e->getMessage());
        }

        if (! is_array($data)) {
            throw new \RuntimeException('The model returned JSON that is not an object.');
        }

        return new AiResult(
            data: $data,
            provider: $this->name(),
            model: $message->model,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            stopReason: $message->stopReason,
        );
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: $this->apiKey,
            requestOptions: ['timeout' => (float) $this->timeoutSeconds],
        );
    }
}
