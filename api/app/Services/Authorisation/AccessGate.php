<?php

namespace App\Services\Authorisation;

use App\Enums\AgentExecutionMode;
use App\Enums\AuditEventType;
use App\Enums\CircleRole;
use App\Enums\Permission;
use App\Models\AgentInstance;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\CircleResource;
use App\Models\ResourceAccessOverride;
use App\Models\RoleGrant;
use App\Models\User;
use App\Services\Audit\AuditChain;

/**
 * The single authorisation entry point (spec §10).
 *
 * Evaluation order mirrors the spec's six checks exactly:
 *   1. active Circle member?
 *   2. does the Circle role permit the action?
 *   3. does the resource policy permit the action?
 *   4. is the Circle active and not expired/archived?
 *   5. any explicit deny/expiry constraint?
 *   6. for an agent: agent-readable and within mandate?
 *
 * The effective permission is the *intersection* of all of them. Nothing is
 * granted by organisation membership alone.
 */
class AccessGate
{
    /** Permissions that remain available on a closed Circle, for internal members. */
    private const READ_ONLY_AFTER_CLOSURE = [
        Permission::CircleView,
        Permission::ResourceView,
        Permission::ResourceDownload,
        Permission::ExportCreate,
    ];

    /** External collaborators are denied these by default (spec §10). */
    private const EXTERNAL_DENIED_BY_DEFAULT = [
        Permission::ResourceShare,
        Permission::ResourceDownload,
        Permission::ResourceDelete,
        Permission::ResourceAgentRead,
        Permission::CircleManageMembers,
        Permission::CircleClose,
    ];

    public function __construct(private readonly AuditChain $audit) {}

    public function inspect(
        Actor $actor,
        Permission $permission,
        Circle $circle,
        ?CircleResource $resource = null,
    ): AccessDecision {
        // A resource can only ever be reached through its own Circle.
        if ($resource !== null && $resource->circle_id !== $circle->id) {
            return AccessDecision::deny('wrong_circle', 'The resource does not belong to this Circle.');
        }

        return $actor instanceof AgentInstance
            ? $this->inspectAgent($actor, $permission, $circle, $resource)
            : $this->inspectUser($actor, $permission, $circle, $resource);
    }

    public function allows(Actor $actor, Permission $permission, Circle $circle, ?CircleResource $resource = null): bool
    {
        return $this->inspect($actor, $permission, $circle, $resource)->allowed;
    }

    /**
     * Authorise or throw. Every denial is written to the audit log, because
     * "who was refused what" is part of the Circle's record (spec §11).
     */
    public function authorise(
        Actor $actor,
        Permission $permission,
        Circle $circle,
        ?CircleResource $resource = null,
    ): void {
        $decision = $this->inspect($actor, $permission, $circle, $resource);

        if ($decision->allowed) {
            return;
        }

        $this->audit->record(
            AuditEventType::AccessDenied,
            $circle,
            $actor->actorType(),
            $actor->actorId(),
            $resource?->resource_type,
            $resource?->id,
            metadata: [
                'permission' => $permission->value,
                'reason'     => $decision->reason,
                'message'    => $decision->message,
            ],
        );

        throw new AuthorisationException($decision);
    }

    // ---------------------------------------------------------------- users

