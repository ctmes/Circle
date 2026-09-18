<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain / Path
    |--------------------------------------------------------------------------
    |
    | The dashboard is served by the API container, so it answers on the same
    | host as the JSON API: http://localhost:8000/horizon in dev.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    */

    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', 'circle_horizon:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | Access is additionally gated by the `viewHorizon` gate. With no gate
    | defined, Horizon falls back to allowing local only, so the dashboard is
    | closed in production until someone decides who should see it.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | Seconds a queue may back up before Horizon reports it as a long wait.
    | `default` carries password resets and circle notifications, so a minute
    | of backlog there is already a user watching an empty inbox; `media` is
    | expected to be slow and is judged far more leniently.
    |
    */

    'waits' => [
        'redis:default' => 60,
        'redis:agents'  => 180,
        'redis:media'   => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    */

    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    */

    'silenced' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | The limit for the Horizon master process itself, not the workers; worker
    | memory is set per supervisor below.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | One supervisor per queue, which is the whole point of moving off
    | `queue:work --queue=media,agents,default`. That single command drained
    | the queues in priority order in one process, so a fifteen-minute
    | transcode on `media` held up every password reset sitting on `default`.
    | Separate supervisors mean the three queues no longer share a head.
    |
    | `timeout` here must stay below the connection's `retry_after`
    | (config/queue.php), or Redis releases a job that is still running and a
    | second worker picks it up — the same transcode twice, the same
    | extraction written twice.
    |
    | `maxTime` carries over the `--max-time=3600` the production worker ran
    | with: a long-lived PHP process holds whatever it leaks, and one that
    | recycles itself hourly is one fewer thing to notice at three in the
    | morning. Horizon checks it between jobs, so nothing is killed mid-run.
    |
    */

    'defaults' => [
        // Evidence processing: ffmpeg, tesseract, poppler and PhpSpreadsheet.
        // CPU-bound and slow, so it runs few processes with a long timeout and
        // more memory than the stock 128MB — a large workbook parsed into
        // PhpSpreadsheet will not fit in it.
        'supervisor-media' => [
            'connection'         => 'redis',
            'queue'              => ['media'],
            'balance'            => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses'       => 1,
            'maxProcesses'       => 2,
            'balanceMaxShift'    => 1,
            'balanceCooldown'    => 3,
            'maxTime'            => 3600,
            'maxJobs'            => 0,
            'memory'             => 512,
            'tries'              => 3,
            'timeout'            => 900,
            'nice'               => 0,
        ],

        // Agent runs. Nothing dispatches here yet — approving an action
        // executes in the same request — but the queue is named in the
        // deployment and is where model calls land when they move off the
        // request path. `tries` is 2 rather than 3 because a retry here is a
        // second billed model call, not a second attempt at a local file.
        'supervisor-agents' => [
            'connection'         => 'redis',
            'queue'              => ['agents'],
            'balance'            => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses'       => 1,
            'maxProcesses'       => 2,
            'balanceMaxShift'    => 1,
            'balanceCooldown'    => 3,
            'maxTime'            => 3600,
            'maxJobs'            => 0,
            'memory'             => 256,
            'tries'              => 2,
            'timeout'            => 300,
            'nice'               => 0,
        ],

        // Mail and anything else unrouted. Short, cheap jobs that someone is
        // usually waiting on, so this supervisor exists mainly to keep them
        // out from behind `media`.
        'supervisor-default' => [
            'connection'         => 'redis',
            'queue'              => ['default'],
            'balance'            => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses'       => 1,
            'maxProcesses'       => 2,
            'balanceMaxShift'    => 1,
            'balanceCooldown'    => 3,
            'maxTime'            => 3600,
            'maxJobs'            => 0,
            'memory'             => 128,
            'tries'              => 3,
            'timeout'            => 60,
            'nice'               => 0,
        ],
    ],

    'environments' => [

        'production' => [
            'supervisor-media' => [
                'minProcesses' => 1,
                'maxProcesses' => 6,
            ],

            'supervisor-agents' => [
                'minProcesses' => 1,
                'maxProcesses' => 4,
            ],

            'supervisor-default' => [
                'minProcesses' => 1,
                'maxProcesses' => 4,
            ],
        ],

        // One process per supervisor. Three PHP workers is already more than a
        // laptop running the whole stack in Docker wants to spare, and dev
        // throughput was never the problem — isolation was.
        'local' => [
            'supervisor-media' => [
                'maxProcesses' => 1,
            ],

            'supervisor-agents' => [
                'maxProcesses' => 1,
            ],

            'supervisor-default' => [
                'maxProcesses' => 1,
            ],
        ],
    ],
];
