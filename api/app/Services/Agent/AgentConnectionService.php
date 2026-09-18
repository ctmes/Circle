<?php

namespace App\Services\Agent;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\CircleRole;
use App\Enums\DecisionStatus;
use App\Enums\Permission;
use App\Models\AgentConnection;
use App\Models\AgentInstance;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\Decision;
use App\Models\DecisionApproval;
use App\Models\User;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use Illuminate\Support\Facades\DB;

/**
 * Letting another party's agent into a Circle (spec §20.4).
 *
 * The intake endpoint has existed since the amendment: a party could register
 * its agent with an endpoint and a key fingerprint, and the row was written at
 * `pending`. Nothing ever moved it off `pending`, so `isAdmitted()` was false
 * forever and an admitted agent was a state the application could describe but
 * never reach.
 *
 * Admission is a decision rather than a setting, and the difference is not
 * decoration. A setting is something an administrator flips and nobody
 * remembers; a decision has a signatory, a timestamp and a place in the packet.
 * When a contractor's agent has written to a shared project and somebody asks
 * six months later who let it in, "it was on" is not an answer. The Decision row
 * this creates is.
 *
 * Two rules about who may admit.
 *
 * Not the party that proposed it. Admitting your own agent is not admission, it
 * is self-certification, and the whole reason a counterparty is shown a key
 * fingerprint is so that *they* accept a specific key rather than a name
 * anybody could type.
 *
 * An owner, because admission is closer to membership than to approval. Letting
 * software act inside a shared Circle is the same class of act as letting a
 * person in, and it is held to the same role.
 *
 * The single-party case is the exception and is allowed: an in-house Circle has
 * no counterparty to ask, and refusing there would mean a company could never
 * connect its own agent to its own work.
 */
