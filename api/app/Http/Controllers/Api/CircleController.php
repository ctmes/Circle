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
use App\Services\Circles\CircleDigest;
use App\Services\Circles\CircleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CircleController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly CircleService $circles,
        private readonly CircleDigest $digest,
    ) {}

    /** Only Circles the user is actually a member of — never org-wide. */
    public function index(Request $request): JsonResponse
    {
        $circles = $request->user()->circles()
            ->with('owner')
            ->orderByDesc('created_at')
            ->get()
            // A deleted Circle is gone for everyone who was in it. It stays
            // listed only for whoever could bring it back — to anyone else it
            // is a name they can no longer open.
            ->reject(fn (Circle $circle) => $circle->isDeleted()
                && ! $this->gate->allows($request->user(), Permission::CircleDelete, $circle))
            ->values();

        // The list's own summary of each Circle. Only here, not on the Circle
        // resource itself: inside a Circle every one of these facts has a
        // screen of its own, and computing them for every read would be waste.
        $digest = $this->digest->for($request->user(), $circles);

        return response()->json([
            'data' => $circles->map(fn (Circle $circle) => array_merge(
                (new CircleView($circle))->resolve($request),
                ['digest' => $digest[$circle->id] ?? null],
            ))->all(),
        ]);
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
            'progress'   => ['sometimes', 'integer', 'between:0,100'],
            // Optional, and worth asking for: a renamed mission is the kind of
            // change somebody reads back six months later and wants a why for.
            'reason'     => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        // Nothing here is mass-assigned. What the mission is called, what it
        // says it is for and when it ends are all assertions somebody makes on
        // the record, so each one goes through the service and onto the chain.
        $progress = $data['progress'] ?? null;
        $reason   = $data['reason'] ?? null;
        unset($data['progress'], $data['reason']);

        if ($data !== []) {
            $circle = $this->circles->updateDetails($circle, $request->user(), $data, $reason);
        }

        // A closed Circle never reaches here — the gate refuses every mutation
        // on one, so the figure it closed at stays the figure of record.
        if ($progress !== null) {
            $circle = $this->circles->setProgress($circle, $request->user(), (int) $progress);
        }

        return response()->json(['data' => new CircleView($circle->fresh()->load('owner'))]);
    }

    public function close(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleClose, $circle);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $circle = $this->circles->close($circle, $request->user(), $data['reason'] ?? null);

        return response()->json(['data' => new CircleView($circle->fresh()->load('owner'))]);
    }

    /** Deletion is a state; see CircleService::delete(). Nothing is removed. */
    public function destroy(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleDelete, $circle);

        abort_if($circle->isDeleted(), 409, 'This Circle has already been deleted.');

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $circle = $this->circles->delete($circle, $request->user(), $data['reason'] ?? null);

        return response()->json(['data' => new CircleView($circle->fresh()->load('owner'))]);
    }

    public function restore(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleDelete, $circle);

        abort_unless($circle->isDeleted(), 409, 'This Circle has not been deleted.');

        $circle = $this->circles->restore($circle, $request->user());

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
