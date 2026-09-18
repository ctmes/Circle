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
}
