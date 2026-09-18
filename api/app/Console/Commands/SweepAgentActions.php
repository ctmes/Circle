<?php

namespace App\Console\Commands;

use App\Enums\AgentActionStatus;
use App\Models\AgentAction;
use App\Models\Circle;
use App\Services\Agent\AgentActionService;
use App\Services\Agent\AgentExecutor;
use Illuminate\Console\Command;

/**
 * Two things the ledger cannot do for itself.
 *
 * An unapproved proposal past its window has to actually become `expired`.
 * Until now `isExpired()` was computed on read and the queue quietly filtered
 * those rows out — correct on screen, but the row still said
 * `awaiting_approval` in the database and in any export, which reads as a
 * decision nobody ever made rather than one the clock ran out on.
 *
 * An approval given while the worker was down has to eventually run or
 * eventually lapse. Silently doing neither is the worst of the three, because
 * the approver believes they authorised something that never happened.
 *
 * The sweep is idempotent and takes the same lock the request path takes, so
 * running it beside a live approval cannot double-execute anything.
 */
class SweepAgentActions extends Command
{
    protected $signature = 'circle:sweep-agent-actions {--circle= : Limit to one Circle}';

    protected $description = 'Expire stale agent proposals and run approvals that have not been executed yet';

    public function handle(AgentActionService $actions, AgentExecutor $executor): int
    {
        $expired = 0;

        AgentAction::query()
            ->when($this->option('circle'), fn ($q, $id) => $q->where('circle_id', $id))
            ->where('status', AgentActionStatus::AwaitingApproval->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->cursor()
            ->each(function (AgentAction $action) use ($actions, &$expired) {
                $actions->expire($action);
                $expired++;
            });

        $totals = ['executed' => 0, 'failed' => 0, 'expired' => $expired];

        // Grouped by Circle rather than swept flat, so a Circle that closed
        // between approval and now is skipped as a whole instead of failing
        // each of its actions individually with the same message.
        Circle::query()
            ->when($this->option('circle'), fn ($q, $id) => $q->whereKey($id))
            ->whereHas('agentActions', fn ($q) => $q->where('status', AgentActionStatus::Approved->value))
            ->cursor()
            ->each(function (Circle $circle) use ($executor, &$totals) {
                if ($circle->isClosed()) {
                    return;
                }

                $result = $executor->drain($circle);

                $totals['executed'] += $result['executed'];
                $totals['failed']   += $result['failed'];
                $totals['expired']  += $result['expired'];
            });

        $this->info(sprintf(
            'Agent actions swept: %d executed, %d failed, %d expired.',
            $totals['executed'],
            $totals['failed'],
            $totals['expired'],
        ));

        return self::SUCCESS;
    }
}
