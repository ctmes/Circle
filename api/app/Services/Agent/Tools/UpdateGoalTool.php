<?php

namespace App\Services\Agent\Tools;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\GoalStatus;
use App\Enums\SideEffect;
use App\Models\AgentAction;
use App\Models\Goal;
use App\Models\GoalScheduleChange;
use App\Services\Audit\AuditChain;
use Illuminate\Support\Facades\DB;

/**
 * Change a goal the plan already has (spec §24).
 *
 * One tool for every ordinary edit — wording, acceptance test, status, progress,
 * due date — because a meeting rarely changes one field at a time, and a
 * transcript that renamed a package and moved its date should read in the
 * ledger as one decision rather than two unrelated ones.
 *
 * Two edits are deliberately not here. Closing a goal is `complete_goal` and
 * dropping one is `abandon_goal`: both settle a node, both cascade to the work
 * beneath it, and both deserve a line of their own in the log.
 *
 * A moved due date is recorded as a schedule change, never as a quiet column
 * update, which is the rule GoalService has always held for people: a date
 * cannot move without the record saying who moved it and why. For an agent the
 * "who" is the agent and the "why" is what was said in the meeting.
 */
class UpdateGoalTool extends BaseTool
{
    /** Statuses an update may set. Met and abandoned have tools of their own. */
    private const SETTABLE = ['active', 'blocked', 'in_review'];

    public function __construct(private readonly AuditChain $audit) {}

    public function key(): string
    {
        return 'update_goal';
    }

    public function name(): string
    {
        return 'Update a goal';
    }

    public function description(): string
    {
        return 'Change a goal\'s wording, acceptance condition, status (active, blocked, in review), progress or due date. '
            . 'A moved due date is recorded as a schedule change with the reason given.';
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
            'required'             => ['goal_id'],
            'properties'           => [
                'goal_id'              => ['type' => 'string'],
                'title'                => ['type' => 'string'],
                'description'          => ['type' => 'string'],
                'acceptance_condition' => ['type' => 'string'],
                'status'               => ['type' => 'string', 'enum' => self::SETTABLE],
                'progress'             => ['type' => 'integer', 'description' => 'Whole percent, 0-100. Leaf goals only.'],
                'due_at'               => ['type' => 'string', 'description' => 'ISO 8601 date.'],
                'reason'               => [
                    'type'        => 'string',
                    'description' => 'Why, in the words of the meeting. Recorded against a moved date.',
                ],
            ],
        ];
    }

    public function handle(AgentAction $action): array
    {
        $circle = $this->circle($action);
        $agent  = $this->agent($action);
        $goal   = $this->goal($action);

        $this->assertMayTouch($action, $goal);

        if ($goal->status->isSettled()) {
            throw new \RuntimeException(sprintf(
                '"%s" is already %s. A settled goal is not edited; open a new one if the work has come back.',
                $goal->title,
                $goal->status->value,
            ));
        }

        $fields = [];

        foreach (['title', 'description', 'acceptance_condition'] as $key) {
            $value = $this->arg($action, $key);

            if (is_string($value) && trim($value) !== '' && trim($value) !== (string) $goal->{$key}) {
                $fields[$key] = trim($value);
            }
        }

        if (($status = $this->arg($action, 'status')) !== null) {
            if (! in_array($status, self::SETTABLE, true)) {
                throw new \RuntimeException(sprintf(
                    'Status "%s" is not set by an update. Use complete_goal or abandon_goal.',
                    $status,
                ));
            }

            if ($status !== $goal->status->value) {
                $fields['status'] = GoalStatus::from($status);
            }
        }

        if (($progress = $this->arg($action, 'progress')) !== null) {
            $progress = (int) $progress;

            if ($progress < 0 || $progress > 100) {
                throw new \RuntimeException('Progress is a whole percent between 0 and 100.');
            }

            // A parent's progress is derived from its children, and setting it
            // directly would put a number on the tree that the next child
            // update silently overwrites.
            if ($goal->children()->exists()) {
                throw new \RuntimeException('A parent goal reports the progress of its sub-goals. Update one of those instead.');
            }

            if ($progress !== (int) $goal->progress) {
                $fields['progress']                = $progress;
                // Left null on purpose: no person set this.
                $fields['progress_set_by_user_id'] = null;
                $fields['progress_set_at']         = now();
            }
        }

        $dueAt  = $this->dateArg($action, 'due_at');
        $reason = $this->arg($action, 'reason');
        $moved  = $dueAt !== null
            && $goal->due_at?->toDateString() !== $dueAt->format('Y-m-d');

        if ($fields === [] && ! $moved) {
            return ['goal_id' => $goal->id, 'changed' => false];
        }

        return DB::transaction(function () use ($circle, $agent, $action, $goal, $fields, $moved, $dueAt, $reason) {
            $before = $goal->only(array_keys($fields));

            if ($fields !== []) {
                $goal->update($fields);

                $this->audit->record(
                    AuditEventType::GoalUpdated, $circle, ActorType::Agent, $agent->id,
                    'goal', $goal->id, metadata: [
                        'changes'      => $this->diff($before, $fields),
                        'reason'       => $reason,
                        'agent_action' => $action->id,
                        'approved_by'  => $action->approved_by_user_id,
                    ],
                );
            }

            if ($moved) {
                $from = $goal->due_at;

                $change = GoalScheduleChange::create([
                    'goal_id'                      => $goal->id,
                    'circle_id'                    => $circle->id,
                    'from_due_at'                  => $from,
                    'to_due_at'                    => $dueAt,
                    'reason'                       => $reason,
                    'changed_by_user_id'           => null,
                    'changed_by_agent_instance_id' => $agent->id,
                ]);

                $goal->update(['due_at' => $dueAt]);

                $this->audit->record(
                    AuditEventType::GoalRescheduled, $circle, ActorType::Agent, $agent->id,
                    'goal', $goal->id, metadata: [
                        'from'         => $from?->format(DATE_ATOM),
                        'to'           => $dueAt?->format(DATE_ATOM),
                        'reason'       => $reason,
                        'days_moved'   => $change->daysMoved(),
                        'agent_action' => $action->id,
                    ],
                );
            }

            return [
                'goal_id' => $goal->id,
                'title'   => $goal->fresh()->title,
                'changed' => array_keys($fields),
                'due_at'  => $moved ? $dueAt?->format('Y-m-d') : null,
            ];
        });
    }

    /** Before and after for each field, in the form the record keeps. */
    private function diff(array $before, array $after): array
    {
        $out = [];

        foreach ($after as $key => $value) {
            $from = $before[$key] ?? null;

            $out[$key] = [
                'from' => $from instanceof \BackedEnum ? $from->value : $from,
                'to'   => $value instanceof \BackedEnum ? $value->value
                    : ($value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value),
            ];
        }

        return $out;
    }
}
