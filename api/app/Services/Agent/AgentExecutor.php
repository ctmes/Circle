<?php

namespace App\Services\Agent;

use App\Enums\AgentActionStatus;
use App\Models\AgentAction;
use App\Models\Circle;
use App\Services\Agent\Tools\ToolRegistry;

/**
 * The part that was missing: something that takes an approved action and runs it.
 *
 * `AgentActionService::execute()` has always taken an injected handler so the
 * ledger's locking and failure recording could be tested before any real side
 * effect existed. This resolves the handler for real, and is the only place in
 * the application that does.
 *
 * It is deliberately thin. Every guarantee — the lock that stops a double
 * execution, the status transitions, the audit events, the refusal to run an
 * expired approval — belongs to AgentActionService and stays there. What is
 * added here is the lookup, and one refusal that only makes sense once handlers
 * exist: a tool whose handler does something more consequential than what the
 * approver was shown does not run at all.
 *
 * A handler that throws is not an error in this class. The ledger records the
 * failure with its message, the action lands in `failed`, and the agent's
 * correct response is to propose something else — which is why nothing here
 * retries. A retry of a side effect nobody re-approved is the exact thing the
 * idempotency key exists to prevent.
 */
class AgentExecutor
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly AgentActionService $actions,
        private readonly \App\Services\Work\EngagementService $engagements,
    ) {}

    public function execute(AgentAction $action): AgentAction
    {
        $result = $this->actions->execute(
            $action,
            // Resolved inside the handler rather than before it, so that a
            // missing or mismatched tool is recorded as a failed action with a
            // reason instead of a bare exception nobody can audit.
            fn (AgentAction $locked) => $this->registry->handlerFor($locked)->handle($locked),
        );

        // Meter it if somebody is paying for this agent (spec §21.6). After
        // execution, never before: an agent does not get paid for asking, and
        // this is the only place that can tell the difference — the proposal
        // was written to the ledger long before we got here.
        //
        // Silent where nothing is hired, which is the overwhelming majority of
        // actions. A failed action is not billed either: meterAgentAction()
        // reads the status rather than the fact that we called it.
        $this->engagements->meterAgentAction($result);

        return $result;
    }

    /**
     * Run everything in a Circle that a human has already agreed to.
     *
     * Used after an approval and by the scheduled sweep, so an approval given
     * while the worker was down is not silently lost — it runs on the next pass
     * or expires honestly.
     *
     * @return array{executed: int, failed: int, expired: int}
     */
    public function drain(Circle $circle): array
    {
        $counts = ['executed' => 0, 'failed' => 0, 'expired' => 0];

        $pending = AgentAction::where('circle_id', $circle->id)
            ->where('status', AgentActionStatus::Approved->value)
            ->orderBy('approved_at')
            ->get();

        foreach ($pending as $action) {
            if ($action->isExpired()) {
                $this->actions->expire($action);
                $counts['expired']++;

                continue;
            }

            $result = $this->execute($action);

            $result->status === AgentActionStatus::Executed
                ? $counts['executed']++
                : $counts['failed']++;
        }

        return $counts;
    }

    /** Whether a declared tool has an implementation behind it. */
    public function canRun(string $toolKey): bool
    {
        return $this->registry->has($toolKey);
    }
}
