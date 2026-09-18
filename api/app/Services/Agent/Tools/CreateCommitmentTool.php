<?php

namespace App\Services\Agent\Tools;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\CommitmentStatus;
use App\Enums\SideEffect;
use App\Models\AgentAction;
use App\Models\Commitment;
use App\Models\Goal;
use App\Services\Audit\AuditChain;
use Illuminate\Support\Facades\DB;

/**
 * Record a piece of work somebody owes.
 *
 * Same rule as the goal tool and for the same reason: the owning party comes
 * from the action's authority, never from the model. A commitment is the thing
 * a dispute eventually turns on, and one an agent could aim at any company
 * would be worth less than an email.
 *
 * `owner_user_id` stays null. An agent may say the work exists; only a person
 * can say who is doing it.
 */
class CreateCommitmentTool extends BaseTool
{
    public function __construct(private readonly AuditChain $audit) {}

    public function key(): string
    {
        return 'create_commitment';
    }

    public function name(): string
    {
        return 'Create a commitment';
    }

    public function description(): string
    {
        return 'Record work the party this agent acts for owes, optionally against a goal and a due date. '
            . 'Nobody is assigned to it — a person picks it up.';
    }

    public function sideEffect(): SideEffect
    {
        return SideEffect::CircleWrite;
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['title'],
            'properties'           => [
                'title'                => ['type' => 'string', 'maxLength' => 200],
                'description'          => ['type' => 'string'],
                'goal_id'              => ['type' => 'string', 'description' => 'The goal this work serves.'],
                'acceptance_condition' => ['type' => 'string'],
                'due_at'               => ['type' => 'string', 'description' => 'ISO 8601 date.'],
            ],
        ];
    }

    public function handle(AgentAction $action): array
    {
        $circle = $this->circle($action);
        $agent  = $this->agent($action);
        $title  = (string) $this->requireArg($action, 'title');

        $goalId = $this->arg($action, 'goal_id');

        if ($goalId !== null && ! Goal::where('circle_id', $circle->id)->whereKey($goalId)->exists()) {
            throw new \RuntimeException(sprintf('No goal %s in this Circle.', $goalId));
        }

        return DB::transaction(function () use ($circle, $agent, $action, $title, $goalId) {
            $commitment = Commitment::create([
                'circle_id'            => $circle->id,
                'goal_id'              => $goalId,
                'title'                => $title,
                'description'          => $this->arg($action, 'description'),
                'acceptance_condition' => $this->arg($action, 'acceptance_condition'),
                'status'               => CommitmentStatus::Open,
                'owner_user_id'        => null,
                'owner_party_id'       => $action->on_behalf_of_party_id,
                'created_by_user_id'   => null,
                'created_by_type'      => 'agent',
                'due_at'               => $this->dateArg($action, 'due_at'),
            ]);

            $this->audit->record(
                AuditEventType::CommitmentCreated, $circle, ActorType::Agent, $agent->id,
                'commitment', $commitment->id, metadata: [
                    'title'        => $title,
                    'goal_id'      => $goalId,
                    'owner_party'  => $action->onBehalfOfParty?->label(),
                    'due_at'       => $commitment->due_at?->format(DATE_ATOM),
                    'agent_action' => $action->id,
                    'approved_by'  => $action->approved_by_user_id,
                ],
            );

            return ['commitment_id' => $commitment->id, 'title' => $commitment->title];
        });
    }
}
