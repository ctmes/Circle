<?php

namespace App\Providers;

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
                ),
                default => new NullAiProvider(),
            };

            // An unconfigured provider must fail loudly at run time rather than
            // quietly producing an empty brief.
            return $provider->isConfigured() ? $provider : new NullAiProvider();
        });

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
