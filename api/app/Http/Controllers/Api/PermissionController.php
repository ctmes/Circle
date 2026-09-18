<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Models\Circle;
use App\Models\RoleGrant;
use App\Models\User;
use App\Services\Authorisation\DelegationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Per-user permission grants (spec §10).
 *
 * Deliberately readable by anyone who can see the Circle, not just by whoever
 * can write them. An exception to the role vocabulary that only its author can
 * see is worse than no exception: the other parties are working against a
 * permission model that is not the one in force.
 */
class PermissionController extends Controller
{
    public function __construct(private readonly DelegationService $delegation) {}

    public function index(Request $request, Circle $circle): JsonResponse
    {
        $grants = $this->delegation->forCircle($circle, $request->user());

        return response()->json([
            'data' => array_map(fn (RoleGrant $g) => $this->present($g), $grants),
            'meta' => [
                // The vocabulary, so the UI never has to carry its own copy of
                // the permission list and drift from the enum.
                'grantable' => array_map(
                    fn (Permission $p) => ['value' => $p->value, 'is_write' => $p->isWrite()],
                    Permission::cases(),
                ),
            ],
        ]);
    }

    public function store(Request $request, Circle $circle): JsonResponse
    {
        $data = $request->validate([
            'user_id'    => ['required', 'string', 'exists:users,id'],
            'permission' => ['required', 'string', 'in:' . implode(',', array_column(Permission::cases(), 'value'))],
            'allow'      => ['nullable', 'boolean'],
            'reason'     => ['nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $grant = $this->delegation->grant(
            circle: $circle,
            granter: $request->user(),
            subject: User::findOrFail($data['user_id']),
            permission: Permission::from($data['permission']),
            allow: $data['allow'] ?? true,
            reason: $data['reason'] ?? null,
            expiresAt: isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
        );

        return response()->json(['data' => $this->present($grant->load(['user', 'grantedBy']))], 201);
    }

    public function destroy(Request $request, Circle $circle, RoleGrant $grant): JsonResponse
    {
        $this->delegation->revoke($circle, $request->user(), $grant);

        return response()->json(null, 204);
    }

    private function present(RoleGrant $grant): array
    {
        return [
            'id'         => $grant->id,
            'user_id'    => $grant->user_id,
            'user_name'  => $grant->user?->name,
            'permission' => $grant->permission->value,
            'allow'      => $grant->allow,
            'reason'     => $grant->reason,
            'granted_by' => $grant->grantedBy?->name,
            'granted_at' => $grant->created_at?->toISOString(),
            'expires_at' => $grant->expires_at?->toISOString(),
            'is_active'  => $grant->isActive(),
        ];
    }
}
