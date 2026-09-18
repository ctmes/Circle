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

        /*
        | Output ceiling. Thinking is on by default on the Opus 5 family and
        | its tokens count against this, so the old 8000 was a ceiling a brief
        | over a full Circle could reach — and hitting it truncates the JSON
        | mid-answer. The provider now names that failure instead of reporting
        | it as malformed output, but the headroom is the actual fix.
        |
        | Kept well under the model's 128K cap: past roughly this size the
        | request wants streaming to stay inside HTTP timeouts, and a brief
        | nobody can read in one sitting is not a better brief.
        */
        'max_tokens'    => (int) env('AGENT_MAX_TOKENS', 16000),

        /*
        | How hard the model works before answering: low, medium, high, xhigh,
        | max. The API's own default is `high`; this is deliberately lower.
        |
        | The task is summarise-and-cite over text that has already been
        | retrieved, filtered and laid out for it — not open-ended reasoning.
        | The expensive judgement calls in this system belong to people, and
        | the citation validator in AgentRunner catches the failure mode that
        | more thinking would buy protection from. Raise it if measurement
        | shows citations being dropped; do not raise it on a hunch.
        */
        'effort'        => env('AGENT_EFFORT', 'medium'),
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
    | The goal tree
    |---------------------------------------------------------------------------
    | How deep work may be broken down. Two levels was the original cap, on the
    | reasoning that anything deeper becomes a work-breakdown structure nobody
    | maintains. That reasoning holds for a single company and breaks for the
    | case this product is now aimed at: principal → package → contractor →
    | subcontractor is four levels before anybody has padded anything, and
    | capping at two forced that real structure into titles like
    | "Beam Rail / bogies / weld inspection".
    |
    | Still a cap rather than unlimited. A tree with no floor is one somebody
    | eventually uses as a task list, and the tree is meant to hold the shape
    | of the mission — commitments are where individual pieces of work live.
    */
    'goals' => [
        'max_depth' => (int) env('CIRCLE_GOAL_MAX_DEPTH', 4),
    ],

    /*
    |---------------------------------------------------------------------------
    | Mission packet export
    |---------------------------------------------------------------------------
    | The packet now carries the original binaries, not only their digests, so a
    | recipient can open the evidence without holding an account here. A Circle
    | with site video in it can run to many gigabytes, so the originals are
    | capped: the packer takes current versions before superseded ones, skips
    | anything that will not fit, and names every omission and its reason in
    | manifest.json. Set the cap to 0 to record digests only.
    */
    'export' => [
        'max_original_bytes' => (int) env('EXPORT_MAX_ORIGINAL_BYTES', 1024 * 1024 * 1024),
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

    /*
    |---------------------------------------------------------------------------
    | Notifications
    |---------------------------------------------------------------------------
    | Until this existed nothing left the browser, which meant the goal tree's
    | deadlines, an assigned decision and an agent waiting on approval were all
    | state somebody had to remember to go and look at — the same as no signal
    | at all.
    |
    | Deliberately four events and a mention, not a preferences system. Every
    | one of them is a thing that happened to *you* and that you cannot find out
    | about any other way. Nothing here digests, batches or reminds: a product
    | that emails people about things they could have seen teaches them to
    | filter it, and the one message that mattered goes with the rest.
    |
    | `web_url` is the front end, not the API. A notification whose link lands
    | on a JSON endpoint is a notification nobody can act on.
    */
    'notifications' => [
        'enabled' => (bool) env('CIRCLE_NOTIFICATIONS', true),
        'web_url' => rtrim((string) env('WEB_APP_URL', 'http://localhost:4321'), '/'),
    ],

];
