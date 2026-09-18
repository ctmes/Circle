<?php

namespace App\Services\Agent\Tools;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\SideEffect;
use App\Models\AgentAction;
use App\Models\Goal;
use App\Services\Audit\AuditChain;
use Illuminate\Support\Facades\DB;

/**
 * Report progress against a goal the agent's own party is responsible for.
 *
 * Bounded twice, and both bounds are the ones the goal tree already relies on.
 *
 * A parent goal is refused, exactly as it is for a person: a parent averages
 * its children, and a stored figure on a parent is the "80% complete over
 * sub-goals at 20%" failure the derived-progress rule exists to prevent.
 *
 * A goal belonging to another party is refused. Reported progress is a party's
 * statement about its own work, and one company reporting another's status —
 * even generously — is not a thing the record should be able to express.
 *
 * Note what this cannot touch: acceptance. Progress is what you say about your
 * own work; acceptance is what the counterparty says about it. An agent that
 * could do both could mark its owner's work complete and sign it off.
 */
class ReportGoalProgressTool extends BaseTool
{
    public function __construct(private readonly AuditChain $audit) {}

    public function key(): string
    {
        return 'report_goal_progress';
    }

    public function name(): string
    {
        return 'Report progress on a goal';
    }

    public function description(): string
    {
        return 'Update the reported progress on a goal your party is responsible for. '
            . 'Cannot be used on a parent goal, on another party\'s goal, or to accept work as done.';
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
            'required'             => ['goal_id', 'progress'],
            'properties'           => [
                'goal_id'  => ['type' => 'string'],
                'progress' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'note'     => [
                    'type'        => 'string',
                    'description' => 'What the figure is based on. Cite the evidence if there is any.',
                ],
            ],
        ];
    }

    public function handle(AgentAction $action): array
    {
        $circle   = $this->circle($action);
        $agent    = $this->agent($action);
        $goalId   = (string) $this->requireArg($action, 'goal_id');
        $progress = (int) $this->requireArg($action, 'progress');

        if ($progress < 0 || $progress > 100) {
            throw new \RuntimeException('Progress is a whole percent between 0 and 100.');
        }

        $goal = Goal::where('circle_id', $circle->id)->find($goalId);

        if ($goal === null) {
            throw new \RuntimeException(sprintf('No goal %s in this Circle.', $goalId));
        }

        if ($goal->children()->exists()) {
            throw new \RuntimeException('A parent goal reports the progress of its sub-goals. Update one of those instead.');
        }

        if ($goal->responsible_party_id !== null
            && $goal->responsible_party_id !== $action->on_behalf_of_party_id) {
            throw new \RuntimeException('That goal belongs to another party. You can only report on your own work.');
        }

        $from = (int) $goal->progress;

        if ($from === $progress) {
            return ['goal_id' => $goal->id, 'progress' => $progress, 'changed' => false];
        }

        return DB::transaction(function () use ($circle, $agent, $action, $goal, $from, $progress) {
            $goal->update([
                'progress' => $progress,
                // Left null on purpose: no person set this, and writing the
                // approver's id here would make it look as though they had.
                'progress_set_by_user_id' => null,
                'progress_set_at'         => now(),
            ]);

            $this->audit->record(
                AuditEventType::GoalUpdated, $circle, ActorType::Agent, $agent->id,
                'goal', $goal->id, metadata: [
                    'progress_from' => $from,
                    'progress_to'   => $progress,
                    'note'          => $this->arg($action, 'note'),
                    'agent_action'  => $action->id,
                    'approved_by'   => $action->approved_by_user_id,
                ],
            );

            return ['goal_id' => $goal->id, 'progress' => $progress, 'changed' => true];
        });
    }
}
