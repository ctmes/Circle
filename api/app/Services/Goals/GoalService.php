<?php

namespace App\Services\Goals;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\GoalStatus;
use App\Models\Circle;
use App\Models\CircleParty;
use App\Models\Goal;
use App\Models\GoalScheduleChange;
use App\Models\User;
use App\Services\Audit\AuditChain;
use Illuminate\Support\Facades\DB;

/**
 * The goal tree (spec §20.2).
 *
 * Two rules this class exists to enforce, both of them about status meaning
 * something:
 *
 *  - Acceptance is not completion. An owner marking their own work done is a
 *    claim about it; only someone else accepting it settles the node.
 *  - A due date cannot move silently. Every movement writes a
 *    GoalScheduleChange with a reason, and where it affects another party it is
 *    recorded as needing that party's agreement.
 */
class GoalService
{
    /**
     * How deep the tree may go. See config/circle.php for why it moved from
     * two to four: subcontracting is genuinely several levels, and a cap that
     * refuses it pushes the structure into the titles instead.
     */
    public static function maxDepth(): int
    {
        return max(1, (int) config('circle.goals.max_depth', 4));
    }

    public function __construct(
        private readonly AuditChain $audit,
        private readonly \App\Services\Notifications\Notifier $notifier,
    ) {}

    public function create(
        Circle $circle,
        User $creator,
        string $title,
        ?string $description = null,
        ?Goal $parent = null,
        ?User $owner = null,
        ?CircleParty $responsibleParty = null,
        ?string $acceptanceCondition = null,
        ?\DateTimeInterface $startsAt = null,
        ?\DateTimeInterface $dueAt = null,
    ): Goal {
        if ($parent !== null) {
            abort_unless($parent->circle_id === $circle->id, 422, 'The parent goal belongs to another Circle.');
            abort_if(
                $this->depthOf($parent) + 1 >= self::maxDepth(),
                422,
                sprintf('Goals nest %d levels deep at most.', self::maxDepth()),
            );
        }

        return DB::transaction(function () use (
            $circle, $creator, $title, $description, $parent, $owner,
            $responsibleParty, $acceptanceCondition, $startsAt, $dueAt
        ) {
            $goal = Goal::create([
                'circle_id'            => $circle->id,
                'parent_goal_id'       => $parent?->id,
                'title'                => $title,
                'description'          => $description,
                'status'               => GoalStatus::Active,
                'owner_user_id'        => $owner?->id,
                'responsible_party_id' => $responsibleParty?->id,
                'acceptance_condition' => $acceptanceCondition,
                'starts_at'            => $startsAt,
                'due_at'               => $dueAt,
                'position'             => $this->nextPosition($circle, $parent),
                'created_by_type'      => 'user',
                'created_by_id'        => $creator->id,
            ]);

            $this->audit->record(
                AuditEventType::GoalCreated,
                $circle,
                ActorType::User,
                $creator->id,
                'goal',
                $goal->id,
                metadata: [
                    'title'             => $title,
                    'parent_goal_id'    => $parent?->id,
                    'responsible_party' => $responsibleParty?->label(),
                    'due_at'            => $dueAt?->format(DATE_ATOM),
                ],
            );

            return $goal;
        });
    }

    /**
     * Field updates other than the due date.
     *
     * The due date is deliberately not settable here — it goes through
     * reschedule(), which demands a reason.
     */
    public function update(Goal $goal, User $actor, array $attributes): Goal
    {
        $allowed = collect($attributes)
            ->only([
                'title', 'description', 'status', 'owner_user_id',
                'responsible_party_id', 'acceptance_condition', 'position',
                'starts_at',
            ])
            ->all();

        return DB::transaction(function () use ($goal, $actor, $allowed) {
            $before = $goal->only(array_keys($allowed));
            $goal->update($allowed);

            $this->audit->record(
                $goal->status === GoalStatus::Abandoned
                    ? AuditEventType::GoalAbandoned
                    : AuditEventType::GoalUpdated,
                $goal->circle,
                ActorType::User,
                $actor->id,
                'goal',
                $goal->id,
                metadata: ['before' => $before, 'after' => $allowed],
            );

            return $goal->fresh();
        });
    }

