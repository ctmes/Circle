<?php

namespace App\Services\Agent\Tools;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\GoalStatus;
use App\Enums\SideEffect;
use App\Models\AgentAction;
use App\Services\Audit\AuditChain;
use Illuminate\Support\Facades\DB;

/**
 * Close a goal because the meeting said it was done (spec §24).
 *
 * This is the tool that most changes what a goal's status means, so the record
 * it leaves is precise about what happened.
 *
 * Until now a goal reached `met` one way: somebody other than its owner
 * accepted it, and `accepted_by_user_id` named them. That rule is why "met"
 * could be trusted in an argument between two companies. A goal this tool
 * closes is `met` and `accepted_at` is set — it is settled, it stops appearing
 * as open work — but `accepted_by_user_id` stays empty, because no person
 * accepted it, and `completed_by_agent_run_id` names the reading that closed it.
 * The export and the history both show the difference: accepted by a named
 * person, or closed by a transcript, with the words from the meeting that
 * closed it on the audit event.
 *
 * It cascades. "Mobilisation is done" does not mean the phase is done and its
 * three packages are still open, and an agent that closed only the node it was
 * pointed at would leave the tree contradicting itself.
 */
class CompleteGoalTool extends BaseTool
{
    public function __construct(private readonly AuditChain $audit) {}

    public function key(): string
    {
        return 'complete_goal';
    }

    public function name(): string
    {
        return 'Complete a goal';
    }

    public function description(): string
    {
        return 'Mark a goal done, with the words from the meeting that say so. Any open work beneath it is closed with it.';
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
            'required'             => ['goal_id', 'evidence'],
            'properties'           => [
                'goal_id'  => ['type' => 'string'],
                'evidence' => [
                    'type'        => 'string',
                    'description' => 'What was said that means this is done — quote the meeting.',
                ],
            ],
        ];
    }

    public function handle(AgentAction $action): array
    {
        $circle   = $this->circle($action);
        $agent    = $this->agent($action);
        $goal     = $this->goal($action);
        $evidence = (string) $this->requireArg($action, 'evidence');

        $this->assertMayTouch($action, $goal);

        if ($goal->status === GoalStatus::Met) {
            return ['goal_id' => $goal->id, 'changed' => false, 'reason' => 'already met'];
        }

        if ($goal->status === GoalStatus::Abandoned) {
            throw new \RuntimeException(sprintf(
                '"%s" was abandoned. Abandoned work is not completed; open a new goal if it was done after all.',
                $goal->title,
            ));
        }

        return DB::transaction(function () use ($circle, $agent, $action, $goal, $evidence) {
            $closed = [];

            foreach ($this->withOpenDescendants($goal) as $node) {
                $node->update([
                    'status'                    => GoalStatus::Met,
                    'progress'                  => 100,
                    'accepted_at'               => now(),
                    // Empty on purpose. See the class docblock: nobody
                    // accepted this, and the column exists to name who did.
                    'accepted_by_user_id'       => null,
                    'completed_by_agent_run_id' => $action->agent_run_id,
                    'progress_set_by_user_id'   => null,
                    'progress_set_at'           => now(),
                ]);

                $this->audit->record(
                    AuditEventType::GoalAccepted, $circle, ActorType::Agent, $agent->id,
                    'goal', $node->id, metadata: [
                        'title'                => $node->title,
                        'closed_by'            => 'transcript',
                        'evidence'             => $evidence,
                        'cascaded_from'        => $node->id === $goal->id ? null : $goal->id,
                        'acceptance_condition' => $node->acceptance_condition,
                        'agent_action'         => $action->id,
                        'agent_run'            => $action->agent_run_id,
                    ],
                );

                $closed[] = ['goal_id' => $node->id, 'title' => $node->title];
            }

            return [
                'goal_id' => $goal->id,
                'title'   => $goal->title,
                'closed'  => $closed,
            ];
        });
    }
}
