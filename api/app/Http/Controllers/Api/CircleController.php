<?php

namespace App\Http\Controllers\Api;

use App\Enums\CircleStatus;
use App\Enums\Permission;
use App\Http\Resources\CircleResource as CircleView;
use App\Models\Circle;
use App\Models\Commitment;
use App\Models\Decision;
use App\Models\DerivedArtifact;
use App\Models\Organisation;
use App\Services\Authorisation\AccessGate;
use App\Services\Circles\CircleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CircleController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly CircleService $circles,
    ) {}

    /** Only Circles the user is actually a member of — never org-wide. */
    public function index(Request $request): JsonResponse
    {
        $circles = $request->user()->circles()
            ->with('owner')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => CircleView::collection($circles)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organisation_id' => ['required', 'string', 'exists:organisations,id'],
            'name'            => ['required', 'string', 'max:200'],
            'purpose'         => ['required', 'string', 'max:5000'],
            'expires_at'      => ['nullable', 'date', 'after:now'],
            'starts_at'       => ['nullable', 'date'],
            'status'          => ['nullable', 'string', 'in:draft,active'],
        ]);

        $organisation = Organisation::findOrFail($data['organisation_id']);

        // Creating a Circle requires organisation membership; everything after
        // creation is governed by Circle membership alone.
        abort_unless(
            $organisation->memberships()->where('user_id', $request->user()->id)->exists(),
            403,
            'You are not a member of this organisation.',
        );

        $circle = $this->circles->create(
            organisation: $organisation,
            owner: $request->user(),
            name: $data['name'],
            purpose: $data['purpose'],
            expiresAt: isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
            startsAt: isset($data['starts_at']) ? new \DateTimeImmutable($data['starts_at']) : null,
            status: CircleStatus::from($data['status'] ?? 'active'),
        );

        return response()->json(['data' => new CircleView($circle->load('owner'))], 201);
    }

    public function show(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        return response()->json(['data' => new CircleView($circle->load('owner', 'organisation'))]);
    }

    public function update(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleManageMembers, $circle);

        $data = $request->validate([
            'name'       => ['sometimes', 'string', 'max:200'],
            'purpose'    => ['sometimes', 'string', 'max:5000'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'status'     => ['sometimes', 'string', 'in:draft,active,closing'],
        ]);

        $circle->fill($data)->save();

        return response()->json(['data' => new CircleView($circle->fresh()->load('owner'))]);
    }

    public function close(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleClose, $circle);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $circle = $this->circles->close($circle, $request->user(), $data['reason'] ?? null);

        return response()->json(['data' => new CircleView($circle->fresh()->load('owner'))]);
    }

    /**
     * The "Now" view (spec §12): only what the mission needs right now —
     * the next decision, what is overdue, and the latest derived brief.
     */
    public function overview(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $pendingDecisions = Decision::where('circle_id', $circle->id)
            ->where('status', 'pending')
            ->with('approver')
            ->orderBy('expires_at')
            ->get();

        $commitments = Commitment::where('circle_id', $circle->id)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->with('owner')
            ->orderBy('due_at')
            ->get();

        $latestBrief = DerivedArtifact::where('circle_id', $circle->id)
            ->where('artifact_type', 'agent_summary')
            ->latest()
            ->first();

        $recentApprovals = Decision::where('circle_id', $circle->id)
            ->whereIn('status', ['approved', 'rejected'])
            ->with('approver')
            ->latest('resolved_at')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => [
                'circle'  => new CircleView($circle->load('owner')),
                'countdown' => [
                    'expires_at' => $circle->expires_at?->toISOString(),
                    'days_left'  => $circle->expires_at ? now()->diffInDays($circle->expires_at, false) : null,
                    'expired'    => $circle->isExpired(),
                ],
                'next_decision' => $pendingDecisions->first() ? [
                    'id'         => $pendingDecisions->first()->id,
                    'title'      => $pendingDecisions->first()->title,
                    'approver'   => $pendingDecisions->first()->approver?->name,
                    'expires_at' => $pendingDecisions->first()->expires_at?->toISOString(),
                ] : null,
                'pending_decisions' => $pendingDecisions->map(fn ($d) => [
                    'id' => $d->id, 'title' => $d->title, 'approver' => $d->approver?->name,
                    'subject_version' => $d->subject_version,
                ])->all(),
                'commitments' => $commitments->map(fn ($c) => [
                    'id' => $c->id, 'title' => $c->title, 'owner' => $c->owner?->name,
                    'status' => $c->status->value, 'due_at' => $c->due_at?->toISOString(),
                    'overdue' => $c->isOverdue(),
                ])->all(),
                'overdue_count'    => $commitments->filter->isOverdue()->count(),
                'recent_approvals' => $recentApprovals->map(fn ($d) => [
                    'id' => $d->id, 'title' => $d->title, 'status' => $d->status->value,
                    'approver' => $d->approver?->name, 'resolved_at' => $d->resolved_at?->toISOString(),
                ])->all(),
                'latest_brief' => $latestBrief ? [
                    'id'           => $latestBrief->id,
                    'created_at'   => $latestBrief->created_at?->toISOString(),
                    'model'        => $latestBrief->model_name,
                    'agent_run_id' => $latestBrief->agent_run_id,
                    'content'      => $latestBrief->content_json,
                    // Always travels with the brief so no UI can render it bare.
                    'derived'      => true,
                ] : null,
            ],
        ]);
    }
}