    /**
     * Reported progress.
     *
     * Only meaningful on a leaf: a parent's figure is derived from its children
     * (see Goal::effectiveProgress), so setting one on a parent would be a
     * number nobody reads.
     */
    public function setProgress(Goal $goal, User $actor, int $progress): Goal
    {
        abort_if($progress < 0 || $progress > 100, 422, 'Progress is a whole percent between 0 and 100.');
        abort_if($goal->children()->exists(), 422, 'A parent goal reports the progress of its sub-goals.');

        if ((int) $goal->progress === $progress) {
            return $goal;
        }

        return DB::transaction(function () use ($goal, $actor, $progress) {
            $from = (int) $goal->progress;

            $goal->update([
                'progress'                => $progress,
                'progress_set_by_user_id' => $actor->id,
                'progress_set_at'         => now(),
            ]);

            $this->audit->record(
                AuditEventType::GoalUpdated,
                $goal->circle,
                ActorType::User,
                $actor->id,
                'goal',
                $goal->id,
                metadata: ['progress_from' => $from, 'progress_to' => $progress],
            );

            return $goal->fresh();
        });
    }

    /**
     * Accept the work.
     *
     * Refused when the acceptor is the goal's own owner: self-certification is
     * the thing acceptance exists to prevent, and in cross-company work it is
     * the counterparty's signature that the record needs.
     */
    public function accept(Goal $goal, User $acceptor): Goal
    {
        abort_if($goal->isAccepted(), 422, 'This goal has already been accepted.');
        abort_if(
            $goal->owner_user_id !== null && $goal->owner_user_id === $acceptor->id,
            422,
            'Work is accepted by someone other than the person who owned it.',
        );

        return DB::transaction(function () use ($goal, $acceptor) {
            $goal->update([
                'status'              => GoalStatus::Met,
                'accepted_by_user_id' => $acceptor->id,
                'accepted_at'         => now(),
                'progress'            => 100,
            ]);

            $this->audit->record(
                AuditEventType::GoalAccepted,
                $goal->circle,
                ActorType::User,
                $acceptor->id,
                'goal',
                $goal->id,
                metadata: [
                    'acceptance_condition' => $goal->acceptance_condition,
                    'owner_user_id'        => $goal->owner_user_id,
                ],
            );

            return $goal->fresh();
        });
    }

    /**
     * Move a due date, on the record.
     *
     * `requiresParty` marks the move as needing a counterparty's assent. The
     * date still moves — blocking it would just push the conversation off the
     * platform — but the goal carries a visible unagreed change until they
     * respond.
     */
    public function reschedule(
        Goal $goal,
        User $actor,
        ?\DateTimeInterface $dueAt,
        ?string $reason = null,
        ?CircleParty $requiresParty = null,
    ): GoalScheduleChange {
        $change = DB::transaction(function () use ($goal, $actor, $dueAt, $reason, $requiresParty) {
            $from = $goal->due_at;

            $change = GoalScheduleChange::create([
                'goal_id'            => $goal->id,
                'circle_id'          => $goal->circle_id,
                'from_due_at'        => $from,
                'to_due_at'          => $dueAt,
                'reason'             => $reason,
                'changed_by_user_id' => $actor->id,
                'requires_party_id'  => $requiresParty?->id,
            ]);

            $goal->update(['due_at' => $dueAt]);

            $this->audit->record(
                AuditEventType::GoalRescheduled,
                $goal->circle,
                ActorType::User,
                $actor->id,
                'goal',
                $goal->id,
                metadata: [
                    'from'           => $from?->format(DATE_ATOM),
                    'to'             => $dueAt?->format(DATE_ATOM),
                    'reason'         => $reason,
                    'requires_party' => $requiresParty?->label(),
                    'days_moved'     => $change->daysMoved(),
                ],
            );

            return $change;
        });

        // The date has already moved — this records what happened rather than
        // gating it — so this message is the counterparty finding out on the
        // day instead of at the end of the job.
        $this->notifier->scheduleChangeProposed($change->setRelation('goal', $goal));

        return $change;
    }

