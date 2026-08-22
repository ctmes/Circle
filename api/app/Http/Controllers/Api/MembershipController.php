<?php

namespace App\Http\Controllers\Api;

use App\Enums\CircleRole;
use App\Enums\Permission;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\EvidenceItem;
use App\Models\Invitation;
use App\Services\Agent\CircleSteward;
use App\Services\Authorisation\AccessGate;
use App\Services\Circles\CircleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MembershipController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly CircleService $circles,
        private readonly CircleSteward $steward,
    ) {}

    /** People and agents in this Circle, with what each can actually do. */
    public function index(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $members = $circle->memberships()->with('user')->get()->map(fn ($m) => [
            'membership_id' => $m->id,
            'user'          => ['id' => $m->user_id, 'name' => $m->user?->name, 'email' => $m->user?->email],
            'circle_role'   => $m->circle_role->value,
            'is_external'   => $m->is_external,
            'invite_status' => $m->invite_status,
            'is_active'     => $m->isActive(),
            'expires_at'    => $m->expires_at?->toISOString(),
            'revoked_at'    => $m->revoked_at?->toISOString(),
            'permissions'   => array_map(fn ($p) => $p->value, $m->circle_role->permissions()),
        ])->all();

        $agent = $this->steward->instanceFor($circle);

        return response()->json([
            'data' => [
                'members' => $members,
                'pending_invitations' => Invitation::query()
                    ->where('circle_id', $circle->id)
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at')
                    ->get()
                    ->map(fn ($i) => [
                        'id'          => $i->id,
                        'email'       => $i->email,
                        'circle_role' => $i->circle_role->value,
                        'is_external' => $i->is_external,
                        'expires_at'  => $i->expires_at?->toISOString(),
                    ])->all(),
                // "What this agent can access" (spec §12) — stated plainly, from
                // the enforced mandate rather than a hand-written blurb.
                'agents' => [[
                    'agent_instance_id'  => $agent->id,
                    'name'               => $agent->blueprint->name,
                    'blueprint'          => $agent->blueprint->key,
                    'version'            => $agent->blueprint->version,
                    'status'             => $agent->status,
                    'mandate'            => $agent->blueprint->mandate,
                    'allowed_actions'    => $agent->blueprint->allowed_actions,
                    'prohibited_actions' => $agent->blueprint->prohibited_actions,
                    'can_access' => EvidenceItem::query()
                        ->whereHas('resource', fn ($q) => $q->where('circle_id', $circle->id))
                        ->where('agent_read', true)
                        ->with('resource')
                        ->get()
                        ->map(fn ($i) => ['evidence_item_id' => $i->id, 'name' => $i->resource->name])
                        ->all(),
                ]],
            ],
        ]);
    }

    public function invite(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleManageMembers, $circle);

        $data = $request->validate([
            'email'       => ['required', 'email', 'max:255'],
            'circle_role' => ['required', 'string', 'in:owner,approver,reviewer,contributor,viewer'],
            'is_external' => ['nullable', 'boolean'],
            'expires_at'  => ['nullable', 'date', 'after:now'],
        ]);

        $invitation = $this->circles->invite(
            circle: $circle,
            invitedBy: $request->user(),
            email: $data['email'],
            role: CircleRole::from($data['circle_role']),
            isExternal: (bool) ($data['is_external'] ?? true),
            expiresAt: isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
        );

        return response()->json([
            'data' => [
                'id'          => $invitation->id,
                'email'       => $invitation->email,
                'circle_role' => $invitation->circle_role->value,
                'is_external' => $invitation->is_external,
                'expires_at'  => $invitation->expires_at->toISOString(),
                // Returned once, for the caller to deliver out of band. The MVP
                // has no mailer wired up; this is not stored in cleartext logs.
                'accept_token' => $invitation->token,
            ],
        ], 201);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        try {
            $membership = $this->circles->acceptInvitation($token, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'circle_id'   => $membership->circle_id,
                'circle_role' => $membership->circle_role->value,
            ],
        ]);
    }

    public function update(Request $request, Circle $circle, CircleMembership $membership): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleManageMembers, $circle);

        abort_unless($membership->circle_id === $circle->id, 404);

        $data = $request->validate([
            'circle_role' => ['required', 'string', 'in:owner,approver,reviewer,contributor,viewer'],
        ]);

        $membership = $this->circles->changeRole($membership, $request->user(), CircleRole::from($data['circle_role']));

        return response()->json(['data' => ['circle_role' => $membership->circle_role->value]]);
    }

    public function destroy(Request $request, Circle $circle, CircleMembership $membership): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleManageMembers, $circle);

        abort_unless($membership->circle_id === $circle->id, 404);

        // The owner's own membership is what keeps the Circle administrable.
        abort_if($membership->user_id === $circle->owner_user_id, 422, 'The Circle owner cannot be removed.');

        $this->circles->removeMember($membership, $request->user());

        return response()->json(['message' => 'Access revoked.']);
    }
}
