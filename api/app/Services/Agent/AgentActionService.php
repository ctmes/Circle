<?php

namespace App\Services\Agent;

use App\Enums\ActorType;
use App\Enums\AgentActionStatus;
use App\Enums\AuditEventType;
use App\Enums\CircleRole;
use App\Enums\Permission;
use App\Enums\SideEffect;
use App\Models\AgentAction;
use App\Models\AgentInstance;
use App\Models\AgentTool;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\CircleParty;
use App\Models\User;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use Illuminate\Support\Facades\DB;

/**
 * The propose → approve → execute loop (spec §20.4).
 *
 * The whole design rests on one asymmetry: proposing is cheap and always
 * recorded, executing is gated and can only happen once. Everything here is
 * arranged so that the interesting failure — an agent doing something nobody
 * agreed to — requires several independent checks to fail at once.
 *
 * Note that a rejected proposal is kept, not deleted. An agent that repeatedly
 * asks for something it is refused is a pattern the record should surface.
 */
class AgentActionService
{
    /** How long an approval stays good for before it stops being consent. */
    public const APPROVAL_TTL_HOURS = 24;

    public function __construct(
        private readonly AuditChain $audit,
        private readonly AccessGate $gate,
    ) {}

    /**
     * An agent asks to do something.
     *
     * Harmless calls are approved on the spot so that a read-only tool does not
     * generate busywork; anything with a side effect stops and waits.
     */
    public function propose(
        AgentInstance $agent,
        AgentTool $tool,
        array $arguments,
        ?string $intent = null,
        ?CircleParty $onBehalfOf = null,
        ?string $agentRunId = null,
        ?string $idempotencyKey = null,
    ): AgentAction {
        $circle = $agent->circle;

        // The gate is asked first, so an agent outside its mandate never even
        // reaches the ledger with a proposal it could not have run.
        $this->gate->authorise($agent, Permission::AgentExecute, $circle);

        abort_unless(
            $tool->agent_blueprint_id === $agent->agent_blueprint_id,
            422,
            'That tool belongs to a different agent.',
        );
        abort_unless($tool->enabled, 422, 'That tool is disabled.');

        // An action carrying real consequence must name the party that bears
        // it. "The Circle did it" is not an answer anyone can act on.
        abort_if(
            $onBehalfOf === null && $tool->side_effect->requiresOwningParty(),
            422,
            'An action with this side effect must name the party it acts for.',
        );

        if ($idempotencyKey !== null) {
            $existing = AgentAction::where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use (
            $agent, $tool, $arguments, $intent, $onBehalfOf, $agentRunId, $idempotencyKey, $circle
        ) {
            $needsHuman = $tool->needsApproval();

            $action = AgentAction::create([
                'circle_id'             => $circle->id,
                'agent_instance_id'     => $agent->id,
                'agent_run_id'          => $agentRunId,
                'agent_tool_id'         => $tool->id,
                'tool_key'              => $tool->key,
                'side_effect'           => $tool->side_effect,
                'status'                => $needsHuman
                    ? AgentActionStatus::AwaitingApproval
                    : AgentActionStatus::Approved,
                'intent'                => $intent,
                'arguments_json'        => $arguments,
                'on_behalf_of_party_id' => $onBehalfOf?->id,
                'expires_at'            => now()->addHours(self::APPROVAL_TTL_HOURS),
                'idempotency_key'       => $idempotencyKey,
            ]);

            $this->audit->record(
                AuditEventType::AgentActionProposed,
                $circle,
                ActorType::Agent,
                $agent->id,
                'agent_action',
                $action->id,
                metadata: [
                    'tool'            => $tool->key,
                    'side_effect'     => $tool->side_effect->value,
                    'needs_approval'  => $needsHuman,
                    'on_behalf_of'    => $onBehalfOf?->label(),
                    'intent'          => $intent,
                    'arguments'       => $arguments,
                ],
            );

            return $action;
        });
    }

    /**
     * A human agrees to it.
     *
     * Three separate things are checked, because each one is a different way
     * this could go wrong: the approver holds the right, the approver is senior
     * enough for this side effect, and the approver belongs to the party that
     * carries the consequence.
     */
    public function approve(AgentAction $action, User $approver, ?string $note = null): AgentAction
    {
        abort_unless(
            $action->status === AgentActionStatus::AwaitingApproval,
            422,
            'This action is not waiting for approval.',
        );
        abort_if($action->isExpired(), 422, 'This proposal has expired. The agent must propose it again.');

        $circle = $action->circle;
        $this->gate->authorise($approver, Permission::AgentApprove, $circle);

        $membership = $this->membership($circle, $approver);
        $tool       = $action->tool;

        if ($tool !== null) {
            $required = $tool->effectiveApprovalRole();

            // Owner satisfies anything; otherwise the role must match exactly
            // what the side effect demands.
            if ($required !== null && $membership->circle_role !== CircleRole::Owner && $membership->circle_role !== $required) {
                abort(403, sprintf('This action needs a %s to approve it.', $required->value));
            }

            if ($tool->needsOwningPartyApprover() && $action->on_behalf_of_party_id !== null) {
                abort_unless(
                    $membership->circle_party_id === $action->on_behalf_of_party_id,
                    403,
                    'Only the party this action acts for can approve it.',
                );
            }
        }

        return DB::transaction(function () use ($action, $approver, $note, $circle) {
            $action->update([
                'status'              => AgentActionStatus::Approved,
                'approved_by_user_id' => $approver->id,
                'approved_at'         => now(),
                // The clock restarts from the approval, not the proposal.
                'expires_at'          => now()->addHours(self::APPROVAL_TTL_HOURS),
            ]);

            $this->audit->record(
                AuditEventType::AgentActionApproved,
                $circle,
                ActorType::User,
                $approver->id,
                'agent_action',
                $action->id,
                metadata: ['tool' => $action->tool_key, 'note' => $note],
            );

            return $action->fresh();
        });
    }

