<?php

namespace App\Services\Agent\Tools;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\GoalStatus;
use App\Enums\SideEffect;
use App\Models\AgentAction;
use App\Models\Goal;
use App\Services\Audit\AuditChain;
use App\Services\Goals\GoalService;
use Illuminate\Support\Facades\DB;

/**
 * Add a node to the goal tree.
 *
 * The agent supplies the shape of the work; it does not supply who owes it.
 * `responsible_party_id` is taken from the party the action runs for and
 * nowhere else, because a sub-goal is a statement about which company is
 * answerable, and an agent that could name any party could assign work to a
 * counterparty who never agreed to it.
 *
 * An owner is not set at all. A person's name against a deliverable is a
 * commitment somebody made, not an inference from a document — a human picks it
 * up in the Work view.
 */
class CreateGoalTool extends BaseTool
{
    public function __construct(private readonly AuditChain $audit) {}

    public function key(): string
    {
        return 'create_goal';
    }

    public function name(): string
    {
        return 'Create a goal';
    }

    public function description(): string
    {
        return 'Add a goal or sub-goal to the work tree, with an acceptance condition and an optional due date. '
            . 'It is created under the party this agent acts for, with no person assigned.';
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
                'title'       => ['type' => 'string', 'maxLength' => 200],
                'description' => ['type' => 'string'],
                'parent_goal_id' => [
                    'type'        => 'string',
                    'description' => 'The goal this sits under. Omit for a top-level goal. The tree has a depth limit; if the parent is already at it, pick a shallower one.',
                ],
                'acceptance_condition' => [
                    'type'        => 'string',
                    'description' => 'What has to be true for this to be accepted as done. State it so a reviewer could check it.',
                ],
                'due_at' => ['type' => 'string', 'description' => 'ISO 8601 date.'],
                'responsible_party_id' => [
                    'type'        => 'string',
                    'description' => 'The party answerable for this. Honoured only for the agents that ship with Circle; '
                        . 'an authored agent always creates work under the party it acts for.',
                ],
            ],
        ];
    }

    /**
     * Who answers for the new goal.
     *
     * For an agent a customer wrote, always the party it acts for — the rule
     * this tool has had since it was written, because an agent that could name
     * any party could hand work to a company that never agreed to it.
     *
     * The agents Circle ships are not acting for one company. The Scribe reads
     * a meeting in which "Northline will issue the traffic plan" was said out
     * loud, and assigning that to the party that said it is reading the room,
     * not volunteering somebody. It may name any party in this Circle, by id,
     * and an id from anywhere else is refused.
     */
    private function responsibleParty(AgentAction $action): ?string
    {
        $named = $this->arg($action, 'responsible_party_id');

        if ($named === null || $named === '' || ! $this->agent($action)->blueprint?->is_system) {
            return $action->on_behalf_of_party_id;
        }

        $party = \App\Models\CircleParty::where('circle_id', $this->circle($action)->id)->find($named);

        if ($party === null) {
            throw new \RuntimeException(sprintf('No party %s in this Circle.', $named));
        }

        return $party->id;
    }

    public function handle(AgentAction $action): array
    {
        $circle = $this->circle($action);
        $agent  = $this->agent($action);
        $title  = (string) $this->requireArg($action, 'title');

        $parent = null;

        if (($parentId = $this->arg($action, 'parent_goal_id')) !== null) {
            $parent = Goal::where('circle_id', $circle->id)->find($parentId);

            if ($parent === null) {
                throw new \RuntimeException(sprintf('No goal %s in this Circle to nest under.', $parentId));
            }

            // The same cap the service layer applies, enforced here too because
            // this path does not go through it.
            $depth = 0;
            $cursor = $parent;

            while ($cursor?->parent_goal_id !== null && $depth <= GoalService::maxDepth() + 2) {
                $cursor = $cursor->parent;
                $depth++;
            }

            if ($depth + 1 >= GoalService::maxDepth()) {
                throw new \RuntimeException(sprintf(
                    'Goals nest %d levels deep at most. Pick a shallower parent.',
                    GoalService::maxDepth(),
                ));
            }
        }

        $party = $this->responsibleParty($action);

        return DB::transaction(function () use ($circle, $agent, $action, $title, $parent, $party) {
            $goal = Goal::create([
                'circle_id'            => $circle->id,
                'parent_goal_id'       => $parent?->id,
                'title'                => $title,
                'description'          => $this->arg($action, 'description'),
                'status'               => GoalStatus::Active,
                'owner_user_id'        => null,
                'responsible_party_id' => $party,
                'acceptance_condition' => $this->arg($action, 'acceptance_condition'),
                'due_at'               => $this->dateArg($action, 'due_at'),
                'position'             => (int) Goal::where('circle_id', $circle->id)
                    ->where('parent_goal_id', $parent?->id)
                    ->max('position') + 1,
                'created_by_type'      => 'agent',
                'created_by_id'        => $agent->id,
            ]);

            $this->audit->record(
                AuditEventType::GoalCreated, $circle, ActorType::Agent, $agent->id,
                'goal', $goal->id, metadata: [
                    'title'             => $title,
                    'parent_goal_id'    => $parent?->id,
                    'responsible_party' => $party === null ? null : \App\Models\CircleParty::find($party)?->label(),
                    'due_at'            => $goal->due_at?->format(DATE_ATOM),
                    'agent_action'      => $action->id,
                    'approved_by'       => $action->approved_by_user_id,
                ],
            );

            return [
                'goal_id'        => $goal->id,
                'title'          => $goal->title,
                'parent_goal_id' => $parent?->id,
            ];
        });
    }
}
