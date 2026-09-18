<?php

namespace App\Providers;

use App\Services\Agent\Tools\CreateCommitmentTool;
use App\Services\Agent\Tools\CreateGoalTool;
use App\Services\Agent\Tools\FlagEvidenceStaleTool;
use App\Services\Agent\Tools\PostCommentTool;
use App\Services\Agent\Tools\ReportGoalProgressTool;
use App\Services\Agent\Tools\ToolRegistry;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AnthropicProvider;
use App\Services\Ai\NullAiProvider;
use App\Services\Transcription\NullTranscriber;
use App\Services\Transcription\Transcriber;
use App\Services\Transcription\WhisperTranscriber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiProvider::class, function () {
            $config = config('circle.agent');

            $provider = match ($config['provider']) {
                'anthropic' => new AnthropicProvider(
                    apiKey: $config['api_key'],
                    model: $config['model'],
                    maxTokens: $config['max_tokens'],
                    timeoutSeconds: $config['timeout_seconds'],
                    effort: $config['effort'] ?? null,
                ),
                default => new NullAiProvider(),
            };

            // An unconfigured provider must fail loudly at run time rather than
            // quietly producing an empty brief.
            return $provider->isConfigured() ? $provider : new NullAiProvider();
        });

        /**
         * The tools that exist.
         *
         * Registered in code rather than seeded into the database, because a
         * handler is executable behaviour and a row is not. A blueprint may
         * declare a tool key that is not in here — the registry refuses it at
         * execution rather than pretending it ran.
         *
         * Every built-in is `circle_write`: reversible, confined to one Circle,
         * and visible in the same register a person's edits land in. External
         * writes are a real integration each, and there is no honest way to
         * ship a generic one.
         */
        $this->app->singleton(ToolRegistry::class, fn ($app) => new ToolRegistry([
            $app->make(PostCommentTool::class),
            $app->make(CreateGoalTool::class),
            $app->make(ReportGoalProgressTool::class),
            $app->make(CreateCommitmentTool::class),
            $app->make(FlagEvidenceStaleTool::class),
        ]));

        $this->app->singleton(Transcriber::class, function () {
            $config = config('circle.transcription');

            return match ($config['driver']) {
                'whisper' => new WhisperTranscriber(
                    endpoint: $config['endpoint'],
                    apiKey: $config['api_key'],
                    model: $config['model'],
                    timeoutSeconds: $config['timeout_seconds'],
                ),
                default => new NullTranscriber(),
            };
        });
    }

    public function boot(): void
    {
        // Evidence, claims and decisions are append-mostly records. Guarding
        // against unguarded mass assignment keeps a stray request from writing
        // a field the policy layer never inspected.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
