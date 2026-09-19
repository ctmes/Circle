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

    public function __construct(
        private readonly AuditChain $audit,
        private readonly \App\Services\Work\WorkRecordService $records,
        private readonly \App\Services\Notifications\Notifier $notifier,
    ) {}

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
        $invitation = DB::transaction(function () use ($circle, $invitedBy, $email, $role, $isExternal, $expiresAt) {
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

        // Outside the transaction, and after it. An invitation that exists but
        // was never delivered is recoverable — the token is still in the
        // response and can be sent by hand. An invitation rolled back because
        // the mailer was down is not.
        $this->notifier->invited($invitation->setRelation('circle', $circle), $invitation->token);

        return $invitation;
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
     * Restates what the mission is: its name, its purpose, when it runs.
     *
     * These were mass-assigned on the controller until now, which meant the
     * one screen every reader starts from — the mission statement in the
     * header — could be rewritten without leaving a trace. That is the single
     * change with the widest blast radius in the product: every claim,
     * decision and commitment below it was made under some wording of the
     * purpose, and a packet that shows only the latest wording invites the
     * reading that they were all made under that one.
     *
     * So it goes through the chain like any other assertion, and it carries
     * the full before and after rather than a note that something changed — a
     * diff nobody can reconstruct is not evidence of anything.
     *
     * `starts_at` is here for the same reason the rest are. Convening a Circle
     * from a contract sets the commencement date, and that date is the origin
     * every other date in the plan was measured from — moving it silently
     * would move the whole programme with no record of who did it.
     *
     * @param  array<string, mixed>  $changes  Any of name, purpose, starts_at, expires_at, status.
     */
    public function updateDetails(Circle $circle, User $actor, array $changes, ?string $reason = null): Circle
    {
        $fields = array_intersect_key($changes, array_flip(['name', 'purpose', 'starts_at', 'expires_at', 'status']));

        if ($fields === []) {
            return $circle;
        }

        return DB::transaction(function () use ($circle, $actor, $fields, $reason) {
            $diff = [];

            foreach ($fields as $field => $value) {
                $before = $this->presentField($circle, $field);
                $circle->fill([$field => $value]);
                $after = $this->presentField($circle, $field);

                if ($before === $after) {
                    continue;
                }

                $diff[$field] = ['from' => $before, 'to' => $after];
            }

            // Nothing actually moved. Writing the event anyway would put a
            // change in the history that a reader could not tell from a real
            // one, which is worse than not recording the request at all.
            if ($diff === []) {
                $circle->discardChanges();

                return $circle;
            }

            $circle->save();

            $this->audit->record(
                AuditEventType::CircleDetailsChanged, $circle, ActorType::User, $actor->id,
                'circle', $circle->id, metadata: [
                    'changes' => $diff,
                    'fields'  => array_keys($diff),
                    'reason'  => $reason,
                ],
            );

            return $circle;
        });
    }

    /**
     * The value as the record should hold it — a string, a timestamp in a
     * fixed format, or null. Compared before and after the fill so that
     * "2026-01-01" and "2026-01-01T00:00:00+00:00" are recognised as the same
     * deadline rather than logged as a move.
     */
    private function presentField(Circle $circle, string $field): ?string
    {
        $value = $circle->{$field};

        return match (true) {
            $value === null                       => null,
            $value instanceof \DateTimeInterface  => $value->format(DATE_ATOM),
            $value instanceof \BackedEnum         => (string) $value->value,
            default                               => (string) $value,
        };
    }

    /**
     * Records how far along the mission is, as a whole percent.
     *
     * This is a statement by someone who runs the Circle, not a count of closed
     * commitments — so it is audited like any other assertion.
     */
    public function setProgress(Circle $circle, User $actor, int $progress): Circle
    {
        $progress = max(0, min(100, $progress));
        $previous = (int) $circle->progress;

        if ($progress === $previous) {
            return $circle;
        }

        $circle->forceFill([
            'progress'        => $progress,
            'progress_set_at' => now(),
        ])->save();

        $this->audit->record(
            AuditEventType::CircleProgressSet, $circle, ActorType::User, $actor->id,
            'circle', $circle->id, metadata: [
                'from' => $previous,
                'to'   => $progress,
            ],
        );

        return $circle;
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

            // Everyone who was engaged here carries something away (spec
            // §21.3). Compiled at closure rather than by a later job, because
            // this is the moment the Circle stops being readable and the
            // record is compiled *from* it — an engagement whose record was
            // never written is work that stops having happened.
            //
            // An engagement nobody formally ended is expired rather than
            // completed. Neither side said how it finished, and writing
            // "completed" on their behalf would be inventing somebody's
            // history for them.
            $records = $this->records->compileForClosure($circle, $actor);

            $this->audit->record(
                AuditEventType::CircleClosed, $circle, ActorType::User, $actor->id,
                'circle', $circle->id, metadata: [
                    'reason'                        => $reason,
                    'external_memberships_revoked'  => $externals->count(),
                    'work_records_compiled'         => $records,
                ],
            );

            return $circle;
        });
    }

    /**
     * Takes a Circle out of everyone's reach. Nothing in it is removed.
     *
     * An open Circle is closed first, through the ordinary path, so that
     * deletion never becomes a way round what closure guarantees: externals
     * lose access as a recorded fact, agents stop, and everyone engaged here
     * still carries their record away. A deleted Circle is therefore always a
     * closed one, and restoring it brings back the closed record rather than
     * reopening the work.
     */
    public function delete(Circle $circle, User $actor, ?string $reason = null): Circle
    {
        return DB::transaction(function () use ($circle, $actor, $reason) {
            $wasOpen = ! $circle->isClosed();

            if ($wasOpen) {
                $circle = $this->close($circle, $actor, $reason);
            }

            // An invitation is a capability link. One that still works after
            // its Circle has gone would hand out a membership to nothing, and
            // restoring the Circle should not quietly bring the link back.
            $invitations = Invitation::where('circle_id', $circle->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $circle->forceFill([
                'deleted_at'         => now(),
                'deleted_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record(
                AuditEventType::CircleDeleted, $circle, ActorType::User, $actor->id,
                'circle', $circle->id, metadata: [
                    'reason'              => $reason,
                    'closed_by_deletion'  => $wasOpen,
                    'invitations_revoked' => $invitations,
                ],
            );

            return $circle;
        });
    }

    /** Brings a deleted Circle back as the closed, read-only record it was. */
    public function restore(Circle $circle, User $actor): Circle
    {
        return DB::transaction(function () use ($circle, $actor) {
            $deletedAt = $circle->deleted_at;

            $circle->forceFill([
                'deleted_at'         => null,
                'deleted_by_user_id' => null,
            ])->save();

            $this->audit->record(
                AuditEventType::CircleRestored, $circle, ActorType::User, $actor->id,
                'circle', $circle->id, metadata: [
                    'deleted_at' => $deletedAt?->format(DATE_ATOM),
                ],
            );

            return $circle;
        });
    }
}
