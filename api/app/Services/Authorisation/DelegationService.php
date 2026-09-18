<?php

namespace App\Services\Authorisation;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\CircleRole;
use App\Enums\Permission;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\RoleGrant;
use App\Models\User;
use App\Services\Audit\AuditChain;
use Illuminate\Support\Facades\DB;

/**
 * Handing one permission to one person, without moving them to a role that
 * carries a dozen others.
 *
 * The gate has read `role_grants` since the first migration; nothing wrote
 * them, so the only way to let a contributor author an agent was to make them
 * an owner — which also hands over membership control and the right to close
 * the Circle. That is how permission systems rot: the narrow need is
 * unreachable, so everyone gets the wide role.
 *
 * Two rules do the real work here.
 *
 * You cannot grant what you do not hold. Otherwise delegation is an escalation
 * ladder: an approver grants themselves `circle.close` and the role vocabulary
 * means nothing. This is checked against the granter's *effective* access, so
 * a permission they hold only by grant can be passed on, and one they have been
 * explicitly denied cannot.
 *
 * The Circle's owner cannot be denied the rights that make them the owner.
 * A Circle whose owner has been locked out of membership control has no way
 * back — there is no support desk inside the product.
 */
class DelegationService
{
    /** Rights the convening owner keeps no matter what anyone writes. */
    private const OWNER_INALIENABLE = [
        Permission::CircleClose,
        Permission::CircleDelete,
        Permission::CircleManageMembers,
    ];

    public function __construct(
        private readonly AccessGate $gate,
        private readonly AuditChain $audit,
    ) {}

    /**
     * @param  bool  $allow  false writes a deny, which outranks the role default.
     */
    public function grant(
        Circle $circle,
        User $granter,
        User $subject,
        Permission $permission,
        bool $allow = true,
        ?string $reason = null,
        ?\DateTimeInterface $expiresAt = null,
    ): RoleGrant {
        $this->gate->authorise($granter, Permission::CircleManageMembers, $circle);

        $membership = CircleMembership::where('circle_id', $circle->id)
            ->where('user_id', $subject->id)
            ->whereNull('revoked_at')
            ->first();

        abort_if(
            $membership === null,
            422,
            'That person is not a member of this Circle. Invite them before granting them anything.',
        );

        // No escalation ladder: delegation moves a right sideways, never up.
        abort_unless(
            $this->gate->allows($granter, $permission, $circle),
            403,
            sprintf('You cannot grant %s because you do not hold it yourself.', $permission->value),
        );

        abort_if(
            ! $allow
                && $subject->id === $circle->owner_user_id
                && in_array($permission, self::OWNER_INALIENABLE, true),
            422,
            'The Circle owner cannot be denied membership control or closure. Transfer ownership first.',
        );

        // Granting somebody something their role already covers is a no-op that
        // reads in the record as though a decision was made. Say so instead.
        abort_if(
            $allow && $membership->circle_role->grants($permission) && $membership->circle_role !== CircleRole::Owner,
            422,
            sprintf('The %s role already permits %s.', $membership->circle_role->value, $permission->value),
        );

        return DB::transaction(function () use (
            $circle, $granter, $subject, $permission, $allow, $reason, $expiresAt, $membership
        ) {
            $grant = RoleGrant::updateOrCreate(
                [
                    'circle_id'  => $circle->id,
                    'user_id'    => $subject->id,
                    'permission' => $permission->value,
                ],
                [
                    'allow'              => $allow,
                    'reason'             => $reason,
                    'granted_by_user_id' => $granter->id,
                    'expires_at'         => $expiresAt,
                ],
            );

            $this->audit->record(
                AuditEventType::PermissionGranted,
                $circle,
                ActorType::User,
                $granter->id,
                'role_grant',
                $grant->id,
                metadata: [
                    'subject'    => $subject->id,
                    'subject_name' => $subject->name,
                    'permission' => $permission->value,
                    'allow'      => $allow,
                    'role'       => $membership->circle_role->value,
                    'party'      => $membership->party?->label(),
                    'reason'     => $reason,
                    'expires_at' => $expiresAt?->format(DATE_ATOM),
                ],
            );

            return $grant;
        });
    }

    public function revoke(Circle $circle, User $actor, RoleGrant $grant): void
    {
        $this->gate->authorise($actor, Permission::CircleManageMembers, $circle);
        abort_unless($grant->circle_id === $circle->id, 404, 'No such grant in this Circle.');

        DB::transaction(function () use ($circle, $actor, $grant) {
            $this->audit->record(
                AuditEventType::PermissionRevoked,
                $circle,
                ActorType::User,
                $actor->id,
                'role_grant',
                $grant->id,
                metadata: [
                    'subject'    => $grant->user_id,
                    'permission' => $grant->permission->value,
                    'was_allow'  => $grant->allow,
                ],
            );

            $grant->delete();
        });
    }

    /** @return list<RoleGrant> */
    public function forCircle(Circle $circle, User $actor): array
    {
        $this->gate->authorise($actor, Permission::CircleView, $circle);

        return RoleGrant::where('circle_id', $circle->id)
            ->with(['user', 'grantedBy'])
            ->orderBy('created_at')
            ->get()
            ->all();
    }
}
