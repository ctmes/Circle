<?php

namespace App\Services\Agent\Tools;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\CommitmentStatus;
use App\Enums\GoalStatus;
use App\Enums\SideEffect;
use App\Models\AgentAction;
use App\Models\Commitment;
use App\Services\Audit\AuditChain;
use Illuminate\Support\Facades\DB;

/**
 * Drop a goal from the plan (spec §24).
 *
 * This is what "delete a goal" means here, and it is deliberately not a delete.
 * A goal is referenced by commitments, decisions, claims, filed evidence,
 * schedule changes and the audit chain; removing the row would either cascade
 * through all of that or leave it pointing at nothing, and the export would
 * lose the record of work that was planned and then dropped — which is often
 * the most useful thing in it. `abandoned` takes the goal out of the live plan,
 * keeps everything that referred to it, and can be read back.
 *
 * It cascades to the open work beneath, and it cancels the open commitments
 * hanging off every goal it closes. A dropped package with a live deliverable
 * still counting down under it is exactly the kind of contradiction autonomous
 * updates would otherwise leave behind for somebody to find.
 */
class AbandonGoalTool extends BaseTool
{
    public function __construct(private readonly AuditChain $audit) {}

    public function key(): string
    {
        return 'abandon_goal';
    }

    public function name(): string
    {
        return 'Abandon a goal';
    }

    public function description(): string
    {
        return 'Drop a goal from the plan because the meeting decided it is no longer needed. '
            . 'Open work beneath it is dropped with it and its open commitments are cancelled. '
            . 'Nothing is deleted; the goal stays in the record as abandoned.';
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
            'required'             => ['goal_id', 'reason'],
            'properties'           => [
                'goal_id' => ['type' => 'string'],
                'reason'  => [
                    'type'        => 'string',
                    'description' => 'Why it was dropped, in the words of the meeting.',
                ],
            ],
        ];
    }

    public function handle(AgentAction $action): array
    {
        $circle = $this->circle($action);
        $agent  = $this->agent($action);
        $goal   = $this->goal($action);
        $reason = (string) $this->requireArg($action, 'reason');

        $this->assertMayTouch($action, $goal);

        if ($goal->status === GoalStatus::Abandoned) {
            return ['goal_id' => $goal->id, 'changed' => false, 'reason' => 'already abandoned'];
        }

        if ($goal->status === GoalStatus::Met) {
            throw new \RuntimeException(sprintf(
                '"%s" is already met. Finished work is not abandoned after the fact.',
                $goal->title,
            ));
        }

        return DB::transaction(function () use ($circle, $agent, $action, $goal, $reason) {
            $dropped   = [];
            $cancelled = 0;

            foreach ($this->withOpenDescendants($goal) as $node) {
                $node->update(['status' => GoalStatus::Abandoned]);

                $this->audit->record(
                    AuditEventType::GoalAbandoned, $circle, ActorType::Agent, $agent->id,
                    'goal', $node->id, metadata: [
                        'title'         => $node->title,
                        'reason'        => $reason,
                        'cascaded_from' => $node->id === $goal->id ? null : $goal->id,
                        'agent_action'  => $action->id,
                    ],
                );

                $open = Commitment::where('goal_id', $node->id)
                    ->whereIn('status', [
                        CommitmentStatus::Draft->value,
                        CommitmentStatus::Open->value,
                        CommitmentStatus::Blocked->value,
                    ])
                    ->get();

                foreach ($open as $commitment) {
                    $from = $commitment->status;

                    $commitment->update(['status' => CommitmentStatus::Cancelled]);

                    $this->audit->record(
                        AuditEventType::CommitmentUpdated, $circle, ActorType::Agent, $agent->id,
                        'commitment', $commitment->id, metadata: [
                            'from'         => $from->value,
                            'to'           => CommitmentStatus::Cancelled->value,
                            'reason'       => sprintf('The goal "%s" was abandoned: %s', $node->title, $reason),
                            'agent_action' => $action->id,
                        ],
                    );

                    $cancelled++;
                }

                $dropped[] = ['goal_id' => $node->id, 'title' => $node->title];
            }

            return [
                'goal_id'                => $goal->id,
                'title'                  => $goal->title,
                'abandoned'              => $dropped,
                'commitments_cancelled'  => $cancelled,
            ];
        });
    }
}
