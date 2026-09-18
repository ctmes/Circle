<?php

namespace App\Console\Commands;

use App\Enums\EngagementStatus;
use App\Models\Engagement;
use App\Services\Work\EngagementService;
use App\Services\Work\WorkRecordService;
use Illuminate\Console\Command;

/**
 * Move engagements whose term has run out, and compile what they earned
 * (spec §21.2, §21.3).
 *
 * This is **not** a security control, and reading it as one would be a
 * mistake. AccessGate refuses work past `ends_at` on the very next request
 * whether or not this has ever run — check (7) asks the clock, not the status
 * column. A sweep that revoked access would be a second, slower answer to a
 * question already answered correctly, and the slower one would be the one
 * people trusted.
 *
 * What it does is keep the *record* honest. An engagement nobody closed reads
 * as active forever, and a record compiled from it would say the contractor is
 * still there. Expiry is the truthful word for a contract that ran out, and it
 * is the one the portable record needs.
 */
class SweepEngagements extends Command
{
    protected $signature = 'circle:sweep-engagements';

    protected $description = 'Expire engagements past their term and compile the records they earned';

    public function handle(EngagementService $engagements, WorkRecordService $records): int
    {
        $expired = $engagements->expireDue();

        // Every ended engagement without a record, not only the ones just
        // expired: an engagement completed while the worker was down would
        // otherwise never be compiled, and the contractor would carry away
        // nothing from work they finished.
        $compiled = 0;

        $pending = Engagement::query()
            ->whereIn('status', [
                EngagementStatus::Completed->value,
                EngagementStatus::Terminated->value,
                EngagementStatus::Expired->value,
            ])
            ->whereDoesntHave('records')
            ->limit(200)
            ->get();

        foreach ($pending as $engagement) {
            if ($records->compile($engagement) !== null) {
                $compiled++;
            }
        }

        $this->info(sprintf('Expired %d engagement(s); compiled %d record(s).', $expired, $compiled));

        return self::SUCCESS;
    }
}
