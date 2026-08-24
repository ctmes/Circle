<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Models\Circle;
use App\Models\CircleParty;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The organisations a Circle spans (spec §20.1).
 *
 * A Circle created before parties existed has none, and the first call to
 * `index` backfills the convener from `circles.organisation_id` — otherwise
 * every party-aware screen would have to special-case an empty list, and
 * private comment threads would have no owner to belong to.
 */
class PartyController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly AuditChain $audit,
    ) {}

    public function index(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $this->ensureConvener($circle);

        $parties = CircleParty::where('circle_id', $circle->id)
            ->with('organisation')
            ->withCount('memberships')
            ->orderByDesc('is_convener')
            ->orderBy('display_name')
            ->get();

        return response()->json(['data' => $parties->map(fn ($p) => $this->present($p))->all()]);
    }

    public function store(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::PartyManage, $circle);

        $data = $request->validate([
            'display_name'       => ['required', 'string', 'max:160'],
            'party_role'         => ['required', 'string', 'in:principal,contractor,subcontractor,advisor,observer'],
            'organisation_id'    => ['nullable', 'string', 'exists:organisations,id'],
            'external_reference' => ['nullable', 'string', 'max:120'],
        ]);

        $this->ensureConvener($circle);

        $party = CircleParty::create([
            'circle_id'          => $circle->id,
            'organisation_id'    => $data['organisation_id'] ?? null,
            'display_name'       => $data['display_name'],
            'party_role'         => $data['party_role'],
            'status'             => 'invited',
            'is_convener'        => false,
            'external_reference' => $data['external_reference'] ?? null,
            'invited_by_user_id' => $request->user()->id,
        ]);

        $this->audit->record(
            AuditEventType::PartyInvited,
            $circle,
            ActorType::User,
            $request->user()->id,
            'circle_party',
            $party->id,
            metadata: ['name' => $party->display_name, 'role' => $party->party_role->value],
        );

        return response()->json(['data' => $this->present($party)], 201);
    }

    public function update(Request $request, Circle $circle, CircleParty $party): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::PartyManage, $circle);
        abort_unless($party->circle_id === $circle->id, 404);

        $data = $request->validate([
            'display_name'       => ['sometimes', 'string', 'max:160'],
            'party_role'         => ['sometimes', 'string', 'in:principal,contractor,subcontractor,advisor,observer'],
            'status'             => ['sometimes', 'string', 'in:invited,active,suspended,withdrawn'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        // The convener cannot be demoted or removed — it holds the closure
        // right and receives the packet, so a Circle without one is broken.
        abort_if(
            $party->is_convener && in_array($data['status'] ?? null, ['withdrawn', 'suspended'], true),
            422,
            'The convening party cannot withdraw from its own Circle.',
        );

        $party->fill($data);

        if (($data['status'] ?? null) === 'active' && $party->joined_at === null) {
            $party->joined_at = now();
        }

        if (($data['status'] ?? null) === 'withdrawn') {
            $party->withdrawn_at = now();
        }

        $party->save();

        $event = match ($data['status'] ?? null) {
            'active'    => AuditEventType::PartyJoined,
            'withdrawn' => AuditEventType::PartyWithdrawn,
            'suspended' => AuditEventType::PartySuspended,
            default     => AuditEventType::PartyInvited,
        };

        $this->audit->record(
            $event,
            $circle,
            ActorType::User,
            $request->user()->id,
            'circle_party',
            $party->id,
            metadata: $data,
        );

        return response()->json(['data' => $this->present($party->fresh('organisation'))]);
    }

    /**
     * Backfill the convening party for a Circle that predates them.
     *
     * Idempotent and safe to call on every read: without it, a Circle from
     * before this schema has no party at all, and features that hang off one —
     * private threads, party-owned goals — have nothing to attach to.
     */
    private function ensureConvener(Circle $circle): void
    {
        $exists = CircleParty::where('circle_id', $circle->id)->where('is_convener', true)->exists();

        if ($exists) {
            return;
        }

        CircleParty::create([
            'circle_id'       => $circle->id,
            'organisation_id' => $circle->organisation_id,
            'display_name'    => $circle->organisation?->name ?? 'Convening organisation',
            'party_role'      => PartyRole::Convener->value,
            'status'          => 'active',
            'is_convener'     => true,
            'joined_at'       => $circle->created_at ?? now(),
        ]);
    }

    private function present(CircleParty $p): array
    {
        return [
            'id'                 => $p->id,
            'label'              => $p->label(),
            'display_name'       => $p->display_name,
            'organisation_id'    => $p->organisation_id,
            'is_bound'           => $p->organisation_id !== null,
            'party_role'         => $p->party_role->value,
            'status'             => $p->status->value,
            'is_convener'        => (bool) $p->is_convener,
            'is_active'          => $p->isActive(),
            'external_reference' => $p->external_reference,
            'member_count'       => (int) ($p->memberships_count ?? 0),
            'joined_at'          => $p->joined_at?->toISOString(),
        ];
    }
}
