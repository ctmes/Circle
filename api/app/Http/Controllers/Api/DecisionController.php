<?php

namespace App\Http\Controllers\Api;

use App\Enums\CommitmentStatus;
use App\Enums\Permission;
use App\Models\Circle;
use App\Models\Commitment;
use App\Models\Decision;
use App\Models\EvidenceItem;
use App\Models\User;
use App\Services\Authorisation\AccessGate;
use App\Services\Decisions\DecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DecisionController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly DecisionService $decisions,
    ) {}

    public function index(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $decisions = Decision::where('circle_id', $circle->id)
            ->with(['approver', 'createdBy', 'approvals.actor'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            // Pending decisions first (spec §12).
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderBy('expires_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $decisions->map(fn ($d) => $this->present($d))->all()]);
    }

    public function store(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::DecisionCreate, $circle);

        $data = $request->validate([
            'title'            => ['required', 'string', 'max:255'],
            'description'      => ['nullable', 'string', 'max:5000'],
            'approver_user_id' => ['nullable', 'string', 'exists:users,id'],
            'subject_type'     => ['nullable', 'string', 'in:evidence_item,claim,decision'],
            'subject_id'       => ['nullable', 'string'],
            'subject_version'  => ['nullable', 'string', 'max:50'],
            'expires_at'       => ['nullable', 'date', 'after:now'],
        ]);

        $approver = isset($data['approver_user_id']) ? User::find($data['approver_user_id']) : null;

        // The approver must themselves be an active member who can approve —
        // otherwise the decision could never be resolved.
        if ($approver !== null) {
            abort_unless(
                $this->gate->allows($approver, Permission::DecisionApprove, $circle),
                422,
                'The nominated approver cannot approve decisions in this Circle.',
            );
        }

        $subjectVersion = $data['subject_version'] ?? null;

        // Binding to evidence without naming a version is ambiguous, so resolve
        // it to the current version at creation time (spec §8).
        if (($data['subject_type'] ?? null) === 'evidence_item' && $subjectVersion === null && isset($data['subject_id'])) {
            $subjectVersion = (string) (EvidenceItem::find($data['subject_id'])?->currentVersion()?->version_number ?? '');
        }

        $decision = $this->decisions->create(
            circle: $circle,
            creator: $request->user(),
            title: $data['title'],
            description: $data['description'] ?? null,
            approver: $approver,
            subjectType: $data['subject_type'] ?? null,
            subjectId: $data['subject_id'] ?? null,
            subjectVersion: $subjectVersion ?: null,
            expiresAt: isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
        );

        return response()->json(['data' => $this->present($decision->load('approver', 'createdBy'))], 201);
    }

    public function approve(Request $request, Decision $decision): JsonResponse
    {
        return $this->resolve($request, $decision, 'approved');
    }

    public function reject(Request $request, Decision $decision): JsonResponse
    {
        return $this->resolve($request, $decision, 'rejected');
    }

    private function resolve(Request $request, Decision $decision, string $outcome): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::DecisionApprove, $decision->circle);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        try {
            $decision = $this->decisions->resolve($decision, $request->user(), $outcome, $data['comment'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($decision->fresh()->load('approver', 'approvals.actor'))]);
    }

    // ------------------------------------------------------------ commitments

    public function storeCommitment(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CommitmentCreate, $circle);

        $data = $request->validate([
            'title'                => ['required', 'string', 'max:255'],
            'description'          => ['nullable', 'string', 'max:5000'],
            'acceptance_condition' => ['nullable', 'string', 'max:2000'],
            'owner_user_id'        => ['nullable', 'string', 'exists:users,id'],
            'due_at'               => ['nullable', 'date'],
        ]);

        $commitment = $this->decisions->createCommitment(
            circle: $circle,
            creator: $request->user(),
            title: $data['title'],
            owner: isset($data['owner_user_id']) ? User::find($data['owner_user_id']) : null,
            dueAt: isset($data['due_at']) ? new \DateTimeImmutable($data['due_at']) : null,
            description: $data['description'] ?? null,
            acceptanceCondition: $data['acceptance_condition'] ?? null,
        );

        return response()->json(['data' => $this->presentCommitment($commitment->load('owner'))], 201);
    }

    public function updateCommitment(Request $request, Commitment $commitment): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CommitmentUpdate, $commitment->circle);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,open,blocked,done,cancelled'],
            'note'   => ['nullable', 'string', 'max:2000'],
            'due_at' => ['nullable', 'date'],
        ]);

        $commitment = $this->decisions->updateCommitment(
            commitment: $commitment,
            actor: $request->user(),
            status: isset($data['status']) ? CommitmentStatus::from($data['status']) : null,
            note: $data['note'] ?? null,
            dueAt: isset($data['due_at']) ? new \DateTimeImmutable($data['due_at']) : null,
        );

        return response()->json(['data' => $this->presentCommitment($commitment->fresh()->load('owner', 'updates'))]);
    }

    public function indexCommitments(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $commitments = Commitment::where('circle_id', $circle->id)
            ->with(['owner', 'updates'])
            ->orderBy('due_at')
            ->get();

        return response()->json(['data' => $commitments->map(fn ($c) => $this->presentCommitment($c))->all()]);
    }

    // ------------------------------------------------------------- presenters

    private function present(Decision $decision): array
    {
        return [
            'id'          => $decision->id,
            'title'       => $decision->title,
            'description' => $decision->description,
            'status'      => $decision->status->value,
            'created_by'  => ['id' => $decision->created_by_user_id, 'name' => $decision->createdBy?->name],
            'approver'    => ['id' => $decision->approver_user_id, 'name' => $decision->approver?->name],
            // The exact thing being approved. A decision that does not name a
            // version cannot be said to have approved anything specific.
            'subject'     => [
                'type'    => $decision->subject_type,
                'id'      => $decision->subject_id,
                'version' => $decision->subject_version,
            ],
            'expires_at'         => $decision->expires_at?->toISOString(),
            'resolved_at'        => $decision->resolved_at?->toISOString(),
            'resolution_comment' => $decision->resolution_comment,
            'is_expired'         => $decision->isExpired(),
            'agent_run_id'       => $decision->agent_run_id,
            'derived'            => $decision->agent_run_id !== null,
            'created_at'         => $decision->created_at?->toISOString(),
            'approval_history'   => $decision->relationLoaded('approvals')
                ? $decision->approvals->map(fn ($a) => [
                    'actor'           => $a->actor?->name,
                    'outcome'         => $a->outcome,
                    'subject_version' => $a->subject_version,
                    'comment'         => $a->comment,
                    'occurred_at'     => $a->occurred_at?->toISOString(),
                ])->all()
                : [],
        ];
    }

    private function presentCommitment(Commitment $commitment): array
    {
        return [
            'id'                   => $commitment->id,
            'title'                => $commitment->title,
            'description'          => $commitment->description,
            'acceptance_condition' => $commitment->acceptance_condition,
            'status'               => $commitment->status->value,
            'owner'                => ['id' => $commitment->owner_user_id, 'name' => $commitment->owner?->name],
            'due_at'               => $commitment->due_at?->toISOString(),
            'completed_at'         => $commitment->completed_at?->toISOString(),
            'is_overdue'           => $commitment->isOverdue(),
            'created_by_type'      => $commitment->created_by_type,
            'derived'              => $commitment->created_by_type === 'agent',
            'updates'              => $commitment->relationLoaded('updates')
                ? $commitment->updates->map(fn ($u) => [
                    'from' => $u->from_status, 'to' => $u->to_status,
                    'note' => $u->note, 'at' => $u->created_at?->toISOString(),
                ])->all()
                : [],
        ];
    }
}
