<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Models\Circle;
use App\Models\CircleParty;
use App\Models\Goal;
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

        $roots = Goal::where('circle_id', $circle->id)
            ->whereNull('parent_goal_id')
            ->with([
                'owner', 'responsibleParty.organisation', 'acceptedBy',
                'children.owner', 'children.responsibleParty.organisation',
                'children.acceptedBy', 'children.commitments', 'children.decisions',
                'commitments', 'decisions',
            ])
            ->orderBy('position')
            ->get();

        return response()->json([
            'data' => $roots->map(fn (Goal $g) => $this->present($g, withChildren: true))->all(),
        ]);
    }

    public function show(Request $request, Goal $goal): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $goal->circle);

        $goal->load([
            'owner', 'responsibleParty.organisation', 'acceptedBy', 'children',
            'commitments.owner', 'decisions.approver', 'claims',
            'scheduleChanges.changedBy', 'scheduleChanges.requiresParty',
        ]);

        return response()->json(['data' => $this->present($goal, withChildren: true, withDetail: true)]);
    }

    public function store(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::GoalCreate, $circle);

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
            parent: isset($data['parent_goal_id']) ? Goal::find($data['parent_goal_id']) : null,
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
        $this->gate->authorise($request->user(), Permission::GoalUpdate, $goal->circle);

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
        $this->gate->authorise($request->user(), Permission::GoalUpdate, $goal->circle);

        $data = $request->validate(['progress' => ['required', 'integer', 'min:0', 'max:100']]);

        $goal = $this->goals->setProgress($goal, $request->user(), $data['progress']);

        return response()->json(['data' => $this->present($goal)]);
    }

    public function accept(Request $request, Goal $goal): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::GoalAccept, $goal->circle);

        $goal = $this->goals->accept($goal, $request->user());

        return response()->json(['data' => $this->present($goal->load('acceptedBy'))]);
    }

    public function reschedule(Request $request, Goal $goal): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::GoalUpdate, $goal->circle);

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
        $this->gate->authorise($request->user(), Permission::GoalUpdate, $change->goal->circle);

        return response()->json([
            'data' => $this->presentChange($this->goals->agreeReschedule($change, $request->user())),
        ]);
    }

    // ------------------------------------------------------------ presenters

    private function present(Goal $goal, bool $withChildren = false, bool $withDetail = false): array
    {
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
            'progress_is_derived'  => $goal->children()->exists(),
            'is_overdue'           => $goal->isOverdue(),
            'position'             => (int) $goal->position,
            'counts'               => [
                'commitments' => $goal->commitments()->count(),
                'decisions'   => $goal->decisions()->count(),
                'claims'      => $goal->claims()->count(),
            ],
        ];

        if ($withChildren) {
            $payload['children'] = $goal->children
                ->map(fn (Goal $c) => $this->present($c))
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
