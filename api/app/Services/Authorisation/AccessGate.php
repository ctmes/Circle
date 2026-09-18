<?php

namespace App\Services\Authorisation;

use App\Enums\AgentExecutionMode;
use App\Enums\AuditEventType;
use App\Enums\CircleRole;
use App\Enums\Permission;
use App\Models\AgentInstance;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\CircleParty;
use App\Models\CircleResource;
use App\Models\Engagement;
use App\Models\Goal;
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
 * and one added by spec §21.2:
 *   7. for a seat issued by an engagement: is the contract live, and is the
 *      subject inside its scope?
 *
 * The effective permission is the *intersection* of all of them. Nothing is
 * granted by organisation membership alone.
 *
 * (7) runs last because an engagement can only ever *narrow*. It never grants
 * a permission the role did not already carry, so nothing above it needs to
 * know it exists — and a Circle with no engagements behaves exactly as it did
 * before §21.
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
        Permission::CircleDelete,
    ];

    /**
     * Permissions whose subject is a goal, and which therefore cannot be
     * authorised against a scoped engagement without one (spec §21.2).
     *
     * Listed positively so adding a permission does not silently opt it out of
     * the scope check — a new goal verb that forgot to appear here is caught by
     * the fail-closed branch in inspectEngagement() instead of slipping past it.
     *
     * `goal.branch` and `goal.merge` are deliberately absent. Opening a branch
     * changes nothing and names no goal, so a scope check there could only ever
     * refuse an empty container; the check that matters happens when a change
     * is staged, where GoalBranchService names the goal it touches. And merging
     * is governed by §20.7's rule that every affected party signs, which is a
     * stronger constraint than a scope and already covers the same ground.
     */
    /**
     * What a seat may still do while its engagement is only *proposed*.
     *
     * The negotiation window (spec §21.1). A shortlisted applicant has to be
     * able to propose the branch that would assign them the work, and to argue
     * about the terms, before anybody has agreed to anything — and they have to
     * be able to do it inside the scope they applied for and nowhere else.
     *
     * Both are proposals. Neither changes the plan: a branch still needs every
     * affected party's signature to merge, and a comment is a comment. That is
     * why this exception is safe and why it is exactly two entries long.
     */
    private const NEGOTIATION_PERMISSIONS = [
        Permission::GoalBranch,
        Permission::CommentCreate,
    ];

    private const SCOPED_TO_A_GOAL = [
        Permission::GoalCreate,
        Permission::GoalUpdate,
        Permission::GoalAccept,
        Permission::CommitmentCreate,
        Permission::CommitmentUpdate,
    ];

    public function __construct(private readonly AuditChain $audit) {}

    public function inspect(
        Actor $actor,
        Permission $permission,
        Circle $circle,
        ?CircleResource $resource = null,
        ?Goal $subject = null,
    ): AccessDecision {
        // A resource can only ever be reached through its own Circle.
        if ($resource !== null && $resource->circle_id !== $circle->id) {
            return AccessDecision::deny('wrong_circle', 'The resource does not belong to this Circle.');
        }

        return $actor instanceof AgentInstance
            ? $this->inspectAgent($actor, $permission, $circle, $resource)
            : $this->inspectUser($actor, $permission, $circle, $resource, $subject);
    }

    public function allows(
        Actor $actor,
        Permission $permission,
        Circle $circle,
        ?CircleResource $resource = null,
        ?Goal $subject = null,
    ): bool {
        return $this->inspect($actor, $permission, $circle, $resource, $subject)->allowed;
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
        ?Goal $subject = null,
    ): void {
        $decision = $this->inspect($actor, $permission, $circle, $resource, $subject);

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

    private function inspectUser(
        User $user,
        Permission $permission,
        Circle $circle,
        ?CircleResource $resource,
        ?Goal $subject = null,
    ): AccessDecision {
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

        // (4) Circle lifecycle.
        //
        // A deleted Circle is out of reach for everyone who was in it, read
        // access included. The one act left is deciding whether it comes back,
        // which is the same right that took it away.
        if ($circle->isDeleted() && $permission !== Permission::CircleDelete) {
            return AccessDecision::deny('circle_deleted', 'This Circle has been deleted.');
        }

        // Closure revokes external participants entirely and leaves internal
        // members with a read-only record. Deleting one is housekeeping on a
        // record that no longer changes, so it survives closure; and closing
        // one that has run past its date is how an expired Circle is meant to
        // end, so expiry does not stand in the way of it.
        $survivesClosure = in_array($permission, self::READ_ONLY_AFTER_CLOSURE, true)
            || $permission === Permission::CircleDelete;

        if ($circle->isClosed()) {
            if ($membership->is_external) {
                return AccessDecision::deny('circle_closed', 'This Circle is closed; external access has been revoked.');
            }

            if (! $survivesClosure) {
                return AccessDecision::deny('circle_closed', 'This Circle is closed and accepts no further changes.');
            }
        } elseif ($circle->isExpired() && ! $survivesClosure && $permission !== Permission::CircleClose) {
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

        // The party this member belongs to, if the Circle uses parties. Carried
        // into the resource policy so a scope can be set once for a company
        // rather than repeated for each of its people as they come and go.
        //
        // effectivePartyId() rather than the raw column: a member with no party
        // row sits with the convener, which is the fallback private threads
        // already use. The two have to agree, or the convener's own staff are
        // the one group a party scope silently fails to describe.
        $partyId = $membership->effectivePartyId();

        // External collaborator defaults (spec §10).
        if ($membership->is_external && in_array($permission, self::EXTERNAL_DENIED_BY_DEFAULT, true)) {
            $override = $resource !== null ? $this->resourceOverride($resource, $user, $permission, $partyId) : null;

            if ($override === null || ! $override->allow) {
                return AccessDecision::deny(
                    'external_default_denies',
                    sprintf('External collaborators require an explicit grant for %s.', $permission->value),
                );
            }
        }

        // (3) resource policy
        if ($resource !== null) {
            $resourceDecision = $this->inspectResourcePolicy($user, $permission, $resource, $partyId);

            if (! $resourceDecision->allowed) {
                return $resourceDecision;
            }
        }

        // (7) the contract that issued this seat (spec §21.2)
        if ($membership->engagement_id !== null && $permission->isWrite()) {
            $engagementDecision = $this->inspectEngagement($membership, $permission, $subject);

            if (! $engagementDecision->allowed) {
                return $engagementDecision;
            }
        }

        return AccessDecision::allow();
    }

    /**
     * A seat issued by a temp contract lives and dies with it (spec §21.2).
     *
     * The term is checked against the clock on every request, which is why this
     * is not a scheduled job that revokes memberships when a contract ends: an
     * engagement whose end date passed an hour ago stops working an hour ago,
     * not whenever a sweep next runs.
     */
    private function inspectEngagement(CircleMembership $membership, Permission $permission, ?Goal $subject): AccessDecision
    {
        $engagement = $membership->relationLoaded('engagement')
            ? $membership->engagement
            : Engagement::find($membership->engagement_id);

        // A seat whose contract is gone keeps nothing. The membership column is
        // nullOnDelete, so this is reachable, and the safe reading of "your
        // contract no longer exists" is not "you may do as you like".
        if ($engagement === null) {
            return AccessDecision::deny(
                'engagement_missing',
                'The engagement that granted this access no longer exists.',
            );
        }

        if (! $engagement->permitsWork()) {
            // The one exception, and it is narrow by construction: a proposed
            // engagement is a negotiation, and a negotiation is conducted in
            // proposals. See NEGOTIATION_PERMISSIONS above. The scope check
            // below still runs, so an applicant argues about the work they
            // applied for and nothing else.
            $negotiating = $engagement->status === \App\Enums\EngagementStatus::Proposed
                && in_array($permission, self::NEGOTIATION_PERMISSIONS, true);

            if (! $negotiating) {
                return AccessDecision::deny(
                    'engagement_' . $engagement->status->value,
                    $engagement->refusalReason() ?? 'This engagement does not permit changes.',
                );
            }
        }

        if ($engagement->scope_goal_id === null) {
            return AccessDecision::allow();
        }

        // A scope was named and the goal it named is gone. Deny rather than
        // widen: the alternative hands a contractor confined to one package the
        // run of the whole Circle the moment that package is removed, which is
        // the opposite of what was agreed.
        if ($engagement->hasDanglingScope()) {
            return AccessDecision::deny(
                'engagement_scope_missing',
                'The work this engagement was scoped to no longer exists.',
            );
        }

        // Fail closed. For a permission whose subject *is* a goal, a caller
        // that did not say which goal has not been checked — and a scope check
        // that quietly passes when nobody supplied a subject is worse than
        // none, because the audit log reads as though it ran.
        if ($subject === null) {
            return in_array($permission, self::SCOPED_TO_A_GOAL, true)
                ? AccessDecision::deny(
                    'engagement_scope_unresolved',
                    'This engagement is scoped to particular work, and the request did not say which work it concerns.',
                )
                : AccessDecision::allow();
        }

        return $engagement->coversGoal($subject)
            ? AccessDecision::allow()
            : AccessDecision::deny(
                'engagement_out_of_scope',
                sprintf(
                    'This engagement covers %s and nothing outside it.',
                    $engagement->scopeGoal?->title ?? 'other work',
                ),
            );
    }

    private function inspectResourcePolicy(
        User $user,
        Permission $permission,
        CircleResource $resource,
        ?string $partyId = null,
    ): AccessDecision {
        $override = $this->resourceOverride($resource, $user, $permission, $partyId);

        if ($override !== null) {
            return $override->allow
                ? AccessDecision::allow('resource_override')
                : AccessDecision::deny(
                    $override->circle_party_id !== null ? 'party_scope_denies' : 'resource_override_denies',
                    $override->circle_party_id !== null
                        ? 'This item is scoped to another party.'
                        : 'Access to this resource has been explicitly denied.',
                );
        }

        $item = $resource->relationLoaded('evidenceItem') ? $resource->evidenceItem : $resource->evidenceItem()->first();

        if ($item === null) {
            return AccessDecision::allow();
        }

        // The item's own party scope. Checked after the override table on
        // purpose: a named grant is the documented way to let one person from
        // outside the party in, and it has to outrank the item's default.
        //
        // This is deliberately not a fourth width of override. An override
        // answers "who else may reach this", one row per permission; the scope
        // answers "whose material is this", once, for every permission at once.
        // The uploader sets it at upload, which is the only moment anyone
        // actually knows the answer.
        if (! $item->isVisibleToParty($partyId, CircleParty::convenerIdFor($resource->circle_id))) {
            return AccessDecision::deny(
                'party_restricted',
                'This evidence item is restricted to another party.',
            );
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

            // An agent instance is bound to a Circle, not to a party, so it has
            // no standing to hold one party's material. Refused outright rather
            // than resolved to a party: a Circle-level agent summarising every
            // bidder's rates into one shared answer is the leak this scope
            // exists to stop, and it would not look like a leak in the output.
            // A party bringing its own agent is what agent_connections is for,
            // and that agent is not this identity.
            if ($item !== null && $item->restricted_to_party_id !== null) {
                return AccessDecision::deny(
                    'party_restricted',
                    'This evidence item is restricted to a party; agents read only Circle-wide evidence.',
                );
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

    /**
     * The narrowest override that applies to this person on this resource.
     *
     * Three widths, and the narrowest wins: named user, then their party, then
     * the resource as a whole. Ordering rather than short-circuiting matters
     * because the common shape is a resource-wide deny with a named exception,
     * and evaluating the wide rule first would refuse the exception.
     *
     * An expired override is skipped rather than treated as a deny, so a lapsed
     * grant falls back to the next rule out instead of silently locking someone
     * out of a resource they otherwise have every right to.
     */
    private function resourceOverride(
        CircleResource $resource,
        User $user,
        Permission $permission,
        ?string $partyId = null,
    ): ?ResourceAccessOverride {
        $overrides = ResourceAccessOverride::query()
            ->where('resource_id', $resource->id)
            ->where('permission', $permission->value)
            ->where(function ($q) use ($user, $partyId) {
                $q->where('user_id', $user->id);

                if ($partyId !== null) {
                    $q->orWhere('circle_party_id', $partyId);
                }

                $q->orWhere(fn ($w) => $w->whereNull('user_id')->whereNull('circle_party_id'));
            })
            ->get()
            ->filter(fn (ResourceAccessOverride $o) => $o->isActive())
            ->sortByDesc(fn (ResourceAccessOverride $o) => $o->specificity());

        return $overrides->first();
    }
}
