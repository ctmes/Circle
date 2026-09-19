<?php

namespace App\Services\Circles;

use App\Enums\AgentActionStatus;
use App\Enums\AuditEventType;
use App\Enums\DecisionStatus;
use App\Enums\GoalStatus;
use App\Enums\Permission;
use App\Models\AgentAction;
use App\Models\AuditEvent;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\Commitment;
use App\Models\Decision;
use App\Models\Goal;
use App\Models\GoalBranch;
use App\Models\GoalScheduleChange;
use App\Models\User;
use App\Services\Agent\AgentActionService;
use App\Services\Authorisation\AccessGate;
use App\Services\Goals\GoalBranchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the Circle list says about each Circle beyond its name.
 *
 * Two kinds of fact, kept apart because they answer different questions. What
 * is waiting on *you* is the reason to open a Circle today. How the mission
 * stands — jobs done, what is late, what is next, who is in it, when anything
 * last happened — is the reason to trust the list without opening it.
 *
 * Built for a list, so each fact is one grouped query across every Circle at
 * once rather than one per card. The gate is asked only where something is
 * actually pending, and it is asked rather than re-derived: a count that
 * includes things the gate would refuse is a count of chores you cannot do.
 */
class CircleDigest
{
    /** Events that record somebody looking, not anything happening. */
    private const NOT_ACTIVITY = [
        AuditEventType::AccessDenied,
        AuditEventType::ResourceViewed,
        AuditEventType::ResourceDownloaded,
    ];

    public function __construct(
        private readonly AccessGate $gate,
        private readonly AgentActionService $actions,
        private readonly GoalBranchService $branches,
    ) {}

    /**
     * @param  Collection<int, Circle>  $circles
     * @return array<string, array<string, mixed>>  keyed by Circle id
     */
    public function for(User $user, Collection $circles): array
    {
        if ($circles->isEmpty()) {
            return [];
        }

        $ids = $circles->pluck('id')->all();

        // Only an open Circle can be late for anything or waiting on anyone.
        // A closed one is a record, and a record has no to-do list.
        $open = $circles
            ->filter(fn (Circle $c) => ! $c->isClosed() && ! $c->isDeleted())
            ->keyBy('id');

        $members = CircleMembership::whereIn('circle_id', $ids)
            ->whereNull('revoked_at')
            ->where('invite_status', 'active')
            ->selectRaw('circle_id, count(*) as n')
            ->groupBy('circle_id')
            ->pluck('n', 'circle_id');

        $activity = AuditEvent::whereIn('circle_id', $ids)
            ->whereNotIn('event_type', array_map(fn (AuditEventType $t) => $t->value, self::NOT_ACTIVITY))
            ->selectRaw('circle_id, max(occurred_at) as at')
            ->groupBy('circle_id')
            ->pluck('at', 'circle_id');

        $goals = Goal::whereIn('circle_id', $ids)
            ->get(['id', 'circle_id', 'title', 'status', 'due_at', 'owner_user_id'])
            ->groupBy('circle_id');

        $commitments = Commitment::whereIn('circle_id', $open->keys()->all())
            ->whereNotIn('status', ['done', 'cancelled'])
            ->get(['id', 'circle_id', 'title', 'status', 'due_at', 'owner_user_id'])
            ->groupBy('circle_id');

        $waiting = $this->waitingOn($user, $open);

        $digest = [];

        foreach ($circles as $circle) {
            $jobs = $goals->get($circle->id, collect())
                ->reject(fn (Goal $g) => $g->status === GoalStatus::Abandoned);

            // Work and promises together: to the reader both are "something
            // that was meant to be done by a date".
            $due = $open->has($circle->id)
                ? $jobs->filter(fn (Goal $g) => $g->status->isOpen())
                    ->concat($commitments->get($circle->id, collect()))
                : collect();

            $late = $due->filter(fn (Goal|Commitment $item) => $item->isOverdue());

            $next = $due
                ->filter(fn (Goal|Commitment $item) => $item->due_at !== null && $item->due_at->isFuture())
                ->sortBy('due_at')
                ->first();

            $at = $activity[$circle->id] ?? null;

            $digest[$circle->id] = [
                'jobs'             => [
                    'total' => $jobs->count(),
                    'done'  => $jobs->where('status', GoalStatus::Met)->count(),
                ],
                'overdue'          => $late->count(),
                'next_due'         => $next === null ? null : [
                    'title'  => $next->title,
                    'due_at' => $next->due_at->toISOString(),
                ],
                'members'          => (int) ($members[$circle->id] ?? 0),
                'last_activity_at' => $at === null ? null : Carbon::parse($at)->toISOString(),
                'waiting'          => array_merge(
                    $this->nothingWaiting(),
                    $waiting[$circle->id] ?? [],
                    ['yours_overdue' => $late->where('owner_user_id', $user->id)->count()],
                ),
            ];
        }

        return $digest;
    }