class AgentConnectionService
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly AuditChain $audit,
    ) {}

    public function admit(AgentConnection $connection, User $approver, ?string $note = null): AgentConnection
    {
        $circle = $connection->circle;

        $this->gate->authorise($approver, Permission::AgentApprove, $circle);

        abort_if($connection->isAdmitted(), 422, 'That agent has already been admitted.');
        abort_if($connection->revoked_at !== null, 422, 'That connection was revoked. Propose it again.');

        $membership = $this->membership($circle, $approver);

        abort_unless(
            $membership->circle_role === CircleRole::Owner,
            403,
            'Admitting another party\'s agent is an owner\'s decision.',
        );

        // Counterparty rule, skipped only where there is genuinely no
        // counterparty to ask.
        $partyCount = $circle->parties()->count();

        abort_if(
            $partyCount > 1 && $membership->circle_party_id === $connection->circle_party_id,
            403,
            'A party cannot admit its own agent. This needs an owner from another party in the Circle.',
        );

        abort_if(
            $connection->key_fingerprint === null,
            422,
            'This connection has no key fingerprint. There is nothing specific to admit.',
        );

        return DB::transaction(function () use ($circle, $connection, $approver, $note, $membership) {
            // The decision the packet will carry. Recorded as resolved rather
            // than pending: the approver is standing here, and a decision that
            // records its own approval in the same breath is exactly what this
            // is. The comment holds the fingerprint, so the record says which
            // key was accepted rather than merely that one was.
            $decision = Decision::create([
                'circle_id'   => $circle->id,
                'title'       => sprintf('Admit agent "%s" from %s', $connection->name, $connection->party?->label() ?? 'an unnamed party'),
                'description' => sprintf(
                    "Admits an agent operated by %s to act in this Circle under that party's authority.\n\n"
                    . "Auth mode: %s\nKey fingerprint: %s\nEndpoint: %s\n\n"
                    . 'Circle never holds this agent\'s credentials. Every action it proposes is written to the '
                    . 'execution ledger and needs a human holding the authority of the party that bears it.',
                    $connection->party?->label() ?? 'an unnamed party',
                    $connection->auth_mode,
                    $connection->key_fingerprint,
                    $connection->endpoint_url ?? 'not supplied',
                ),
                'status'             => DecisionStatus::Approved,
                'created_by_user_id' => $approver->id,
                'approver_user_id'   => $approver->id,
                'subject_type'       => 'agent_connection',
                'subject_id'         => $connection->id,
                'resolved_at'        => now(),
                'resolution_comment' => $note,
            ]);

            DecisionApproval::create([
                'decision_id'   => $decision->id,
                'actor_user_id' => $approver->id,
                'outcome'       => 'approved',
                'subject_type'  => 'agent_connection',
                'subject_id'    => $connection->id,
                'comment'       => $note,
                'occurred_at'   => now(),
            ]);

            $connection->forceFill([
                'status'                   => 'active',
                'admitted_via_decision_id' => $decision->id,
                'admitted_by_user_id'      => $approver->id,
                'admitted_at'              => now(),
            ])->save();

            // An admitted connection that names a blueprint gets a seat at the
            // table: without an instance there is no identity for the gate to
            // authorise and no row for the ledger to point at.
            $instance = null;

            if ($connection->agent_blueprint_id !== null) {
                $instance = AgentInstance::firstOrCreate(
                    ['agent_blueprint_id' => $connection->agent_blueprint_id, 'circle_id' => $circle->id],
                    ['status' => 'active'],
                );
            }

            $this->audit->record(
                AuditEventType::AgentAdmitted, $circle, ActorType::User, $approver->id,
                'agent_connection', $connection->id, metadata: [
                    'name'          => $connection->name,
                    'party'         => $connection->party?->label(),
                    'admitted_by_party' => $membership->party?->label(),
                    'auth_mode'     => $connection->auth_mode,
                    'fingerprint'   => $connection->shortFingerprint(),
                    'decision_id'   => $decision->id,
                    'instance_id'   => $instance?->id,
                    'note'          => $note,
                ],
            );

            return $connection->fresh(['party.organisation']);
        });
    }

    /**
     * Withdraw an admitted agent.
     *
     * Available to an owner of either side, and deliberately so: the party that
     * brought the agent can pull it, and the party that admitted it can throw
     * it out. Consent that cannot be withdrawn unilaterally is not consent
     * anybody sensible would give in the first place.
     *
     * The instance is disabled rather than deleted. Its runs, its retrievals and
     * everything it proposed stay in the record — the agent stops, the history
     * of what it did does not.
     */
    public function revoke(AgentConnection $connection, User $actor, ?string $reason = null): AgentConnection
    {
        $circle = $connection->circle;

        $this->gate->authorise($actor, Permission::AgentApprove, $circle);
        abort_unless($connection->isAdmitted(), 422, 'That agent is not currently admitted.');

        $membership = $this->membership($circle, $actor);

        abort_unless(
            $membership->circle_role === CircleRole::Owner,
            403,
            'Withdrawing an agent is an owner\'s decision.',
        );

        return DB::transaction(function () use ($circle, $connection, $actor, $reason, $membership) {
            $connection->forceFill([
                'status'     => 'revoked',
                'revoked_at' => now(),
            ])->save();

            if ($connection->agent_blueprint_id !== null) {
                AgentInstance::where('agent_blueprint_id', $connection->agent_blueprint_id)
                    ->where('circle_id', $circle->id)
                    ->update(['status' => 'disabled', 'disabled_at' => now()]);
            }

            $this->audit->record(
                AuditEventType::AgentRevoked, $circle, ActorType::User, $actor->id,
                'agent_connection', $connection->id, metadata: [
                    'name'            => $connection->name,
                    'party'           => $connection->party?->label(),
                    'revoked_by_party' => $membership->party?->label(),
                    'reason'          => $reason,
                ],
            );

            return $connection->fresh(['party.organisation']);
        });
    }

    private function membership(Circle $circle, User $user): CircleMembership
    {
        $membership = CircleMembership::where('circle_id', $circle->id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->first();

        abort_if($membership === null, 403, 'You are not a member of this Circle.');

        return $membership;
    }
}