    private function inspectUser(User $user, Permission $permission, Circle $circle, ?CircleResource $resource): AccessDecision
    {
        // (1) active Circle membership
        $membership = $this->membershipFor($user, $circle);

        if ($membership === null) {
            return AccessDecision::deny('not_member', 'You are not a member of this Circle.');
        }

        if ($membership->revoked_at !== null) {
            return AccessDecision::deny('membership_revoked', 'Your access to this Circle has been revoked.');
        }

        if ($membership->invite_status !== 'active') {
            return AccessDecision::deny('membership_not_active', 'Your Circle invitation has not been accepted.');
        }

        if ($membership->expires_at !== null && $membership->expires_at->isPast()) {
            return AccessDecision::deny('membership_expired', 'Your access to this Circle has expired.');
        }

        // (4) Circle lifecycle. Closure revokes external participants entirely
        // and leaves internal members with a read-only record.
        if ($circle->isClosed()) {
            if ($membership->is_external) {
                return AccessDecision::deny('circle_closed', 'This Circle is closed; external access has been revoked.');
            }

            if (! in_array($permission, self::READ_ONLY_AFTER_CLOSURE, true)) {
                return AccessDecision::deny('circle_closed', 'This Circle is closed and accepts no further changes.');
            }
        } elseif ($circle->isExpired() && ! in_array($permission, self::READ_ONLY_AFTER_CLOSURE, true)) {
            return AccessDecision::deny('circle_expired', 'This Circle has passed its expiry date and accepts no further changes.');
        }

        // (5) explicit per-user deny always wins, before role defaults.
        $grant = $this->roleGrant($circle, $user, $permission);

        if ($grant !== null && ! $grant->allow) {
            return AccessDecision::deny('explicitly_denied', 'This action has been explicitly denied for you in this Circle.');
        }

        // (2) role default, unless an explicit grant adds the permission.
        $role = $membership->circle_role;

        if (! $role->grants($permission) && $grant === null) {
            return AccessDecision::deny(
                'role_denies',
                sprintf('The %s role does not permit %s.', $role->value, $permission->value),
            );
        }

        // External collaborator defaults (spec §10).
        if ($membership->is_external && in_array($permission, self::EXTERNAL_DENIED_BY_DEFAULT, true)) {
            $override = $resource !== null ? $this->resourceOverride($resource, $user, $permission) : null;

            if ($override === null || ! $override->allow) {
                return AccessDecision::deny(
                    'external_default_denies',
                    sprintf('External collaborators require an explicit grant for %s.', $permission->value),
                );
            }
        }

        // (3) resource policy
        if ($resource !== null) {
            $resourceDecision = $this->inspectResourcePolicy($user, $permission, $resource);

            if (! $resourceDecision->allowed) {
                return $resourceDecision;
            }
        }

        return AccessDecision::allow();
    }

    private function inspectResourcePolicy(User $user, Permission $permission, CircleResource $resource): AccessDecision
    {
        $override = $this->resourceOverride($resource, $user, $permission);

        if ($override !== null) {
            return $override->allow
                ? AccessDecision::allow('resource_override')
                : AccessDecision::deny('resource_override_denies', 'Access to this resource has been explicitly denied.');
        }

        $item = $resource->relationLoaded('evidenceItem') ? $resource->evidenceItem : $resource->evidenceItem()->first();

        if ($item === null) {
            return AccessDecision::allow();
        }

        // Download is a distinct right from view (spec §7).
        if ($permission === Permission::ResourceDownload && ! $item->downloadable) {
            return AccessDecision::deny('download_disabled', 'This evidence item is not downloadable.');
        }

        if ($permission === Permission::ResourceAgentRead && ! $item->agent_read) {
            return AccessDecision::deny('agent_read_not_enabled', 'This evidence item is not marked agent-readable.');
        }

        if ($item->expires_at !== null && $item->expires_at->isPast()) {
            return AccessDecision::deny('resource_expired', 'This evidence item has expired.');
        }

        return AccessDecision::allow();
    }

    // --------------------------------------------------------------- agents