    public function agreeReschedule(GoalScheduleChange $change, User $actor): GoalScheduleChange
    {
        abort_unless($change->isAwaitingAgreement(), 422, 'This change is not waiting on agreement.');

        return DB::transaction(function () use ($change, $actor) {
            $change->update(['agreed_by_user_id' => $actor->id, 'agreed_at' => now()]);

            $this->audit->record(
                AuditEventType::GoalRescheduleAgreed,
                $change->goal->circle,
                ActorType::User,
                $actor->id,
                'goal',
                $change->goal_id,
                metadata: ['schedule_change_id' => $change->id],
            );

            return $change->fresh();
        });
    }

    /**
     * How many ancestors a goal has. Zero for a root.
     *
     * The loop guard is the cap plus slack rather than the cap itself, so a row
     * that somehow sits deeper than the cap — an old tree after the cap was
     * lowered — is measured honestly instead of reported as being at the limit.
     */
    /**
     * Move a goal to a different parent.
     *
     * Separate from update() because it is the one field change that can make
     * the tree invalid rather than merely wrong. Two failures are checked and
     * both are silent corruption if they are not:
     *
     * A goal cannot be moved beneath its own descendant, which would detach the
     * whole subtree from the root and lose it from every view that walks down
     * from the top.
     *
     * The move must leave the deepest node in the subtree within the cap. It is
     * the subtree's height that matters, not the goal's own depth — moving a
     * two-level branch one level down pushes its leaves down with it.
     */
    public function reparent(Goal $goal, User $actor, ?Goal $parent): Goal
    {
        if ($parent !== null) {
            abort_unless($parent->circle_id === $goal->circle_id, 422, 'That parent is in another Circle.');
            abort_if($parent->id === $goal->id, 422, 'A goal cannot be its own parent.');

            $cursor = $parent;
            $steps  = 0;

            while ($cursor !== null && $steps <= self::maxDepth() + 2) {
                abort_if($cursor->id === $goal->id, 422, 'That would move a goal underneath its own sub-goal.');
                $cursor = $cursor->parent;
                $steps++;
            }

            abort_if(
                $this->depthOf($parent) + 1 + $this->heightOf($goal) >= self::maxDepth(),
                422,
                sprintf('That move would push sub-goals past %d levels.', self::maxDepth()),
            );
        }

        $from = $goal->parent_goal_id;

        if ($from === $parent?->id) {
            return $goal;
        }

        return DB::transaction(function () use ($goal, $actor, $parent, $from) {
            $goal->update([
                'parent_goal_id' => $parent?->id,
                'position'       => (int) Goal::where('circle_id', $goal->circle_id)
                    ->where('parent_goal_id', $parent?->id)
                    ->max('position') + 1,
            ]);

            $this->audit->record(
                AuditEventType::GoalUpdated,
                $goal->circle,
                ActorType::User,
                $actor->id,
                'goal',
                $goal->id,
                metadata: ['moved_from_parent' => $from, 'moved_to_parent' => $parent?->id],
            );

            return $goal->fresh();
        });
    }

    /** How many levels of sub-goal hang below this one. Zero for a leaf. */
    public function heightOf(Goal $goal): int
    {
        $children = $goal->children()->get();

        if ($children->isEmpty()) {
            return 0;
        }

        return 1 + $children->max(fn (Goal $c) => $this->heightOf($c));
    }

    public function depthOf(Goal $goal): int
    {
        $depth  = 0;
        $cursor = $goal;

        while ($cursor->parent_goal_id !== null && $depth <= self::maxDepth() + 2) {
            $cursor = $cursor->parent;
            $depth++;
        }

        return $depth;
    }

    private function nextPosition(Circle $circle, ?Goal $parent): int
    {
        return (int) Goal::where('circle_id', $circle->id)
            ->where('parent_goal_id', $parent?->id)
            ->max('position') + 1;
    }
}