    public function reject(AgentAction $action, User $actor, ?string $reason = null): AgentAction
    {
        abort_unless(
            $action->status === AgentActionStatus::AwaitingApproval,
            422,
            'This action is not waiting for approval.',
        );

        $this->gate->authorise($actor, Permission::AgentApprove, $action->circle);

        return DB::transaction(function () use ($action, $actor, $reason) {
            $action->update([
                'status'              => AgentActionStatus::Rejected,
                'rejected_by_user_id' => $actor->id,
                'rejected_at'         => now(),
                'rejection_reason'    => $reason,
            ]);

            $this->audit->record(
                AuditEventType::AgentActionRejected,
                $action->circle,
                ActorType::User,
                $actor->id,
                'agent_action',
                $action->id,
                metadata: ['tool' => $action->tool_key, 'reason' => $reason],
            );

            return $action->fresh();
        });
    }

    /**
     * Run an approved action.
     *
     * The status flip to `executing` is the lock: it happens in a transaction
     * with a fresh re-read, so two concurrent callers cannot both proceed. That
     * matters more here than anywhere else in the codebase — a double-executed
     * external write is not something an audit trail can undo.
     *
     * The handler is injected rather than dispatched from a registry so that
     * the ledger's guarantees can be tested without any real side effect
     * existing yet.
     */
    public function execute(AgentAction $action, callable $handler): AgentAction
    {
        $locked = DB::transaction(function () use ($action) {
            /** @var AgentAction $fresh */
            $fresh = AgentAction::whereKey($action->id)->lockForUpdate()->first();

            if (! $fresh->isExecutable()) {
                return null;
            }

            $fresh->update(['status' => AgentActionStatus::Executing]);

            return $fresh;
        });

        if ($locked === null) {
            $current = $action->fresh();

            // Expiry is reported as expiry rather than as a generic refusal,
            // because the agent's correct response is to propose again.
            if ($current->isExpired() && ! $current->status->isTerminal()) {
                return $this->expire($current);
            }

            abort(422, 'This action is not in a state that can be executed.');
        }

        try {
            $result = $handler($locked);

            $locked->update([
                'status'      => AgentActionStatus::Executed,
                'result_json' => is_array($result) ? $result : ['value' => $result],
                'executed_at' => now(),
            ]);

            $this->audit->record(
                AuditEventType::AgentActionExecuted,
                $locked->circle,
                ActorType::Agent,
                $locked->agent_instance_id,
                'agent_action',
                $locked->id,
                metadata: [
                    'tool'         => $locked->tool_key,
                    'side_effect'  => $locked->side_effect->value,
                    'approved_by'  => $locked->approved_by_user_id,
                    'on_behalf_of' => $locked->onBehalfOfParty?->label(),
                ],
            );
        } catch (\Throwable $e) {
            $locked->update([
                'status'      => AgentActionStatus::Failed,
                'error'       => $e->getMessage(),
                'executed_at' => now(),
            ]);

            $this->audit->record(
                AuditEventType::AgentActionFailed,
                $locked->circle,
                ActorType::Agent,
                $locked->agent_instance_id,
                'agent_action',
                $locked->id,
                metadata: ['tool' => $locked->tool_key, 'error' => $e->getMessage()],
            );
        }

        return $locked->fresh();
    }

    public function expire(AgentAction $action): AgentAction
    {
        $action->update(['status' => AgentActionStatus::Expired]);

        $this->audit->record(
            AuditEventType::AgentActionExpired,
            $action->circle,
            ActorType::System,
            null,
            'agent_action',
            $action->id,
            metadata: ['tool' => $action->tool_key],
        );

        return $action->fresh();
    }

    /** Actions waiting on a human, newest first. */
    public function queue(Circle $circle)
    {
        return AgentAction::where('circle_id', $circle->id)
            ->where('status', AgentActionStatus::AwaitingApproval->value)
            ->with(['tool', 'agentInstance.blueprint', 'onBehalfOfParty'])
            ->orderBy('expires_at')
            ->get()
            // Anything past its window is reported as expired rather than
            // offered for approval it can no longer receive.
            ->reject(fn (AgentAction $a) => $a->isExpired())
            ->values();
    }

    private function membership(Circle $circle, User $user): CircleMembership
    {
        $membership = CircleMembership::where('circle_id', $circle->id)
            ->where('user_id', $user->id)
            ->first();

        abort_if($membership === null, 403, 'You are not a member of this Circle.');

        return $membership;
    }
}
