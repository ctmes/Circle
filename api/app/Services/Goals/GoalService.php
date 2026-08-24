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
    /** Two levels is the useful case; deeper becomes a WBS nobody reads. */
    public const MAX_DEPTH = 2;

    public function __construct(private readonly AuditChain $audit) {}

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
            abort_if($this->depthOf($parent) + 1 >= self::MAX_DEPTH + 1, 422, 'Goals nest two levels deep at most.');
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
        return DB::transaction(function () use ($goal, $actor, $dueAt, $reason, $requiresParty) {
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

    private function depthOf(Goal $goal): int
    {
        $depth  = 0;
        $cursor = $goal;

        while ($cursor->parent_goal_id !== null && $depth <= self::MAX_DEPTH + 1) {
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
