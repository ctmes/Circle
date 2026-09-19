<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Models\Circle;
use App\Models\CircleParty;
use App\Models\Goal;
use App\Models\GoalBranch;
use App\Models\GoalChange;
use App\Models\GoalScheduleChange;
use App\Models\User;
use App\Services\Authorisation\AccessGate;
use App\Services\Goals\GoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The goal tree — the workflow spine the UI is built around.
 *
 * `index` returns the whole tree in one call rather than a flat list the client
 * reassembles. The tree is the thing being looked at, and the depth cap keeps
 * it small enough that paginating it would cost more than it saves.
 */
class GoalController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly GoalService $goals,
    ) {}

    public function index(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        // The whole Circle's goals in one query, assembled into a tree in
        // memory. Eager-loading `children.children...` needs a fixed nesting
        // depth spelled out in the `with()` call, which silently truncates the
        // tree the moment the cap is raised — the bug being fixed here.
        $goals = Goal::where('circle_id', $circle->id)
            ->with(['owner', 'responsibleParty.organisation', 'acceptedBy'])
            ->orderBy('position')
            ->get();

        $tree = $this->tree($goals);

        // `?branch=` renders the plan as it would stand if that branch merged,
        // with every touched node marked. Computed here rather than stored,
        // because the overlay must always be against the tree as it is *now* —
        // a cached preview is exactly how somebody approves a diff that has
        // since stopped being true.
        if (($branchId = $request->query('branch')) !== null) {
            $branch = GoalBranch::where('circle_id', $circle->id)->find($branchId);

            abort_if($branch === null, 404, 'No such branch in this Circle.');

            $tree = $this->overlay($tree, $branch);
        }

        return response()->json(['data' => $tree]);
    }

    public function show(Request $request, Goal $goal): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $goal->circle);

        $goal->load([
            'owner', 'responsibleParty.organisation', 'acceptedBy', 'children',
            'commitments.owner', 'decisions.approver', 'claims',
            'scheduleChanges.changedBy', 'scheduleChanges.requiresParty',
        ]);

        // The real depth rather than the presenter's default of zero: a job
        // opened on its own page decides from this whether it can take a
        // sub-job, and a leaf at the cap reporting itself as a root offers a
        // button the API then refuses.
        return response()->json(['data' => $this->present(
            $goal,
            withChildren: true,
            withDetail: true,
            depth: $this->goals->depthOf($goal),
        )]);
    }

    public function store(Request $request, Circle $circle): JsonResponse
    {
        $data = $request->validate([
            'title'                => ['required', 'string', 'max:255'],
            'description'          => ['nullable', 'string', 'max:5000'],
            'parent_goal_id'       => ['nullable', 'string', 'exists:goals,id'],
            'owner_user_id'        => ['nullable', 'string', 'exists:users,id'],
            'responsible_party_id' => ['nullable', 'string', 'exists:circle_parties,id'],
            'acceptance_condition' => ['nullable', 'string', 'max:2000'],
            'starts_at'            => ['nullable', 'date'],
            'due_at'               => ['nullable', 'date'],
        ]);

        $parent = isset($data['parent_goal_id']) ? Goal::find($data['parent_goal_id']) : null;

        // Validated before authorising, because the parent *is* the subject:
        // a contractor working under a scoped engagement may add work beneath
        // their own package and nowhere else, and a new root goal is outside
        // every scope by definition (spec §21.2).
        $this->gate->authorise($request->user(), Permission::GoalCreate, $circle, subject: $parent);

        $party = isset($data['responsible_party_id'])
            ? CircleParty::find($data['responsible_party_id'])
            : null;

        // A party from another Circle would silently attach responsibility to
        // an organisation that cannot see the work.
        if ($party !== null) {
            abort_unless($party->circle_id === $circle->id, 422, 'That party is not in this Circle.');
        }

        $goal = $this->goals->create(
            circle: $circle,
            creator: $request->user(),
            title: $data['title'],
            description: $data['description'] ?? null,
            parent: $parent,
            owner: isset($data['owner_user_id']) ? User::find($data['owner_user_id']) : null,
            responsibleParty: $party,
            acceptanceCondition: $data['acceptance_condition'] ?? null,
            startsAt: isset($data['starts_at']) ? new \DateTimeImmutable($data['starts_at']) : null,
            dueAt: isset($data['due_at']) ? new \DateTimeImmutable($data['due_at']) : null,
        );

        return response()->json([
            'data' => $this->present($goal->load('owner', 'responsibleParty.organisation')),
        ], 201);
    }

    public function update(Request $request, Goal $goal): JsonResponse
    {
        // The goal is named as the subject so that a contractor working under
        // a scoped engagement is confined to the work they were engaged for
        // (spec §21.2). The gate fails closed without it.
        $this->gate->authorise($request->user(), Permission::GoalUpdate, $goal->circle, subject: $goal);

        $data = $request->validate([
            'title'                => ['sometimes', 'string', 'max:255'],
            'description'          => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status'               => ['sometimes', 'string', 'in:draft,active,blocked,in_review,met,abandoned'],
            'owner_user_id'        => ['sometimes', 'nullable', 'string', 'exists:users,id'],
            'responsible_party_id' => ['sometimes', 'nullable', 'string', 'exists:circle_parties,id'],
            'acceptance_condition' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'position'             => ['sometimes', 'integer', 'min:0'],
        ]);

        // `met` is reached by accepting the work, not by editing a field.
        // Otherwise acceptance is a formality anyone can skip.
        abort_if(
            ($data['status'] ?? null) === 'met',
            422,
            'A goal is met by accepting it, so that the record shows who accepted it.',
        );

        $goal = $this->goals->update($goal, $request->user(), $data);

        return response()->json(['data' => $this->present($goal->load('owner', 'responsibleParty.organisation'))]);
    }

    public function setProgress(Request $request, Goal $goal): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::GoalUpdate, $goal->circle, subject: $goal);

        $data = $request->validate(['progress' => ['required', 'integer', 'min:0', 'max:100']]);

        $goal = $this->goals->setProgress($goal, $request->user(), $data['progress']);

        return response()->json(['data' => $this->present($goal)]);
    }

    public function accept(Request $request, Goal $goal): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::GoalAccept, $goal->circle, subject: $goal);

        $goal = $this->goals->accept($goal, $request->user());

        return response()->json(['data' => $this->present($goal->load('acceptedBy'))]);
    }

    public function reschedule(Request $request, Goal $goal): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::GoalUpdate, $goal->circle, subject: $goal);

        $data = $request->validate([
            'due_at'            => ['nullable', 'date'],
            'reason'            => ['nullable', 'string', 'max:1000'],
            'requires_party_id' => ['nullable', 'string', 'exists:circle_parties,id'],
        ]);

        $change = $this->goals->reschedule(
            goal: $goal,
            actor: $request->user(),
            dueAt: isset($data['due_at']) ? new \DateTimeImmutable($data['due_at']) : null,
            reason: $data['reason'] ?? null,
            requiresParty: isset($data['requires_party_id'])
                ? CircleParty::find($data['requires_party_id'])
                : null,
        );

        return response()->json([
            'data' => $this->present($goal->fresh(['owner', 'responsibleParty.organisation'])),
            'change' => $this->presentChange($change),
        ]);
    }

    public function agreeReschedule(Request $request, GoalScheduleChange $change): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::GoalUpdate, $change->goal->circle, subject: $change->goal);

        return response()->json([
            'data' => $this->presentChange($this->goals->agreeReschedule($change, $request->user())),
        ]);
    }

    // ------------------------------------------------------------ presenters

    /**
     * Builds the tree from a flat set, at whatever depth it happens to be.
     *
     * Counts are gathered in three grouped queries rather than three per node.
     * At two levels the N+1 was tolerable; at four it is the difference between
     * a page and a stall, and the tree is the first thing anyone opens.
     *
     * @param  \Illuminate\Support\Collection<int, Goal>  $goals
     * @return list<array<string, mixed>>
     */
    private function tree(\Illuminate\Support\Collection $goals): array
    {
        $ids = $goals->pluck('id')->all();

        $counts = [
            'commitments' => $this->countBy('commitments', $ids),
            'decisions'   => $this->countBy('decisions', $ids),
            'claims'      => $this->countBy('claims', $ids),
        ];

        $byParent = $goals->groupBy('parent_goal_id');
        $hasChildren = $byParent->keys()->filter()->flip();

        $build = function (?string $parentId, int $depth) use (
            &$build, $byParent, $counts, $hasChildren
        ): array {
            return $byParent->get($parentId ?? '', collect())
                ->map(fn (Goal $g) => $this->present($g, counts: [
                    'commitments' => $counts['commitments'][$g->id] ?? 0,
                    'decisions'   => $counts['decisions'][$g->id] ?? 0,
                    'claims'      => $counts['claims'][$g->id] ?? 0,
                ], hasChildren: $hasChildren->has($g->id), depth: $depth)
                    + ['children' => $build($g->id, $depth + 1)])
                ->values()
                ->all();
        };

        return $build(null, 0);
    }

    /**
     * Lays a branch's proposed changes over the real tree.
     *
     * Every node the branch touches keeps its real values and gains a `branch`
     * block holding what would change. Nothing is overwritten, so the client
     * can draw a before → after on the same row rather than showing a plan that
     * silently claims to be the current one.
     *
     * Added goals are inserted where they would land, marked `added`, and
     * carry no id — there is nothing to click through to yet, and giving them a
     * placeholder id invites the UI to link somewhere that does not exist.
     *
     * @param  list<array<string, mixed>>  $tree
     * @return list<array<string, mixed>>
     */
    private function overlay(array $tree, GoalBranch $branch): array
    {
        $changes = $branch->changes()->with('goal')->get();

        $byGoal = $changes->whereNotNull('goal_id')->keyBy('goal_id');

        // Adds, grouped by where they attach. Adds nesting under other adds are
        // resolved by temp_key so a whole proposed sub-tree renders at once.
        $addsByParentGoal = $changes->where('change_type', 'add')
            ->whereNull('parent_temp_key')
            ->groupBy('parent_goal_id');
        $addsByParentTemp = $changes->where('change_type', 'add')
            ->whereNotNull('parent_temp_key')
            ->groupBy('parent_temp_key');

        $renderAdd = function (GoalChange $change, int $depth) use (&$renderAdd, $addsByParentTemp): array {
            $attributes = $change->attributes_json ?? [];

            return [
                'id'                  => null,
                'change_id'           => $change->id,
                'parent_goal_id'      => $change->parent_goal_id,
                'title'               => $attributes['title'] ?? 'Untitled',
                'description'         => $attributes['description'] ?? null,
                'status'              => $attributes['status'] ?? 'active',
                'owner'               => null,
                'responsible_party'   => null,
                'acceptance_condition' => $attributes['acceptance_condition'] ?? null,
                'accepted_by'         => null,
                'accepted_at'         => null,
                'starts_at'           => $attributes['starts_at'] ?? null,
                'due_at'              => $attributes['due_at'] ?? null,
                'progress'            => 0,
                'progress_reported'   => 0,
                'progress_is_derived' => false,
                'is_overdue'          => false,
                'position'            => $change->position,
                'depth'               => $depth,
                'counts'              => ['commitments' => 0, 'decisions' => 0, 'claims' => 0],
                'branch'              => ['state' => 'added', 'change_id' => $change->id],
                'children'            => $addsByParentTemp
                    ->get($change->temp_key, collect())
                    ->map(fn (GoalChange $c) => $renderAdd($c, $depth + 1))
                    ->values()
                    ->all(),
            ];
        };

        $walk = function (array $nodes) use (&$walk, $byGoal, $addsByParentGoal, $renderAdd): array {
            $out = [];

            foreach ($nodes as $node) {
                $change = $byGoal->get($node['id']);

                if ($change !== null) {
                    $node['branch'] = [
                        'state'     => $change->change_type === 'remove' ? 'removed' : 'changed',
                        'change_id' => $change->id,
                        'from'      => $change->base_json,
                        'to'        => $change->attributes_json,
                        'reason'    => $change->reason,
                    ];
                }

                $node['children'] = $walk($node['children'] ?? []);

                foreach ($addsByParentGoal->get($node['id'], collect()) as $add) {
                    $node['children'][] = $renderAdd($add, ($node['depth'] ?? 0) + 1);
                }

                $out[] = $node;
            }

            return $out;
        };

        $tree = $walk($tree);

        // Adds with no parent are new top-level goals.
        foreach ($addsByParentGoal->get('', collect())->merge($addsByParentGoal->get(null, collect())) as $add) {
            $tree[] = $renderAdd($add, 0);
        }

        return $tree;
    }

    /**
     * @param  list<string>  $goalIds
     * @return array<string, int>
     */
    private function countBy(string $table, array $goalIds): array
    {
        if ($goalIds === []) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table($table)
            ->whereIn('goal_id', $goalIds)
            ->selectRaw('goal_id, count(*) as total')
            ->groupBy('goal_id')
            ->pluck('total', 'goal_id')
            ->all();
    }

    private function present(
        Goal $goal,
        bool $withChildren = false,
        bool $withDetail = false,
        ?array $counts = null,
        ?bool $hasChildren = null,
        int $depth = 0,
    ): array {
        $payload = [
            'id'                   => $goal->id,
            'parent_goal_id'       => $goal->parent_goal_id,
            'title'                => $goal->title,
            'description'          => $goal->description,
            'status'               => $goal->status->value,
            'owner'                => $goal->owner === null ? null : [
                'id'   => $goal->owner->id,
                'name' => $goal->owner->name,
            ],
            'responsible_party'    => $goal->responsibleParty === null ? null : [
                'id'    => $goal->responsibleParty->id,
                'label' => $goal->responsibleParty->label(),
                'role'  => $goal->responsibleParty->party_role->value,
            ],
            'acceptance_condition' => $goal->acceptance_condition,
            'accepted_by'          => $goal->acceptedBy?->name,
            'accepted_at'          => $goal->accepted_at?->toISOString(),
            'starts_at'            => $goal->starts_at?->toISOString(),
            'due_at'               => $goal->due_at?->toISOString(),
            // The stored figure and the one worth showing. A parent's stored
            // number is meaningless, so the UI reads `progress` and the API is
            // explicit that it is derived for anything with children.
            'progress'             => $goal->effectiveProgress(),
            'progress_reported'    => (int) $goal->progress,
            'progress_is_derived'  => $hasChildren ?? $goal->children()->exists(),
            'is_overdue'           => $goal->isOverdue(),
            'position'             => (int) $goal->position,
            // How deep this node sits, so the client can indent without
            // walking the tree a second time to work it out.
            'depth'                => $depth,
            'counts'               => $counts ?? [
                'commitments' => $goal->commitments()->count(),
                'decisions'   => $goal->decisions()->count(),
                'claims'      => $goal->claims()->count(),
            ],
        ];

        if ($withChildren) {
            $payload['children'] = $goal->children
                ->map(fn (Goal $c) => $this->present($c, withChildren: true, depth: $depth + 1))
                ->all();
        }

        if ($withDetail) {
            $payload['schedule_changes'] = $goal->scheduleChanges
                ->map(fn (GoalScheduleChange $c) => $this->presentChange($c))
                ->all();
        }

        return $payload;
    }

    private function presentChange(GoalScheduleChange $change): array
    {
        return [
            'id'                  => $change->id,
            'from_due_at'         => $change->from_due_at?->toISOString(),
            'to_due_at'           => $change->to_due_at?->toISOString(),
            'days_moved'          => $change->daysMoved(),
            'reason'              => $change->reason,
            'changed_by'          => $change->changedBy?->name,
            'requires_party'      => $change->requiresParty?->label(),
            'awaiting_agreement'  => $change->isAwaitingAgreement(),
            'agreed_at'           => $change->agreed_at?->toISOString(),
            'created_at'          => $change->created_at?->toISOString(),
        ];
    }
}
