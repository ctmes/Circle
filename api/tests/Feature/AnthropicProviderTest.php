<?php

namespace Tests\Feature;

use App\Services\Ai\AnthropicProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RecordedTransport;
use Tests\TestCase;

/**
 * The one place this application talks to a model.
 *
 * Everything else about the agent is covered against a fake provider, which
 * proves the safety machinery and proves nothing about the request underneath
 * it. This closes that gap: the call is exercised end to end against a recorded
 * response, so a breaking change in the SDK, a dropped cache breakpoint or a
 * misread token counter fails here rather than in front of a customer.
 *
 * Deliberately not a live call. A test that needs an API key is a test nobody
 * runs.
 */
class AnthropicProviderTest extends TestCase
{
    private const SCHEMA = [
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => ['summary'],
        'properties'           => ['summary' => ['type' => 'string']],
    ];

    private function provider(RecordedTransport $transport, ?string $effort = 'medium'): AnthropicProvider
    {
        return new AnthropicProvider(
            apiKey: 'sk-ant-test-key',
            model: 'claude-opus-5',
            maxTokens: 16000,
            timeoutSeconds: 180,
            effort: $effort,
            transporter: $transport,
        );
    }

    /** A realistic reply: thinking block first, then the structured payload. */
    private function reply(array $overrides = []): array
    {
        return array_replace([
            'id'            => 'msg_test',
            'type'          => 'message',
            'role'          => 'assistant',
            'model'         => 'claude-opus-5',
            'content'       => [
                // Thinking is on by default on Opus 5 and `display` defaults to
                // omitted, so a real reply leads with an empty thinking block.
                // The provider has to walk past it to find the JSON.
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig_test'],
                ['type' => 'text', 'text' => json_encode(['summary' => 'Two loads conflict.'])],
            ],
            'stop_reason'   => 'end_turn',
            'stop_sequence' => null,
            'usage'         => [
                'input_tokens'                => 1200,
                'output_tokens'               => 340,
                'cache_read_input_tokens'     => 598,
                'cache_creation_input_tokens' => 0,
            ],
        ], $overrides);
    }

    #[Test]
    public function it_parses_a_structured_reply_past_the_thinking_block(): void
    {
        $transport = (new RecordedTransport)->queue($this->reply());

        $result = $this->provider($transport)->generateStructured('MANDATE', 'EVIDENCE', self::SCHEMA);

        $this->assertSame(['summary' => 'Two loads conflict.'], $result->data);
        $this->assertSame('anthropic', $result->provider);
        // The model as the API reported it, not as we asked for it.
        $this->assertSame('claude-opus-5', $result->model);
        $this->assertSame('end_turn', $result->stopReason);
        $this->assertSame(1, $transport->callCount());
    }

    #[Test]
    public function it_records_cached_input_tokens_separately_from_fresh_ones(): void
    {
        $transport = (new RecordedTransport)->queue($this->reply());

        $result = $this->provider($transport)->generateStructured('MANDATE', 'EVIDENCE', self::SCHEMA);

        $this->assertSame(1200, $result->inputTokens);
        $this->assertSame(340, $result->outputTokens);
        $this->assertSame(598, $result->cacheReadInputTokens);
        $this->assertSame(0, $result->cacheCreationInputTokens);

        // The number anything metering this run should be reading.
        $this->assertSame(1798, $result->totalInputTokens());
    }

    #[Test]
    public function it_sends_the_model_ceiling_effort_and_schema_we_configured(): void
    {
        $transport = (new RecordedTransport)->queue($this->reply());

        $this->provider($transport)->generateStructured('MANDATE', 'EVIDENCE', self::SCHEMA);

        $sent = $transport->sentBody();

        $this->assertSame('claude-opus-5', $sent['model']);
        $this->assertSame(16000, $sent['max_tokens']);
        $this->assertSame('medium', $sent['output_config']['effort']);
        $this->assertSame('json_schema', $sent['output_config']['format']['type']);
        $this->assertSame(self::SCHEMA, $sent['output_config']['format']['schema']);

        // Structured output, not tool use: there is nothing for a read-only
        // agent to call, and a tools array here would be a regression.
        $this->assertArrayNotHasKey('tools', $sent);
    }

    #[Test]
    public function it_puts_the_cache_breakpoint_on_the_stable_mandate(): void
    {
        $transport = (new RecordedTransport)->queue($this->reply());

        $this->provider($transport)->generateStructured('MANDATE', 'EVIDENCE', self::SCHEMA);

        $sent = $transport->sentBody();

        $this->assertSame('MANDATE', $sent['system'][0]['text']);
        $this->assertSame(['type' => 'ephemeral'], $sent['system'][0]['cache_control']);

        // The evidence is Circle-specific and must stay uncached — caching it
        // costs 1.25x to write and is almost never read back warm.
        $this->assertSame('EVIDENCE', $sent['messages'][0]['content']);
        $this->assertArrayNotHasKey('cache_control', $sent['messages'][0]);
    }

