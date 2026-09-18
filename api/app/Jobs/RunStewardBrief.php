<?php

namespace App\Jobs;

use App\Models\Circle;
use App\Models\User;
use App\Services\Agent\CircleSteward;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * A first Steward brief over a Circle that has just been convened (spec §23).
 *
 * Queued rather than run inline, for the obvious reason and a less obvious one.
 * The obvious one is latency: convening already spends one model call reading
 * the contract, and making the person wait through a second before their Circle
 * appears would put a minute of spinner in front of the thing they asked for.
 *
 * The less obvious one is that the brief is not part of convening. The plan is
 * written and committed before this is dispatched; if the Steward is misconfigured,
 * over quota, or simply having a bad afternoon, the Circle is unaffected and the
 * failure is recorded on its own run where the agents screen will show it. A
 * brief that could roll back a plan would be a worse trade than no brief.
 *
 * `tries = 1` on purpose. A failed model call is recorded as a failed run and a
 * person can press the button; retrying automatically would spend money twice on
 * a prompt that is likely to fail the same way, and produce two briefs when it
 * does not.
 */
class RunStewardBrief implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        public readonly string $circleId,
        /** Whoever convened. The run is triggered on their behalf and gated as them. */
        public readonly string $triggeredByUserId,
    ) {
        $this->onQueue('agents');
    }

    public function handle(CircleSteward $steward): void
    {
        $circle = Circle::find($this->circleId);
        $user   = User::find($this->triggeredByUserId);

        if ($circle === null || $user === null) {
            return;
        }

        try {
            $steward->runBrief($circle, $user);
        } catch (\Throwable $e) {
            // Already recorded as a failed AgentRun by the runner, which is
            // where somebody will look for it. Logged here so an operator
            // reading worker output sees it too, and swallowed so the job does
            // not sit in the failed queue for a condition a retry cannot fix.
            Log::warning('The convening brief could not be produced', [
                'circle' => $this->circleId,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
