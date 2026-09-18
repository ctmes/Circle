<?php

namespace App\Services\Goals;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\DecisionStatus;
use App\Enums\GoalStatus;
use App\Enums\Permission;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\CircleParty;
use App\Models\Decision;
use App\Models\DecisionApproval;
use App\Models\Goal;
use App\Models\GoalBranch;
use App\Models\GoalChange;
use App\Models\User;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use Illuminate\Support\Facades\DB;

/**
 * Branches of the plan: propose, review, merge (spec §20.7).
 *
 * The rule that shapes everything here: a merge needs agreement from every
 * party whose work the branch touches. Not the convener's agreement, and not
 * the author's — the companies who will have to do the changed thing.
 *
 * This is the same principle `goal_schedule_changes` already encodes for a
 * single date, generalised to a set of edits. A date that can move without the
 * party it lands on agreeing carries no weight; so does a plan that can be
 * rewritten the same way. The client cannot quietly reschedule the
 * contractor's work by opening a branch, and the contractor cannot quietly
 * reassign the client's.
 *
 * Conflict detection is the other half. Every change records what main held
 * when it was written. If main has moved since, the change is reported as a
 * conflict and the merge refuses — because the alternative is that somebody
 * signed off on a diff that no longer describes what would happen.
 */
class GoalBranchService
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly AuditChain $audit,
        private readonly GoalService $goals,
    ) {}

    // ------------------------------------------------------------ authoring

    public function open(
        Circle $circle,
        User $author,
        string $name,
        ?string $intent = null,
    ): GoalBranch {
        $this->gate->authorise($author, Permission::GoalBranch, $circle);

        $membership = $this->membership($circle, $author);

        return DB::transaction(function () use ($circle, $author, $name, $intent, $membership) {
            $branch = GoalBranch::create([
                'circle_id'          => $circle->id,
                'name'               => $name,
                'intent'             => $intent,
                'circle_party_id'    => $membership->circle_party_id,
                'created_by_user_id' => $author->id,
                'status'             => 'draft',
            ]);

            $this->audit->record(
                AuditEventType::BranchOpened, $circle, ActorType::User, $author->id,
                'goal_branch', $branch->id, metadata: [
                    'name'   => $name,
                    'intent' => $intent,
                    'party'  => $membership->party?->label(),
                ],
            );

            return $branch;
        });
    }

    /**
     * Stage one edit.
     *
     * `base_json` is captured here rather than at merge time, which is the
     * whole point: it records what the author was looking at when they decided
     * this was the right change.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function stage(
        GoalBranch $branch,
        User $author,
        string $changeType,
        ?Goal $goal = null,
        array $attributes = [],
        ?string $reason = null,
        ?string $tempKey = null,
        ?string $parentTempKey = null,
        ?Goal $parentGoal = null,
    ): GoalChange {
        // The goal is passed as the subject so a contractor working under a
        // scoped engagement is confined to the work they were engaged for
        // (spec §21.2). Opening the branch could not be checked this way —
        // an empty branch names no goal — so this is where it happens.
        $this->gate->authorise($author, Permission::GoalBranch, $branch->circle, subject: $goal ?? $parentGoal);
        abort_unless($branch->isEditable(), 422, 'This branch has been merged or withdrawn.');

        abort_unless(
            in_array($changeType, ['add', 'update', 'remove'], true),
            422,
            'A change is an add, an update or a removal.',
        );

        if ($goal !== null) {
            abort_unless($goal->circle_id === $branch->circle_id, 422, 'That goal is in another Circle.');
        }

        // Only fields the tree actually holds, and never `progress`: reported
        // progress is a statement about work already done, not a proposal about
        // work to come. Letting a branch carry it would mean a party could
        // "agree" to somebody else's status.
        $attributes = array_intersect_key($attributes, array_flip(GoalChange::EDITABLE));

        if ($changeType === 'update') {
            abort_if($goal === null, 422, 'An update needs a goal.');
            abort_if($attributes === [], 422, 'That change would alter nothing.');

            // Checked when it is staged as well as when it is applied. An
            // author who finds out at merge time that their move was never
            // possible has taken three other parties through a review for
            // nothing.
            if (array_key_exists('parent_goal_id', $attributes)) {
                $this->assertMoveIsPossible(
                    $goal,
                    $attributes['parent_goal_id'] === null ? null : Goal::find($attributes['parent_goal_id']),
                );
            }

            abort_if(
                array_key_exists('due_at', $attributes) && ($reason === null || trim($reason) === ''),
                422,
                'Moving a date needs a reason, on a branch as much as anywhere else.',
            );
        }

        if ($changeType === 'add') {
            abort_if(($attributes['title'] ?? '') === '', 422, 'A new goal needs a title.');
        }

        if ($changeType === 'remove') {
            abort_if($goal === null, 422, 'A removal needs a goal.');
            abort_if(
                $goal->children()->exists(),
                422,
                'Remove the sub-goals first. Dropping a branch of the tree in one step hides what was dropped.',
            );
        }

        return DB::transaction(function () use (
            $branch, $author, $changeType, $goal, $attributes, $reason,
            $tempKey, $parentTempKey, $parentGoal
        ) {
            $change = GoalChange::create([
                'goal_branch_id'     => $branch->id,
                'change_type'        => $changeType,
                'goal_id'            => $goal?->id,
                'temp_key'           => $changeType === 'add' ? ($tempKey ?? (string) \Illuminate\Support\Str::uuid()) : null,
                'parent_temp_key'    => $parentTempKey,
                'parent_goal_id'     => $parentGoal?->id,
                'attributes_json'    => $attributes,
                'base_json'          => $goal === null ? null : $this->snapshot($goal, array_keys($attributes)),
                'reason'             => $reason,
                'position'           => (int) GoalChange::where('goal_branch_id', $branch->id)->max('position') + 1,
                'created_by_user_id' => $author->id,
            ]);

            // Re-opening the question. A branch somebody already signed cannot
            // grow a change afterwards and keep the signature: that would let
            // an author add a clause after the counterparty agreed.
            if ($branch->isOpen()) {
                $this->clearApprovals($branch, 'a change was added after review began');
            }

            return $change;
        });
    }

    public function unstage(GoalChange $change, User $actor): void
    {
        $branch = $change->branch;

        $this->gate->authorise($actor, Permission::GoalBranch, $branch->circle);
        abort_unless($branch->isEditable(), 422, 'This branch has been merged or withdrawn.');

        DB::transaction(function () use ($branch, $change) {
            $change->delete();

            if ($branch->isOpen()) {
                $this->clearApprovals($branch, 'a change was withdrawn after review began');
            }
        });
    }

    // ------------------------------------------------------------- review

    public function propose(GoalBranch $branch, User $actor): GoalBranch
    {
        $this->gate->authorise($actor, Permission::GoalBranch, $branch->circle);
        abort_unless($branch->isDraft(), 422, 'This branch is not a draft.');
        abort_if($branch->changes()->count() === 0, 422, 'An empty branch proposes nothing.');

        return DB::transaction(function () use ($branch, $actor) {
            $parties = $this->affectedParties($branch);

            $decision = Decision::create([
                'circle_id'          => $branch->circle_id,
                'title'              => sprintf('Merge "%s" into the plan', $branch->name),
                'description'        => $this->decisionBody($branch, $parties),
                'status'             => DecisionStatus::Pending,
                'created_by_user_id' => $actor->id,
                'subject_type'       => 'goal_branch',
                'subject_id'         => $branch->id,
            ]);

            $branch->forceFill([
                'status'      => 'open',
                'proposed_at' => now(),
                'decision_id' => $decision->id,
            ])->save();

            $this->audit->record(
                AuditEventType::BranchProposed, $branch->circle, ActorType::User, $actor->id,
                'goal_branch', $branch->id, metadata: [
                    'name'      => $branch->name,
                    'changes'   => $branch->changes()->count(),
                    'needs'     => $parties->map(fn (CircleParty $p) => $p->label())->all(),
                    'decision'  => $decision->id,
                ],
            );

            return $branch->fresh();
        });
    }

    /**
     * A party signs.
     *
     * Signing on behalf of a party you do not belong to is refused even for an
     * owner. In a Circle the client convened, the client's owner holds every
     * permission there is — and still cannot sign for the contractor, because
     * the signature is the only thing the contractor gets out of this.
     */
    public function approve(GoalBranch $branch, User $approver, ?string $comment = null): GoalBranch
    {
        $this->gate->authorise($approver, Permission::GoalMerge, $branch->circle);
        abort_unless($branch->isOpen(), 422, 'This branch is not open for review.');

        $membership = $this->membership($branch->circle, $approver);
        $party      = $membership->party;

        $affected = $this->affectedParties($branch);

        abort_if(
            $affected->isNotEmpty() && ($party === null || ! $affected->contains('id', $party->id)),
            403,
            'This branch does not touch your party\'s work, so your agreement is not what it needs.',
        );

        // Reported before signing rather than after merging. Someone agreeing
        // to a diff that cannot be applied has been asked the wrong question.
        $conflicts = $this->conflicts($branch);
        abort_if(
            $conflicts !== [],
            422,
            'The plan has moved under this branch. It needs rebasing before anyone signs it.',
        );

        return DB::transaction(function () use ($branch, $approver, $party, $comment, $affected) {
            DecisionApproval::updateOrCreate(
                [
                    'decision_id'   => $branch->decision_id,
                    'actor_user_id' => $approver->id,
                ],
                [
                    'circle_party_id' => $party?->id,
                    'outcome'         => 'approved',
                    'subject_type'    => 'goal_branch',
                    'subject_id'      => $branch->id,
                    'comment'         => $comment,
                    'occurred_at'     => now(),
                ],
            );

            $this->audit->record(
                AuditEventType::BranchApproved, $branch->circle, ActorType::User, $approver->id,
                'goal_branch', $branch->id, metadata: [
                    'party'   => $party?->label(),
                    'comment' => $comment,
                ],
            );

            // Merges the moment the last signature lands. Holding it for
            // somebody to press a further button would mean an agreed plan and
            // the real plan disagree for as long as that button goes unpressed.
            if ($this->outstandingParties($branch, $affected)->isEmpty()) {
                return $this->merge($branch->fresh(), $approver);
            }

            return $branch->fresh();
        });
    }

    /**
     * A party refuses.
     *
     * The branch stays open. A refusal is an answer to a proposal, not the end
     * of one — the author narrows it and asks again, which is what a
     * negotiation is. Closing it here would teach people not to refuse.
     */
    public function refuse(GoalBranch $branch, User $actor, ?string $reason = null): GoalBranch
    {
        $this->gate->authorise($actor, Permission::GoalMerge, $branch->circle);
        abort_unless($branch->isOpen(), 422, 'This branch is not open for review.');

        $membership = $this->membership($branch->circle, $actor);
        $party      = $membership->party;

        return DB::transaction(function () use ($branch, $actor, $party, $reason) {
            DecisionApproval::updateOrCreate(
                [
                    'decision_id'   => $branch->decision_id,
                    'actor_user_id' => $actor->id,
                ],
                [
                    'circle_party_id' => $party?->id,
                    'outcome'         => 'rejected',
                    'subject_type'    => 'goal_branch',
                    'subject_id'      => $branch->id,
                    'comment'         => $reason,
                    'occurred_at'     => now(),
                ],
            );

            $this->audit->record(
                AuditEventType::BranchRefused, $branch->circle, ActorType::User, $actor->id,
                'goal_branch', $branch->id, metadata: [
                    'party'  => $party?->label(),
                    'reason' => $reason,
                ],
            );

            return $branch->fresh();
        });
    }

    public function withdraw(GoalBranch $branch, User $actor, ?string $reason = null): GoalBranch
    {
        $this->gate->authorise($actor, Permission::GoalBranch, $branch->circle);
        abort_if($branch->isMerged(), 422, 'A merged branch cannot be withdrawn.');

        abort_unless(
            $branch->created_by_user_id === $actor->id
                || $this->gate->allows($actor, Permission::CircleManageMembers, $branch->circle),
            403,
            'Only the author or a Circle owner can withdraw a branch.',
        );

        return DB::transaction(function () use ($branch, $actor, $reason) {
            $branch->forceFill(['status' => 'withdrawn', 'withdrawn_at' => now()])->save();

            if ($branch->decision !== null) {
                $branch->decision->forceFill([
                    'status'             => DecisionStatus::Rejected,
                    'resolved_at'        => now(),
                    'resolution_comment' => $reason ?? 'Branch withdrawn by its author.',
                ])->save();
            }

            $this->audit->record(
                AuditEventType::BranchWithdrawn, $branch->circle, ActorType::User, $actor->id,
                'goal_branch', $branch->id, metadata: ['reason' => $reason],
            );

            return $branch->fresh();
        });
    }

    // -------------------------------------------------------------- merging

    /**
     * Apply the change-set.
     *
     * Adds run before updates so a change can target something the same branch
     * created, and removals run last so a goal being removed can be edited on
     * the way out without the update failing on a missing row.
     */
    public function merge(GoalBranch $branch, User $actor): GoalBranch
    {
        abort_unless($branch->isOpen(), 422, 'This branch is not open for review.');

        $conflicts = $this->conflicts($branch);
        abort_if($conflicts !== [], 422, 'This branch conflicts with the current plan and cannot be merged.');

        $outstanding = $this->outstandingParties($branch, $this->affectedParties($branch));
        abort_if(
            $outstanding->isNotEmpty(),
            422,
            'Still waiting on ' . $outstanding->map(fn (CircleParty $p) => $p->label())->join(', ') . '.',
        );

        return DB::transaction(function () use ($branch, $actor) {
            $created = [];

            foreach ($branch->changes as $change) {
                match ($change->change_type) {
                    'add'    => $created[$change->temp_key] = $this->applyAdd($branch, $change, $created, $actor),
                    'update' => $this->applyUpdate($branch, $change, $actor),
                    'remove' => null,
                    default  => null,
                };
            }

            foreach ($branch->changes->where('change_type', 'remove') as $change) {
                $this->applyRemove($branch, $change, $actor);
            }

            $branch->forceFill([
                'status'            => 'merged',
                'merged_by_user_id' => $actor->id,
                'merged_at'         => now(),
            ])->save();

            if ($branch->decision !== null) {
                $branch->decision->forceFill([
                    'status'      => DecisionStatus::Approved,
                    'resolved_at' => now(),
                ])->save();
            }

            $this->audit->record(
                AuditEventType::BranchMerged, $branch->circle, ActorType::User, $actor->id,
                'goal_branch', $branch->id, metadata: [
                    'name'    => $branch->name,
                    'changes' => $branch->changes->count(),
                    'author'  => $branch->author?->name,
                    'signed'  => $this->approvals($branch)
                        ->map(fn (DecisionApproval $a) => $a->party?->label() ?? $a->actor?->name)
                        ->filter()->values()->all(),
                ],
            );

            return $branch->fresh();
        });
    }

    private function applyAdd(GoalBranch $branch, GoalChange $change, array $created, User $actor): Goal
    {
        $attributes = $change->attributes_json ?? [];

        // A parent created earlier in this same branch, or one already on main.
        $parent = $change->parent_temp_key !== null
            ? ($created[$change->parent_temp_key] ?? null)
            : ($change->parent_goal_id !== null ? Goal::find($change->parent_goal_id) : null);

        return $this->goals->create(
            circle: $branch->circle,
            creator: $actor,
            title: $attributes['title'],
            description: $attributes['description'] ?? null,
            parent: $parent,
            owner: isset($attributes['owner_user_id']) ? User::find($attributes['owner_user_id']) : null,
            responsibleParty: isset($attributes['responsible_party_id'])
                ? CircleParty::find($attributes['responsible_party_id'])
                : null,
            acceptanceCondition: $attributes['acceptance_condition'] ?? null,
            startsAt: isset($attributes['starts_at']) ? new \DateTimeImmutable($attributes['starts_at']) : null,
            dueAt: isset($attributes['due_at']) ? new \DateTimeImmutable($attributes['due_at']) : null,
        );
    }

    /**
     * A date moved by a branch still goes through reschedule(), so it lands in
     * `goal_schedule_changes` with its reason. Otherwise a branch would be the
     * one route by which a deadline could move without a record of why — and
     * that record is the single most-argued-over thing in the product.
     */
    private function applyUpdate(GoalBranch $branch, GoalChange $change, User $actor): void
    {
        $goal = $change->goal;

        if ($goal === null) {
            return;
        }

        $attributes = $change->attributes_json ?? [];

        if (array_key_exists('due_at', $attributes)) {
            $this->goals->reschedule(
                goal: $goal,
                actor: $actor,
                dueAt: $attributes['due_at'] === null ? null : new \DateTimeImmutable($attributes['due_at']),
                reason: sprintf('%s (agreed via branch "%s")', $change->reason, $branch->name),
                // Already agreed: every affected party signed the branch this
                // move arrived in. Asking again would be asking twice.
                requiresParty: null,
            );

            unset($attributes['due_at']);
        }

        // Re-parenting goes through its own path — update() cannot do it, and
        // routing it there would drop a change somebody had already approved.
        if (array_key_exists('parent_goal_id', $attributes)) {
            $this->goals->reparent(
                $goal->fresh(),
                $actor,
                $attributes['parent_goal_id'] === null ? null : Goal::find($attributes['parent_goal_id']),
            );

            unset($attributes['parent_goal_id']);
        }

        if ($attributes !== []) {
            $this->goals->update($goal->fresh(), $actor, $attributes);
        }
    }

    private function applyRemove(GoalBranch $branch, GoalChange $change, User $actor): void
    {
        $goal = $change->goal;

        if ($goal === null) {
            return;
        }

        // Abandoned, not deleted. Everything that cited or commented on this
        // goal still resolves, and the packet can still say the work existed
        // and was dropped — which is usually the interesting part.
        $this->goals->update($goal, $actor, ['status' => GoalStatus::Abandoned->value]);
    }

    // ------------------------------------------------------------ inspection

    /**
     * Where the branch and the current plan disagree.
     *
     * Compares each change's recorded base against what the goal holds now. A
     * field the branch does not touch is not compared — two parties editing
     * different fields of the same goal is collaboration, not a conflict, and
     * treating it as one is how a tool teaches people to stop using branches.
     *
     * @return list<array<string, mixed>>
     */
    public function conflicts(GoalBranch $branch): array
    {
        $conflicts = [];

        foreach ($branch->changes as $change) {
            if ($change->isAdd()) {
                if ($change->parent_goal_id !== null && Goal::find($change->parent_goal_id) === null) {
                    $conflicts[] = [
                        'change_id' => $change->id,
                        'kind'      => 'parent_gone',
                        'message'   => 'The goal this was to be added under no longer exists.',
                    ];
                }

                continue;
            }

            $goal = $change->goal;

            if ($goal === null) {
                $conflicts[] = [
                    'change_id' => $change->id,
                    'kind'      => 'goal_gone',
                    'message'   => 'The goal this change targets has been deleted.',
                ];

                continue;
            }

            if ($change->isRemove()) {
                continue;
            }

            $now  = $this->snapshot($goal, array_keys($change->base_json ?? []));
            $base = $change->base_json ?? [];

            foreach ($base as $field => $was) {
                if (($now[$field] ?? null) !== $was) {
                    $conflicts[] = [
                        'change_id' => $change->id,
                        'goal_id'   => $goal->id,
                        'kind'      => 'moved_underneath',
                        'field'     => $field,
                        'message'   => sprintf(
                            '"%s" changed on the plan since this was drafted: %s is now %s, not %s.',
                            $goal->title,
                            $field,
                            $this->readable($now[$field] ?? null),
                            $this->readable($was),
                        ),
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * Re-point a stale branch at the plan as it stands now.
     *
     * Only the recorded base moves; what the branch *wants* is untouched. That
     * is the honest version of a rebase here — the author is saying "I have
     * seen what changed and I still want this", and because every signature was
     * cleared they have to say it to the other parties again.
     */
    public function rebase(GoalBranch $branch, User $actor): GoalBranch
    {
        $this->gate->authorise($actor, Permission::GoalBranch, $branch->circle);
        abort_unless($branch->isEditable(), 422, 'This branch has been merged or withdrawn.');

        return DB::transaction(function () use ($branch, $actor) {
            foreach ($branch->changes as $change) {
                if ($change->goal === null || $change->isAdd()) {
                    continue;
                }

                $change->forceFill([
                    'base_json' => $this->snapshot($change->goal, array_keys($change->attributes_json ?? [])),
                ])->save();
            }

            if ($branch->isOpen()) {
                $this->clearApprovals($branch, 'the branch was rebased onto a changed plan');
            }

            return $branch->fresh();
        });
    }

    /**
     * The companies whose agreement this needs.
     *
     * A goal's responsible party, plus the party of the goal a new node lands
     * under — adding work beneath somebody's deliverable changes what they are
     * answerable for, even though their own row is untouched.
     *
     * @return \Illuminate\Support\Collection<int, CircleParty>
     */
    public function affectedParties(GoalBranch $branch): \Illuminate\Support\Collection
    {
        $ids = collect();

        foreach ($branch->changes as $change) {
            if ($change->goal !== null) {
                $ids->push($change->goal->responsible_party_id);
            }

            $ids->push(($change->attributes_json ?? [])['responsible_party_id'] ?? null);

            if ($change->parent_goal_id !== null) {
                $ids->push(Goal::find($change->parent_goal_id)?->responsible_party_id);
            }
        }

        $ids = $ids->filter()->unique()->values();

        // A branch touching only unassigned work still needs somebody to agree.
        // The convener answers for the Circle as a whole, and an unowned change
        // that nobody has to sign is a change with no agreement behind it.
        if ($ids->isEmpty()) {
            $convener = CircleParty::where('circle_id', $branch->circle_id)
                ->where('is_convener', true)
                ->first();

            return collect(array_filter([$convener]));
        }

        return CircleParty::whereIn('id', $ids)->get();
    }

    /** @return \Illuminate\Support\Collection<int, CircleParty> */
    public function outstandingParties(GoalBranch $branch, ?\Illuminate\Support\Collection $affected = null): \Illuminate\Support\Collection
    {
        $affected ??= $this->affectedParties($branch);

        $signed = $this->approvals($branch)
            ->where('outcome', 'approved')
            ->pluck('circle_party_id')
            ->filter()
            ->unique();

        return $affected->reject(fn (CircleParty $p) => $signed->contains($p->id))->values();
    }

    /** @return \Illuminate\Support\Collection<int, DecisionApproval> */
    public function approvals(GoalBranch $branch): \Illuminate\Support\Collection
    {
        if ($branch->decision_id === null) {
            return collect();
        }

        return DecisionApproval::where('decision_id', $branch->decision_id)
            ->with(['actor', 'party'])
            ->get();
    }

    // --------------------------------------------------------------- helpers

    /**
     * The goal's current values for the given fields, normalised to strings so
     * a comparison is not fooled by a Carbon instance against an ISO string.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function snapshot(Goal $goal, array $fields): array
    {
        $out = [];

        foreach ($fields as $field) {
            $value = $goal->{$field};

            $out[$field] = match (true) {
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof \BackedEnum        => $value->value,
                default                              => $value,
            };
        }

        return $out;
    }

    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null => 'not set',
            is_bool($value) => $value ? 'yes' : 'no',
            default         => (string) $value,
        };
    }

    /**
     * Wipe the signatures.
     *
     * Called whenever the thing being agreed to changes. A signature belongs to
     * a specific diff — carrying it across an edit would let an author add a
     * clause after the counterparty had already said yes, which is the one
     * failure this whole mechanism exists to make impossible.
     */
    private function clearApprovals(GoalBranch $branch, string $why): void
    {
        if ($branch->decision_id === null) {
            return;
        }

        $cleared = DecisionApproval::where('decision_id', $branch->decision_id)->delete();

        if ($cleared > 0) {
            $this->audit->record(
                AuditEventType::BranchRefused, $branch->circle, ActorType::System, null,
                'goal_branch', $branch->id, metadata: [
                    'cleared_approvals' => $cleared,
                    'reason'            => $why,
                ],
            );
        }
    }

    private function decisionBody(GoalBranch $branch, \Illuminate\Support\Collection $parties): string
    {
        $lines = [
            $branch->intent ?? 'A proposed revision of the plan.',
            '',
            sprintf('Proposed by %s (%s).', $branch->author?->name ?? 'unknown', $branch->party?->label() ?? 'no party'),
            sprintf('Needs agreement from: %s.', $parties->map(fn (CircleParty $p) => $p->label())->join(', ')),
            '',
            'Changes:',
        ];

        foreach ($branch->changes as $change) {
            $lines[] = '  - ' . $this->describe($change);
        }

        return implode("\n", $lines);
    }

    /** A one-line reading of a change, for the packet and the audit log. */
    public function describe(GoalChange $change): string
    {
        $attributes = $change->attributes_json ?? [];

        return match ($change->change_type) {
            'add' => sprintf('Add "%s"', $attributes['title'] ?? 'a goal'),
            'remove' => sprintf('Drop "%s"', $change->goal?->title ?? 'a goal'),
            default => sprintf(
                'Change %s on "%s"',
                implode(', ', array_keys($attributes)),
                $change->goal?->title ?? 'a goal',
            ),
        };
    }

    /**
     * A dry run of the move, so an impossible one is refused at drafting time.
     *
     * Delegates to the same service the merge uses rather than re-deriving the
     * rules, because two copies of a depth check drift and the copy that drifts
     * is always the one nobody is looking at.
     */
    private function assertMoveIsPossible(Goal $goal, ?Goal $parent): void
    {
        if ($parent === null) {
            return;
        }

        abort_unless($parent->circle_id === $goal->circle_id, 422, 'That parent is in another Circle.');
        abort_if($parent->id === $goal->id, 422, 'A goal cannot be its own parent.');

        $cursor = $parent;
        $steps  = 0;

        while ($cursor !== null && $steps <= GoalService::maxDepth() + 2) {
            abort_if($cursor->id === $goal->id, 422, 'That would move a goal underneath its own sub-goal.');
            $cursor = $cursor->parent;
            $steps++;
        }
    }

    private function membership(Circle $circle, User $user): CircleMembership
    {
        $membership = CircleMembership::where('circle_id', $circle->id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->first();

        abort_if($membership === null, 403, 'You are not a member of this Circle.');

        return $membership;
    }
}