    /**
     * Everything that needs this person in particular, per open Circle.
     *
     * Each kind mirrors the check its own endpoint makes, so nothing is counted
     * that the reader would open and then be refused.
     *
     * @param  Collection<string, Circle>  $open
     * @return array<string, array<string, int>>
     */
    private function waitingOn(User $user, Collection $open): array
    {
        if ($open->isEmpty()) {
            return [];
        }

        $ids = $open->keys()->all();
        $counts = [];
        $bump = function (string $circleId, string $kind, int $by = 1) use (&$counts): void {
            $counts[$circleId][$kind] = ($counts[$circleId][$kind] ?? 0) + $by;
        };

        $memberships = CircleMembership::whereIn('circle_id', $ids)
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('circle_id');

        // Addressed to you by name. Reading a mention needs nothing more than
        // being able to read the Circle, which being listed already says.
        DB::table('comment_mentions')
            ->join('comments', 'comments.id', '=', 'comment_mentions.comment_id')
            ->where('comment_mentions.mentioned_user_id', $user->id)
            ->whereNull('comment_mentions.read_at')
            ->whereIn('comments.circle_id', $ids)
            ->selectRaw('comments.circle_id as circle_id, count(*) as n')
            ->groupBy('comments.circle_id')
            ->pluck('n', 'circle_id')
            ->each(fn ($n, $circleId) => $bump($circleId, 'mentions', (int) $n));

        // Decisions that name you as the approver. Nobody else can resolve them.
        Decision::whereIn('circle_id', $ids)
            ->where('status', DecisionStatus::Pending->value)
            ->where('approver_user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->selectRaw('circle_id, count(*) as n')
            ->groupBy('circle_id')
            ->pluck('n', 'circle_id')
            ->each(function ($n, $circleId) use ($user, $open, $bump) {
                if ($this->gate->allows($user, Permission::DecisionApprove, $open[$circleId])) {
                    $bump($circleId, 'decisions', (int) $n);
                }
            });

        // What an agent proposed and a person has to agree to.
        AgentAction::whereIn('circle_id', $ids)
            ->where('status', AgentActionStatus::AwaitingApproval->value)
            ->with('tool')
            ->get()
            ->reject(fn (AgentAction $a) => $a->isExpired())
            ->each(function (AgentAction $action) use ($user, $open, $memberships, $bump) {
                $membership = $memberships[$action->circle_id] ?? null;

                if ($membership !== null
                    && $this->gate->allows($user, Permission::AgentApprove, $open[$action->circle_id])
                    && $this->actions->approvalRefusal($action, $membership) === null) {
                    $bump($action->circle_id, 'agent_actions');
                }
            });

        // A due date that moved and needs your company's agreement. The same
        // people the notifier tells: members whose party the move requires.
        GoalScheduleChange::whereIn('circle_id', $ids)
            ->whereNotNull('requires_party_id')
            ->whereNull('agreed_at')
            ->with('goal')
            ->get()
            ->each(function (GoalScheduleChange $change) use ($user, $open, $memberships, $bump) {
                $membership = $memberships[$change->circle_id] ?? null;

                if ($membership !== null
                    && $change->goal !== null
                    && $membership->effectivePartyId() === $change->requires_party_id
                    && $this->gate->allows($user, Permission::GoalUpdate, $open[$change->circle_id], subject: $change->goal)) {
                    $bump($change->circle_id, 'date_changes');
                }
            });

        // Work its owner says is finished, waiting for somebody else to accept.
        // Never your own: the owner of the work cannot accept it.
        Goal::whereIn('circle_id', $ids)
            ->where('status', GoalStatus::InReview->value)
            ->whereNull('accepted_at')
            ->where(fn ($q) => $q->whereNull('owner_user_id')->orWhere('owner_user_id', '!=', $user->id))
            ->get()
            ->each(function (Goal $goal) use ($user, $open, $bump) {
                if ($this->gate->allows($user, Permission::GoalAccept, $open[$goal->circle_id], subject: $goal)) {
                    $bump($goal->circle_id, 'to_accept');
                }
            });

        // A proposed revision of the plan that touches your company's work and
        // has not got its signature. Signing uses the member's own party row,
        // so this does too. A branch the plan has moved under cannot be signed
        // until it is rebased — that one is waiting on its author, not on you.
        GoalBranch::whereIn('circle_id', $ids)
            ->where('status', 'open')
            ->with('changes.goal')
            ->get()
            ->each(function (GoalBranch $branch) use ($user, $open, $memberships, $bump) {
                $party = $memberships->get($branch->circle_id)?->circle_party_id;

                if ($party !== null
                    && $this->branches->outstandingParties($branch)->contains('id', $party)
                    && $this->gate->allows($user, Permission::GoalMerge, $open[$branch->circle_id])
                    && $this->branches->conflicts($branch) === []) {
                    $bump($branch->circle_id, 'revisions');
                }
            });

        return $counts;
    }

    /** @return array<string, int> */
    private function nothingWaiting(): array
    {
        return [
            'decisions'     => 0,
            'mentions'      => 0,
            'agent_actions' => 0,
            'date_changes'  => 0,
            'to_accept'     => 0,
            'revisions'     => 0,
            'yours_overdue' => 0,
        ];
    }
}