    #[Test]
    public function effort_is_omitted_when_it_is_not_configured(): void
    {
        $transport = (new RecordedTransport)->queue($this->reply());

        $this->provider($transport, effort: null)
            ->generateStructured('MANDATE', 'EVIDENCE', self::SCHEMA);

        $this->assertArrayNotHasKey('effort', $transport->sentBody()['output_config']);
    }

    #[Test]
    public function a_refusal_is_reported_with_its_explanation(): void
    {
        $transport = (new RecordedTransport)->queue($this->reply([
            'content'      => [['type' => 'text', 'text' => '']],
            'stop_reason'  => 'refusal',
            'stop_details' => [
                'type'        => 'refusal',
                'category'    => 'cyber',
                'explanation' => 'This request was refused due to policy.',
            ],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This request was refused due to policy.');

        $this->provider($transport)->generateStructured('MANDATE', 'EVIDENCE', self::SCHEMA);
    }

    #[Test]
    public function a_truncated_reply_is_named_as_truncation_not_as_bad_json(): void
    {
        // Exactly what running out of room looks like: valid prose, cut mid-token.
        $transport = (new RecordedTransport)->queue($this->reply([
            'content'     => [['type' => 'text', 'text' => '{"summary": "Two loads con']],
            'stop_reason' => 'max_tokens',
        ]));

        try {
            $this->provider($transport)->generateStructured('MANDATE', 'EVIDENCE', self::SCHEMA);
            $this->fail('A truncated reply should not be accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cut off', $e->getMessage());
            // Names the knob, so nobody goes hunting the schema for this.
            $this->assertStringContainsString('AGENT_MAX_TOKENS', $e->getMessage());
            $this->assertStringNotContainsString('malformed JSON', $e->getMessage());
        }
    }

    #[Test]
    public function genuinely_malformed_json_is_still_reported_as_such(): void
    {
        $transport = (new RecordedTransport)->queue($this->reply([
            'content' => [['type' => 'text', 'text' => 'I cannot help with that.']],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed JSON');

        $this->provider($transport)->generateStructured('MANDATE', 'EVIDENCE', self::SCHEMA);
    }

    #[Test]
    public function a_reply_with_no_text_block_is_refused(): void
    {
        $transport = (new RecordedTransport)->queue($this->reply([
            'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig_test']],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no text content');

        $this->provider($transport)->generateStructured('MANDATE', 'EVIDENCE', self::SCHEMA);
    }

    #[Test]
    public function an_unconfigured_key_never_reaches_the_network(): void
    {
        $transport = new RecordedTransport;

        $provider = new AnthropicProvider(
            apiKey: '',
            model: 'claude-opus-5',
            maxTokens: 16000,
            timeoutSeconds: 180,
            transporter: $transport,
        );

        $this->assertFalse($provider->isConfigured());

        try {
            $provider->generateStructured('MANDATE', 'EVIDENCE', self::SCHEMA);
            $this->fail('An unconfigured provider should refuse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ANTHROPIC_API_KEY', $e->getMessage());
        }

        $this->assertSame(0, $transport->callCount());
    }

    /**
     * Structured outputs implements a subset of JSON Schema and rejects the
     * rest with a 400 — one keyword per round trip, so a schema carrying four
     * of them takes four attempts to clear. Both of this application's schemas
     * carried them and neither had ever reached a live model.
     *
     * The builders still say `minimum: 1`: it documents the intent, a second
     * provider may well honour it, and PlanResolver re-checks every one of
     * those bounds after the run regardless. Only the wire is trimmed.
     */
    #[Test]
    public function it_strips_the_schema_keywords_structured_outputs_refuses(): void
    {
        $transport = (new RecordedTransport)->queue($this->reply());

        $this->provider($transport)->generateStructured('MANDATE', 'EVIDENCE', [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['level'],
            'properties'           => [
                'level'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
                'excerpt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                'steps'   => [
                    'type'     => 'array',
                    'minItems' => 1,
                    'items'    => [
                        'type'       => 'object',
                        'properties' => [
                            'weight' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        ],
                    ],
                ],
            ],
        ]);

        $sent = $transport->sentBody()['output_config']['format']['schema'];

        $this->assertSame(['type' => 'integer'], $sent['properties']['level']);
        $this->assertSame(['type' => 'string'], $sent['properties']['excerpt']);
        $this->assertArrayNotHasKey('minItems', $sent['properties']['steps']);

        // Nested inside an array's items, which is where the recursion matters.
        $this->assertSame(
            ['type' => 'number'],
            $sent['properties']['steps']['items']['properties']['weight'],
        );

        // Everything the API does support survives untouched.
        $this->assertFalse($sent['additionalProperties']);
        $this->assertSame(['level'], $sent['required']);
    }

    #[Test]
    public function it_strips_those_keywords_from_the_schemas_this_application_actually_sends(): void
    {
        foreach ([
            'convening' => \App\Services\Convening\ConveningSchema::build(),
            'brief'     => \App\Services\Agent\OutputSchema::build(),
        ] as $name => $schema) {
            $transport = (new RecordedTransport)->queue($this->reply());

            $this->provider($transport)->generateStructured('MANDATE', 'EVIDENCE', $schema);

            $sent = json_encode($transport->sentBody()['output_config']['format']['schema']);

            foreach (['minimum', 'maximum', 'minItems', 'minLength', 'maxLength'] as $keyword) {
                $this->assertStringNotContainsString(
                    '"' . $keyword . '"',
                    $sent,
                    "The {$name} schema still sends {$keyword}, which the API refuses.",
                );
            }
        }
    }

    /**
     * Reading a contract into a fixed schema and finding the contradiction
     * between two documents are different jobs, and paying one rate for both
     * was a choice nobody made deliberately.
     */
    #[Test]
    public function it_takes_its_model_and_effort_from_the_task_it_was_asked_for(): void
    {
        config()->set('circle.agent.tasks.convening', [
            'model'  => 'claude-haiku-4-5',
            'effort' => 'low',
        ]);

        $transport = (new RecordedTransport)->queue($this->reply());

        $this->provider($transport)->forTask('convening')
            ->generateStructured('MANDATE', 'EVIDENCE', self::SCHEMA);

        $body = $transport->sentBody();

        $this->assertSame('claude-haiku-4-5', $body['model']);
        $this->assertSame('low', $body['output_config']['effort']);
    }

    #[Test]
    public function an_unknown_task_falls_back_to_the_default_rather_than_failing(): void
    {
        $transport = (new RecordedTransport)->queue($this->reply());

        // A typo in a task name must not take down a run whose whole purpose is
        // to produce a brief.
        $this->provider($transport)->forTask('no-such-task')
            ->generateStructured('MANDATE', 'EVIDENCE', self::SCHEMA);

        $this->assertSame('claude-opus-5', $transport->sentBody()['model']);
    }

    #[Test]
    public function asking_for_a_task_does_not_change_the_provider_it_was_asked_of(): void
    {
        config()->set('circle.agent.tasks.convening', ['model' => 'claude-haiku-4-5']);

        $transport = (new RecordedTransport)->queue($this->reply())->queue($this->reply());
        $provider  = $this->provider($transport);

        $provider->forTask('convening')->generateStructured('M', 'E', self::SCHEMA);
        $provider->generateStructured('M', 'E', self::SCHEMA);

        // One task's model must not leak onto the next caller.
        $this->assertSame('claude-haiku-4-5', $transport->sentBody(0)['model']);
        $this->assertSame('claude-opus-5', $transport->sentBody(1)['model']);
    }

    /**
     * Structured outputs compiles a grammar from the schema and refuses one
     * with too many optional parameters, counting every nesting level and every
     * place a shape is inlined. The convening schema ran to 34 against a limit
     * of 24 and was refused on a live call.
     *
     * The guard is here rather than in a comment because the failure mode is
     * silent until somebody uploads a contract: nothing in the type system, the
     * linter or the rest of the suite notices an extra optional field.
     */
    #[Test]
    public function the_schemas_stay_inside_the_grammar_compilers_optional_parameter_budget(): void
    {
        $limit = \App\Services\Convening\ConveningSchema::MAX_OPTIONAL_PARAMETERS;

        $tools = [];

        foreach ([
            'convening' => \App\Services\Convening\ConveningSchema::build(),
            'brief'     => \App\Services\Agent\OutputSchema::build(),
            'authored'  => \App\Services\Agent\OutputSchema::build($tools),
        ] as $name => $schema) {
            $optional = \App\Services\Ai\StructuredSchema::countOptional($schema);

            $this->assertLessThanOrEqual(
                $limit,
                $optional,
                "The {$name} schema declares {$optional} optional parameters; the compiler refuses more than {$limit}. "
                . 'Make a field required, or take one out.',
            );
        }
    }

    /**
     * `additionalProperties` may only ever be false. An open object is refused
     * outright, which is how a locator that accepted anything went unnoticed
     * until the first live brief.
     */
    #[Test]
    public function no_schema_this_application_sends_leaves_an_object_open(): void
    {
        foreach ([
            'convening' => \App\Services\Convening\ConveningSchema::build(),
            'brief'     => \App\Services\Agent\OutputSchema::build(),
        ] as $name => $schema) {
            $this->assertStringNotContainsString(
                '"additionalProperties":true',
                (string) json_encode($schema),
                "The {$name} schema leaves an object open, which the API refuses.",
            );
        }
    }
}
