<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Models\Circle;
use App\Models\CircleParty;
use App\Models\Goal;
use App\Models\GoalBranch;
use App\Models\GoalChange;
use App\Models\DecisionApproval;
use App\Services\Authorisation\AccessGate;
use App\Services\Goals\GoalBranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Branches of the plan (spec §20.7).
 *
 * Every response carries the branch's conflicts and its outstanding signatures,
 * because both are things a reviewer has to know *before* deciding and neither
 * is derivable from the change list alone.
 */
class BranchController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly GoalBranchService $branches,
    ) {}

    public function index(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $branches = GoalBranch::where('circle_id', $circle->id)
            ->with(['author', 'party.organisation', 'changes.goal', 'changes.author', 'mergedBy'])
            // `?goal=` narrows to the branches that would touch one node, for
            // the job screen: somebody reading a package needs to know a
            // revision of it is in flight, and has no way to find that out
            // from a list of branch names.
            ->when($request->query('goal'), fn ($q, $v) => $q->whereHas(
                'changes',
                fn ($c) => $c->where('goal_id', $v)->orWhere('parent_goal_id', $v),
            ))
            ->orderByRaw("case status when 'open' then 0 when 'draft' then 1 else 2 end")
            ->orderByDesc('created_at')
            ->get();

        // Asked about one node, answer about that node: the whole change list
        // of a forty-change branch is not what a job screen is for, and making
        // the reader find the one row that concerns them is how a diff gets
        // approved unread.
        $goalId = $request->query('goal');

        return response()->json([
            'data' => $branches->map(fn (GoalBranch $b) => $goalId === null
                ? $this->present($b)
                : $this->present($b, withChanges: true, onlyGoal: $goalId))->all(),
        ]);
    }

    public function store(Request $request, Circle $circle): JsonResponse
    {
        $data = $request->validate([
            'name'   => ['required', 'string', 'max:120'],
            'intent' => ['nullable', 'string', 'max:2000'],
        ]);

        $branch = $this->branches->open(
            $circle,
            $request->user(),
            $data['name'],
            $data['intent'] ?? null,
        );

        return response()->json(['data' => $this->present($branch->fresh(['author', 'party', 'changes']))], 201);
    }

    public function show(Request $request, GoalBranch $branch): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $branch->circle);

        return response()->json([
            'data' => $this->present(
                $branch->load(['author', 'party.organisation', 'changes.goal', 'changes.author', 'mergedBy']),
                withChanges: true,
            ),
        ]);
    }

    /** Stage one edit onto the branch. */
    public function stage(Request $request, GoalBranch $branch): JsonResponse
    {
        $data = $request->validate([
            'change_type'     => ['required', 'string', 'in:add,update,remove'],
            'goal_id'         => ['nullable', 'string', 'exists:goals,id'],
            'parent_goal_id'  => ['nullable', 'string', 'exists:goals,id'],
            'parent_temp_key' => ['nullable', 'string', 'max:64'],
            'temp_key'        => ['nullable', 'string', 'max:64'],
            'reason'          => ['nullable', 'string', 'max:1000'],
            'attributes'      => ['nullable', 'array'],
        ]);

        $change = $this->branches->stage(
            branch: $branch,
            author: $request->user(),
            changeType: $data['change_type'],
            goal: isset($data['goal_id']) ? Goal::find($data['goal_id']) : null,
            attributes: $data['attributes'] ?? [],
            reason: $data['reason'] ?? null,
            tempKey: $data['temp_key'] ?? null,
            parentTempKey: $data['parent_temp_key'] ?? null,
            parentGoal: isset($data['parent_goal_id']) ? Goal::find($data['parent_goal_id']) : null,
        );

        return response()->json(['data' => $this->presentChange($change->load('goal', 'author'))], 201);
    }

    public function unstage(Request $request, GoalChange $change): JsonResponse
    {
        $this->branches->unstage($change, $request->user());

        return response()->json(null, 204);
    }

    public function propose(Request $request, GoalBranch $branch): JsonResponse
    {
        return response()->json([
            'data' => $this->present(
                $this->branches->propose($branch, $request->user())
                    ->load(['author', 'party.organisation', 'changes.goal']),
                withChanges: true,
            ),
        ]);
    }

    public function approve(Request $request, GoalBranch $branch): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        return response()->json([
            'data' => $this->present(
                $this->branches->approve($branch, $request->user(), $data['comment'] ?? null)
                    ->load(['author', 'party.organisation', 'changes.goal']),
                withChanges: true,
            ),
        ]);
    }

    public function refuse(Request $request, GoalBranch $branch): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return response()->json([
            'data' => $this->present(
                $this->branches->refuse($branch, $request->user(), $data['reason'] ?? null)
                    ->load(['author', 'party.organisation', 'changes.goal']),
                withChanges: true,
            ),
        ]);
    }

    /**
     * Merge explicitly.
     *
     * Rarely needed — the last signature merges it — but a branch whose
     * affected parties all signed before a conflict was cleared, or one signed
     * while the worker was down, needs a way through that is not "add a change
     * and start again".
     */
    public function merge(Request $request, GoalBranch $branch): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::GoalMerge, $branch->circle);

        return response()->json([
            'data' => $this->present(
                $this->branches->merge($branch, $request->user())
                    ->load(['author', 'party.organisation', 'changes.goal']),
                withChanges: true,
            ),
        ]);
    }

    public function rebase(Request $request, GoalBranch $branch): JsonResponse
    {
        return response()->json([
            'data' => $this->present(
                $this->branches->rebase($branch, $request->user())
                    ->load(['author', 'party.organisation', 'changes.goal']),
                withChanges: true,
            ),
        ]);
    }

    public function withdraw(Request $request, GoalBranch $branch): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return response()->json([
            'data' => $this->present(
                $this->branches->withdraw($branch, $request->user(), $data['reason'] ?? null)
                    ->load(['author', 'party.organisation', 'changes.goal']),
            ),
        ]);
    }

    // ------------------------------------------------------------ presenters

    private function present(GoalBranch $branch, bool $withChanges = false, ?string $onlyGoal = null): array
    {
        $affected    = $this->branches->affectedParties($branch);
        $outstanding = $this->branches->outstandingParties($branch, $affected);
        $approvals   = $this->branches->approvals($branch);
        $conflicts   = $branch->isMerged() ? [] : $this->branches->conflicts($branch);

        $payload = [
            'id'          => $branch->id,
            'circle_id'   => $branch->circle_id,
            'name'        => $branch->name,
            'intent'      => $branch->intent,
            'status'      => $branch->status,
            'author'      => $branch->author?->name,
            'party'       => $branch->party?->label(),
            'change_count' => $branch->changes()->count(),
            // Both surfaced on every read. A reviewer needs to know the plan
            // moved underneath this *before* they read the diff, not after they
            // agree to it.
            'conflicts'   => $conflicts,
            'has_conflicts' => $conflicts !== [],
            'affected_parties' => $affected->map(fn (CircleParty $p) => [
                'id' => $p->id, 'label' => $p->label(),
            ])->values()->all(),
            'awaiting' => $outstanding->map(fn (CircleParty $p) => [
                'id' => $p->id, 'label' => $p->label(),
            ])->values()->all(),
            'signatures' => $approvals->map(fn (DecisionApproval $a) => [
                'party'    => $a->party?->label(),
                'person'   => $a->actor?->name,
                'outcome'  => $a->outcome,
                'comment'  => $a->comment,
                'at'       => $a->occurred_at?->toISOString(),
            ])->values()->all(),
            'decision_id' => $branch->decision_id,
            'proposed_at' => $branch->proposed_at?->toISOString(),
            'merged_at'   => $branch->merged_at?->toISOString(),
            'merged_by'   => $branch->mergedBy?->name,
            'created_at'  => $branch->created_at?->toISOString(),
        ];

        if ($withChanges) {
            $payload['changes'] = $branch->changes
                ->when(
                    $onlyGoal !== null,
                    fn ($changes) => $changes->filter(fn (GoalChange $c) => $c->goal_id === $onlyGoal
                        || $c->parent_goal_id === $onlyGoal),
                )
                ->map(fn (GoalChange $c) => $this->presentChange($c, $conflicts))
                ->values()
                ->all();
        }

        return $payload;
    }

    private function presentChange(GoalChange $change, array $conflicts = []): array
    {
        return [
            'id'          => $change->id,
            'change_type' => $change->change_type,
            'goal_id'     => $change->goal_id,
            'goal_title'  => $change->goal?->title,
            'temp_key'    => $change->temp_key,
            'parent_temp_key' => $change->parent_temp_key,
            'parent_goal_id'  => $change->parent_goal_id,
            'attributes'  => $change->attributes_json,
            // What it was looking at, so the UI can render an honest before →
            // after rather than only the value being asked for.
            'base'        => $change->base_json,
            'reason'      => $change->reason,
            'summary'     => $this->branches->describe($change),
            'author'      => $change->author?->name,
            'conflicts'   => array_values(array_filter(
                $conflicts,
                fn (array $c) => ($c['change_id'] ?? null) === $change->id,
            )),
            'created_at'  => $change->created_at?->toISOString(),
        ];
    }
}