    /**
     * An agent is authorised against its own instance identity and its
     * blueprint mandate. It has no membership row and cannot inherit one.
     */
    private function inspectAgent(AgentInstance $agent, Permission $permission, Circle $circle, ?CircleResource $resource): AccessDecision
    {
        // (6) an agent never reads outside its own Circle.
        if ($agent->circle_id !== $circle->id) {
            return AccessDecision::deny('agent_wrong_circle', 'The agent is not bound to this Circle.');
        }

        if (! $agent->isActive()) {
            return AccessDecision::deny('agent_disabled', 'This agent instance is disabled.');
        }

        // Closure stops agent runs outright (spec §16).
        if ($circle->isClosed()) {
            return AccessDecision::deny('circle_closed', 'This Circle is closed; agents have no access.');
        }

        if ($circle->isExpired()) {
            return AccessDecision::deny('circle_expired', 'This Circle has expired; agents have no access.');
        }

        if (! CircleRole::Agent->grants($permission)) {
            return AccessDecision::deny(
                'agent_role_denies',
                sprintf('The agent role does not permit %s.', $permission->value),
            );
        }

        // The blueprint is the authoritative mandate; the role enum is a floor.
        $blueprint = $agent->relationLoaded('blueprint') ? $agent->blueprint : $agent->blueprint()->first();

        if ($blueprint !== null) {
            if (! $blueprint->isActive()) {
                return AccessDecision::deny('agent_blueprint_inactive', 'This agent has been suspended.');
            }

            // A Circle-scoped blueprint cannot be instanced anywhere else, even
            // by its own organisation.
            if ($blueprint->isCircleScoped() && $blueprint->circle_id !== $circle->id) {
                return AccessDecision::deny(
                    'agent_blueprint_wrong_circle',
                    'This agent was authored for a different Circle.',
                );
            }

            $mode = $blueprint->execution_mode ?? AgentExecutionMode::ReadOnly;

            // The execution mode is checked before the declared action list, so
            // that dropping an agent to read_only stops everything it could do
            // without anyone having to edit its mandate or its tools.
            if ($permission !== Permission::CircleView && ! $mode->canWrite() && $permission->isWrite()) {
                return AccessDecision::deny(
                    'agent_read_only',
                    sprintf('%s is a read-only agent and cannot %s.', $blueprint->name, $permission->value),
                );
            }

            if ($permission === Permission::AgentExecute && ! $mode->canExecute()) {
                return AccessDecision::deny(
                    'agent_cannot_execute',
                    sprintf('%s is not permitted to execute actions.', $blueprint->name),
                );
            }

            // effectivePermissions() is the intersection of what the blueprint
            // declares and what its mode allows it to declare at all, so an
            // authored agent cannot widen its own mandate by listing more.
            if (! $blueprint->grants($permission)) {
                return AccessDecision::deny(
                    'agent_mandate_denies',
                    sprintf('%s is outside the %s mandate.', $permission->value, $blueprint->key),
                );
            }
        }

        if ($resource !== null) {
            $item = $resource->relationLoaded('evidenceItem') ? $resource->evidenceItem : $resource->evidenceItem()->first();

            // Agent read is opt-in per item and never inherited from the Circle.
            if ($item !== null && ! $item->agent_read) {
                return AccessDecision::deny('agent_read_not_enabled', 'This evidence item is not marked agent-readable.');
            }

            if ($item !== null && $item->expires_at !== null && $item->expires_at->isPast()) {
                return AccessDecision::deny('resource_expired', 'This evidence item has expired.');
            }
        }

        return AccessDecision::allow();
    }

    // -------------------------------------------------------------- lookups

    private function membershipFor(User $user, Circle $circle): ?CircleMembership
    {
        return CircleMembership::query()
            ->where('circle_id', $circle->id)
            ->where('user_id', $user->id)
            ->first();
    }

    private function roleGrant(Circle $circle, User $user, Permission $permission): ?RoleGrant
    {
        $grant = RoleGrant::query()
            ->where('circle_id', $circle->id)
            ->where('user_id', $user->id)
            ->where('permission', $permission->value)
            ->first();

        return $grant?->isActive() ? $grant : null;
    }

    private function resourceOverride(CircleResource $resource, User $user, Permission $permission): ?ResourceAccessOverride
    {
        $override = ResourceAccessOverride::query()
            ->where('resource_id', $resource->id)
            ->where('permission', $permission->value)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereNull('user_id'))
            // A user-specific override outranks a resource-wide one.
            ->orderByRaw('case when user_id is null then 1 else 0 end')
            ->first();

        return $override?->isActive() ? $override : null;
    }
}
