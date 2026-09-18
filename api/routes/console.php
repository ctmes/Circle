<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * The scheduler container has been running `schedule:work` since the stack was
 * built, against an empty schedule. This is the first thing on it.
 *
 * Every five minutes rather than hourly because the approval TTL is 24 hours
 * and the thing being swept is consent: an approval that has already been given
 * should turn into its effect while the person who gave it is still at their
 * desk, and a proposal nobody answered should stop looking answerable promptly.
 *
 * `withoutOverlapping` because the sweep executes real side effects. Two
 * overlapping runs would still be stopped by the row lock inside
 * AgentActionService, but relying on that as the only guard puts the whole
 * safety argument on one `SELECT ... FOR UPDATE`.
 */
Schedule::command('circle:sweep-agent-actions')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/**
 * Engagements past their term, and the records they earned (spec §21).
 *
 * Hourly rather than every five minutes, because unlike the action sweep this
 * is not enforcing anything. AccessGate already refuses work past `ends_at` on
 * the next request — check (7) asks the clock. What this keeps current is the
 * *status*, and through it the portable record: an engagement nobody closed
 * would otherwise read as active forever, and a record compiled from it would
 * say a contractor is still somewhere they left in March.
 */
Schedule::command('circle:sweep-engagements')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

/**
 * Horizon's throughput and wait-time graphs are drawn from snapshots, and
 * nothing takes them unless this runs. Five minutes is the interval Horizon's
 * own metrics windows assume; leave it out and the Metrics tab is simply
 * blank, which reads as "no traffic" rather than "never measured".
 */
Schedule::command('horizon:snapshot')->everyFiveMinutes();
