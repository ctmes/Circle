<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Circle Steward agent
    |---------------------------------------------------------------------------
    | The agent is strictly read-only. These settings control which provider
    | answers a run and how much Circle context it is allowed to be shown. They
    | never widen what the agent may *access* — that is decided solely by the
    | per-resource agent_read flag and the AccessGate.
    */
    'agent' => [
        'provider'      => env('AGENT_PROVIDER', 'anthropic'),
        'model'         => env('AGENT_MODEL', 'claude-opus-5'),
        // Read here rather than via env() at call time so `config:cache` works.
        'api_key'       => env('ANTHROPIC_API_KEY'),
        'max_tokens'    => (int) env('AGENT_MAX_TOKENS', 8000),
        // Cap on characters of extracted text per evidence version placed in
        // the prompt, so one enormous document cannot crowd out the rest.
        'max_chars_per_source' => (int) env('AGENT_MAX_CHARS_PER_SOURCE', 12000),
        'max_sources'          => (int) env('AGENT_MAX_SOURCES', 40),
        'timeout_seconds'      => (int) env('AGENT_TIMEOUT_SECONDS', 180),
    ],

    /*
    | How long an evidence item may go untouched before the Steward may flag it
    | as potentially stale (spec §9). Flagging is a suggestion for humans, never
    | a state change the agent makes on its own authority.
    */
    'staleness' => [
        'after_days' => (int) env('AGENT_STALE_AFTER_DAYS', 30),
    ],

    /*
    |---------------------------------------------------------------------------
    | Transcription
    |---------------------------------------------------------------------------
    | Any OpenAI-compatible /audio/transcriptions endpoint works here, including
    | a self-hosted Whisper server. When unset, transcription is skipped and the
    | version records that plainly rather than appearing to be stuck.
    */
    'transcription' => [
        'driver'   => env('TRANSCRIPTION_DRIVER', 'null'),
        'endpoint' => env('TRANSCRIPTION_ENDPOINT', 'https://api.openai.com/v1/audio/transcriptions'),
        'api_key'  => env('TRANSCRIPTION_API_KEY'),
        'model'    => env('TRANSCRIPTION_MODEL', 'whisper-1'),
        'timeout_seconds' => (int) env('TRANSCRIPTION_TIMEOUT_SECONDS', 600),
    ],

];
