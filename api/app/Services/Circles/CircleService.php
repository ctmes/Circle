<?php

namespace App\Services\Circles;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\CircleRole;
use App\Enums\CircleStatus;
use App\Models\AgentInstance;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Audit\AuditChain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CircleService
{
    /** Invitations are capability links; they should not live indefinitely. */
    private const INVITE_TTL_DAYS = 14;

    public function __construct(private readonly AuditChain $audit) {}

    public function create(
        Organisation $organisation,
        User $owner,
        string $name,
        string $purpose,
        ?\DateTimeInterface $expiresAt = null,
        ?\DateTimeInterface $startsAt = null,
        CircleStatus $status = CircleStatus::Active,
    ): Circle {
        return DB::transaction(function () use ($organisation, $owner, $name, $purpose, $expiresAt, $startsAt, $status) {
            $circle = Circle::create([
                'organisation_id' => $organisation->id,
                'name'            => $name,
                'purpose'         => $purpose,
                'status'          => $status,
                'owner_user_id'   => $owner->id,
                'starts_at'       => $startsAt ?? now(),
                'expires_at'      => $expiresAt,
            ]);

            // The owner's membership is what actually grants access — being the
            // owner_user_id alone confers nothing (spec §10).
            CircleMembership::create([
                'circle_id'     => $circle->id,
                'user_id'       => $owner->id,
                'circle_role'   => CircleRole::Owner,
                'is_external'   => false,
                'invite_status' => 'active',
            ]);

            $this->audit->record(
                AuditEventType::CircleCreated, $circle, ActorType::User, $owner->id,
                'circle', $circle->id, metadata: [
                    'name'       => $name,
                    'purpose'    => $purpose,
                    'expires_at' => $expiresAt?->format(DATE_ATOM),
                ],
            );

            return $circle;
        });
    }

    /**
     * Invites someone by email. Membership is created only on acceptance, so an
     * unaccepted invitation grants nothing.
     */
    public function invite(
        Circle $circle,
        User $invitedBy,
        string $email,
        CircleRole $role,
        bool $isExternal = true,
        ?\DateTimeInterface $expiresAt = null,
    ): Invitation {
        return DB::transaction(function () use ($circle, $invitedBy, $email, $role, $isExternal, $expiresAt) {
            $invitation = Invitation::create([
                'circle_id'          => $circle->id,
                'email'              => Str::lower(trim($email)),
                'circle_role'        => $role,
                'is_external'        => $isExternal,
                'token'              => Str::random(64),
                'invited_by_user_id' => $invitedBy->id,
                'expires_at'         => $expiresAt ?? now()->addDays(self::INVITE_TTL_DAYS),
            ]);

            $this->audit->record(
                AuditEventType::CircleMemberInvited, $circle, ActorType::User, $invitedBy->id,
                'invitation', $invitation->id, metadata: [
                    'email'       => $invitation->email,
                    'circle_role' => $role->value,
                    'is_external' => $isExternal,
                ],
            );

            return $invitation;
        });
    }

    /**
     * Redeems an invitation. The email must match the accepting account, or a
     * leaked link would hand Circle access to whoever found it.
     */
    public function acceptInvitation(string $token, User $user): CircleMembership
    {
        return DB::transaction(function () use ($token, $user) {
            $invitation = Invitation::where('token', $token)->lockForUpdate()->first();

            if ($invitation === null || ! $invitation->isRedeemable()) {
                throw new \RuntimeException('This invitation is not valid or has already been used.');
            }

            if (! hash_equals($invitation->email, Str::lower($user->email))) {
                throw new \RuntimeException('This invitation was issued to a different email address.');
            }

            $membership = CircleMembership::updateOrCreate(
                ['circle_id' => $invitation->circle_id, 'user_id' => $user->id],
                [
                    'circle_role'   => $invitation->circle_role,
                    'is_external'   => $invitation->is_external,
                    'invite_status' => 'active',
                    'revoked_at'    => null,
                ],
            );

            $invitation->forceFill([
                'accepted_at'      => now(),
                'accepted_user_id' => $user->id,
            ])->save();

            $this->audit->record(
                AuditEventType::CircleMemberRoleChanged, $invitation->circle, ActorType::User, $user->id,
                'circle_membership', $membership->id, metadata: [
                    'event'       => 'invitation_accepted',
                    'circle_role' => $membership->circle_role->value,
                ],
            );

            return $membership;
        });
    }

    public function changeRole(CircleMembership $membership, User $actor, CircleRole $role): CircleMembership
    {
        $previous = $membership->circle_role;
        $membership->forceFill(['circle_role' => $role])->save();

        $this->audit->record(
            AuditEventType::CircleMemberRoleChanged, $membership->circle, ActorType::User, $actor->id,
            'circle_membership', $membership->id, metadata: [
                'from' => $previous->value,
                'to'   => $role->value,
                'user' => $membership->user_id,
            ],
        );

        return $membership;
    }

    /** Revocation is immediate — the gate reads revoked_at on the next request. */
    public function removeMember(CircleMembership $membership, User $actor): void
    {
        $membership->forceFill([
            'revoked_at'    => now(),
            'invite_status' => 'revoked',
        ])->save();

        $this->audit->record(
            AuditEventType::CircleMemberRemoved, $membership->circle, ActorType::User, $actor->id,
            'circle_membership', $membership->id, metadata: [
                'user'        => $membership->user_id,
                'circle_role' => $membership->circle_role->value,
            ],
        );
    }

    /**
     * Closes a Circle. External participants and agents lose access immediately;
     * internal members retain a read-only record (spec §16).
     */
    public function close(Circle $circle, User $actor, ?string $reason = null): Circle
    {
        return DB::transaction(function () use ($circle, $actor, $reason) {
            $circle->forceFill([
                'status'    => CircleStatus::Archived,
                'closed_at' => now(),
            ])->save();

            // Revoke external memberships explicitly, so the revocation is a
            // recorded fact and not merely a consequence of Circle state.
            $externals = CircleMembership::where('circle_id', $circle->id)
                ->where('is_external', true)
                ->whereNull('revoked_at')
                ->get();

            foreach ($externals as $membership) {
                $membership->forceFill(['revoked_at' => now(), 'invite_status' => 'revoked'])->save();
            }

            // Disable agent instances bound to this Circle. The gate would
            // refuse them anyway on a closed Circle; this makes it explicit.
            AgentInstance::where('circle_id', $circle->id)
                ->update(['status' => 'disabled', 'disabled_at' => now()]);

            $this->audit->record(
                AuditEventType::CircleClosed, $circle, ActorType::User, $actor->id,
                'circle', $circle->id, metadata: [
                    'reason'                        => $reason,
                    'external_memberships_revoked'  => $externals->count(),
                ],
            );

            return $circle;
        });
    }
}
