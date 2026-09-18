<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Psr\Http\Client\ClientInterface;

/**
 * Claude via the official Anthropic PHP SDK.
 *
 * Uses structured outputs (`outputConfig.format` with a JSON schema) rather than
 * tool use: the Steward is read-only, so there is nothing for the model to call.
 * Constraining the response to a schema means the application parses a known
 * shape instead of trusting free text — the model proposes, the app disposes.
 *
 * Two things this class is deliberately careful about, because both fail
 * silently otherwise:
 *
 * A truncated response is not malformed JSON. Thinking is on by default on the
 * Opus 5 family and its tokens count against `maxTokens`, so a run that hits
 * the ceiling returns valid-but-cut-off text. Reported as a parse error that
 * sends somebody hunting the schema; reported as truncation it names the knob.
 *
 * Cached input is billed differently from fresh input. `usage.inputTokens` is
 * only the uncached part, so a run that reads a warm cache looks far cheaper
 * than it was. All three counters are carried out of here.
 */
class AnthropicProvider implements AiProvider
{
    private ?Client $client = null;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens,
        private readonly int $timeoutSeconds,
        /**
         * Thinking depth and overall token spend. Defaults to the API's `high`
         * when null, which is more than this task needs — see config/circle.php.
         */
        private readonly ?string $effort = null,
        /**
         * PSR-18 transport, for tests. Null in production, where the SDK
         * discovers a real one.
         *
         * This exists because the request built below is the only place the
         * application talks to a model, and without a seam the only way to
         * exercise it is to spend money on a live call — which meant it was
         * never exercised at all.
         */
        private readonly ?ClientInterface $transporter = null,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * A copy of this provider pointed at the model and effort a named task is
     * configured for (config/circle.php -> agent.tasks).
     *
     * The transporter is carried across so a test that injected one keeps it,
     * and an unknown task returns this provider unchanged rather than throwing
     * — a typo in a task name should degrade to the default model, not take
     * down the run.
     */
    public function forTask(string $task): AiProvider
    {
        $settings = config("circle.agent.tasks.{$task}");

        if (! is_array($settings)) {
            return $this;
        }

        return new self(
            apiKey: $this->apiKey,
            model: $settings['model'] ?? $this->model,
            maxTokens: $settings['max_tokens'] ?? $this->maxTokens,
            timeoutSeconds: $this->timeoutSeconds,
            effort: $settings['effort'] ?? $this->effort,
            transporter: $this->transporter,
        );
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

        $outputConfig = [
            'format' => [
                'type'   => 'json_schema',
                // Structured outputs takes a subset of JSON Schema and rejects
                // the rest with a 400 naming one keyword at a time. The Python
                // and TypeScript SDKs strip these client-side; the PHP one does
                // not. See StructuredSchema for why the builders keep saying
                // things this call cannot send.
                'schema' => StructuredSchema::prepare($jsonSchema),
            ],
        ];

        if ($this->effort !== null) {
            $outputConfig['effort'] = $this->effort;
        }

        $message = $this->client()->messages->create(
            model: $this->model,
            maxTokens: $this->maxTokens,
            system: [
                // The mandate is byte-identical on every run of this agent, and
                // for the Steward on every run in the whole instance — so it is
                // the one prefix with a real chance of being read back warm.
                // The evidence below it is Circle-specific and deliberately not
                // cached: see config/circle.php for why.
                ['type' => 'text', 'text' => $systemPrompt, 'cacheControl' => ['type' => 'ephemeral']],
            ],
            messages: [
                ['role' => 'user', 'content' => $userPrompt],
            ],
            outputConfig: $outputConfig,
        );

        // A safety refusal is a legitimate outcome, not a crash. Surface it so
        // the run is recorded as failed with a readable reason.
        if ($message->stopReason === 'refusal') {
            $explanation = $message->stopDetails?->explanation ?? 'no explanation given';

            throw new \RuntimeException("The model declined to answer: {$explanation}");
        }

        // Checked before parsing, because a truncated response is usually still
        // syntactically broken and would otherwise be blamed on the schema.
        if ($message->stopReason === 'max_tokens') {
            throw new \RuntimeException(sprintf(
                'The model ran out of room at %d output tokens and its answer was cut off. '
                . 'Raise AGENT_MAX_TOKENS, or lower AGENT_MAX_SOURCES so there is less to report on.',
                $this->maxTokens,
            ));
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
            cacheReadInputTokens: $message->usage->cacheReadInputTokens,
            cacheCreationInputTokens: $message->usage->cacheCreationInputTokens,
        );
    }

    private function client(): Client
    {
        $options = ['timeout' => (float) $this->timeoutSeconds];

        if ($this->transporter !== null) {
            $options['transporter'] = $this->transporter;
        }

        return $this->client ??= new Client(
            apiKey: $this->apiKey,
            requestOptions: $options,
        );
    }
}
