<?php

namespace App\Services\Work;

use App\Enums\ActorType;
use App\Enums\AgentActionStatus;
use App\Enums\AuditEventType;
use App\Enums\CircleRole;
use App\Enums\CommitmentStatus;
use App\Enums\DecisionStatus;
use App\Enums\EngagementStatus;
use App\Enums\FeeBasis;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Models\AgentAction;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\CircleParty;
use App\Models\Commitment;
use App\Models\Decision;
use App\Models\DecisionApproval;
use App\Models\Engagement;
use App\Models\EngagementMeterEntry;
use App\Models\Goal;
use App\Models\User;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use Illuminate\Support\Facades\DB;

/**
 * The temp contract, from proposal to record (spec §21.2).
 *
 * Two rules shape everything here.
 *
 * An engagement is a thing two companies agreed, so agreement reuses the
 * decision machinery rather than a boolean somebody sets. The engaging party
 * and the contractor party both sign, and neither can sign for the other —
 * the same rule §20.7 applies to a merge, for the same reason.
 *
 * And an engagement narrows access rather than granting it. The seat it issues
 * carries `engagement_id`, and AccessGate reads that as check (7); nothing in
 * this class hands anybody a permission their Circle role did not already give
 * them. Ending an engagement therefore takes effect on the next request rather
 * than whenever a sweep runs.
 */
class EngagementService
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly AuditChain $audit,
        private readonly DiscoveryService $discovery,
    ) {}

    /**
     * Write the contract, unsigned.
     *
     * Created at `proposed`: check (7) then permits the contractor to argue
     * about it and to propose the branch that would assign them the work, and
     * nothing else. The negotiation window is a real state rather than a gap
     * between two writes.
     */
    public function propose(
        Circle $circle,
        User $actor,
        CircleParty $engaging,
        CircleParty $contractor,
        PrincipalType $principalType,
        string $principalId,
        string $title,
        ?Goal $scope = null,
        FeeBasis $feeBasis = FeeBasis::Fixed,
        ?int $feeAmountMinor = null,
        string $currency = 'AUD',
        ?int $unitCap = null,
        ?\DateTimeInterface $startsAt = null,
        ?\DateTimeInterface $endsAt = null,
        ?string $terms = null,
        ?string $openingId = null,
        ?string $blueprintVersionId = null,
    ): Engagement {
        $this->gate->authorise($actor, Permission::EngagementManage, $circle);

        abort_if($engaging->id === $contractor->id, 422, 'A company cannot engage itself.');
        abort_unless($engaging->circle_id === $circle->id && $contractor->circle_id === $circle->id, 422, 'Both parties must belong to this Circle.');
        abort_unless(
            $principalType->canBeEngaged(),
            422,
            'The contractor party is the company; a principal is a person or an agent instance.',
        );

        if ($scope !== null) {
            abort_unless($scope->circle_id === $circle->id, 422, 'That work is in another Circle.');
        }

        abort_if(
            $startsAt !== null && $endsAt !== null && $endsAt <= $startsAt,
            422,
            'An engagement cannot end before it starts.',
        );

        return DB::transaction(function () use (
            $circle, $actor, $engaging, $contractor, $principalType, $principalId,
            $title, $scope, $feeBasis, $feeAmountMinor, $currency, $unitCap,
            $startsAt, $endsAt, $terms, $openingId, $blueprintVersionId
        ) {
            $engagement = Engagement::create([
                'circle_id'                  => $circle->id,
                'engaging_party_id'          => $engaging->id,
                'contractor_party_id'        => $contractor->id,
                'principal_type'             => $principalType,
                'principal_id'               => $principalId,
                'work_opening_id'            => $openingId,
                'agent_blueprint_version_id' => $blueprintVersionId,
                'title'                      => $title,
                'terms'                      => $terms,
                'scope_goal_id'              => $scope?->id,
                'status'                     => EngagementStatus::Proposed,
                'fee_basis'                  => $feeBasis,
                'fee_amount_minor'           => $feeAmountMinor,
                'currency'                   => $currency,
                'unit_cap'                   => $unitCap,
                'starts_at'                  => $startsAt,
                'ends_at'                    => $endsAt,
                'created_by_user_id'         => $actor->id,
            ]);

            // The signatures live on a decision, so a signed engagement lands
            // in the packet as what it is rather than as a settings row.
            $decision = Decision::create([
                'circle_id'          => $circle->id,
                'title'              => sprintf('Engage %s: %s', $contractor->label(), $title),
                'description'        => $this->decisionBody($engagement),
                'status'             => DecisionStatus::Pending,
                'created_by_user_id' => $actor->id,
                'subject_type'       => 'engagement',
                'subject_id'         => $engagement->id,
            ]);

            $engagement->forceFill(['agreed_via_decision_id' => $decision->id])->save();

            $this->audit->record(
                AuditEventType::EngagementProposed, $circle, ActorType::User, $actor->id,
                'engagement', $engagement->id, metadata: [
                    'title'      => $title,
                    'engaging'   => $engaging->label(),
                    'contractor' => $contractor->label(),
                    'fee_basis'  => $feeBasis->value,
                    'scope'      => $scope?->title,
                    'starts_at'  => $startsAt?->format(DATE_ATOM),
                    'ends_at'    => $endsAt?->format(DATE_ATOM),
                ],
            );

            return $engagement->fresh();
        });
    }

    /**
     * Issue the seat this engagement pays for.
     *
     * Separate from propose() because a seat may exist already — a contractor
     * shortlisted for a second package in a Circle they are already working in
     * does not want a second membership row, they want their existing one
     * bound to the new contract.
     *
     * The role is a floor, not a grant. Check (7) narrows whatever it says.
     */
    public function issueSeat(Engagement $engagement, User $principal, CircleRole $role = CircleRole::Contributor): CircleMembership
    {
        $contractor = $engagement->contractorParty;

        // Never overwrite a seat somebody holds for a different company. A
        // person who is already in this Circle for the client cannot be turned
        // into the contractor's contributor by an engagement — that would
        // demote them and move which party their past work reads as.
        $existing = CircleMembership::where('circle_id', $engagement->circle_id)
            ->where('user_id', $principal->id)
            ->first();

        if ($existing !== null
            && $existing->circle_party_id !== null
            && $existing->circle_party_id !== $contractor->id) {
            abort(422, sprintf(
                '%s is already in this Circle for %s.',
                $principal->name,
                $existing->party?->label() ?? 'another company',
            ));
        }

        return CircleMembership::updateOrCreate(
            ['circle_id' => $engagement->circle_id, 'user_id' => $principal->id],
            [
                'circle_party_id' => $contractor->id,
                'engagement_id'   => $engagement->id,
                'circle_role'     => $role,
                // "Not the convener" — §20.1's reading of external. A
                // contractor is external unless they convened the Circle, and
                // the external defaults in §10 then apply on top of check (7).
                'is_external'     => ! $contractor->is_convener,
                'invite_status'   => 'active',
                // The seat expires with the contract even if nothing sweeps.
                // Belt and braces against check (7), which is the real
                // enforcement: two mechanisms agreeing is cheap, and a seat
                // that outlives its contract because one of them was skipped
                // is the failure this whole section exists to prevent.
                'expires_at'      => $engagement->ends_at,
            ],
        );
    }

    /**
     * One company signs. The engagement activates when both have.
     *
     * Signing for a party you do not belong to is refused even for an owner —
     * in a Circle the client convened, the client's owner holds every
     * permission there is and still cannot sign for the contractor.
     */
    public function agree(Engagement $engagement, User $actor, ?string $comment = null): Engagement
    {
        $this->gate->authorise($actor, Permission::EngagementManage, $engagement->circle);

        abort_unless(
            $engagement->status === EngagementStatus::Proposed,
            422,
            'This engagement is no longer awaiting agreement.',
        );

        $party = $this->partyOf($engagement->circle, $actor);

        abort_unless(
            $party !== null && in_array($party->id, [$engagement->engaging_party_id, $engagement->contractor_party_id], true),
            403,
            'This engagement is between two other companies, so your agreement is not what it needs.',
        );

        return $this->signFor($engagement, $party, $actor, $comment);
    }

    /**
     * Record one party's signature, without asking who is calling.
     *
     * Split out of agree() because the award path (spec §21.1) has to record
     * the *contractor's* signature while the client is the one making the
     * request. The applicant already agreed to these terms — they proposed
     * them — and routing that through agree() would authorise the wrong
     * person and then refuse a contributor for lacking `engagement.manage`.
     *
     * Authorisation is therefore the caller's responsibility, and there are
     * exactly two callers: agree(), which gates, and
     * WorkApplicationService::award(), which has already gated on `work.award`
     * and established that the actor speaks for the posting party.
     */
    public function signFor(Engagement $engagement, CircleParty $party, User $signatory, ?string $comment = null, ?\DateTimeInterface $at = null): Engagement
    {
        return DB::transaction(function () use ($engagement, $party, $signatory, $comment, $at) {
            DecisionApproval::updateOrCreate(
                ['decision_id' => $engagement->agreed_via_decision_id, 'actor_user_id' => $signatory->id],
                [
                    'circle_party_id' => $party->id,
                    'outcome'         => 'approved',
                    'subject_type'    => 'engagement',
                    'subject_id'      => $engagement->id,
                    'comment'         => $comment,
                    'occurred_at'     => $at ?? now(),
                ],
            );

            $signed = DecisionApproval::where('decision_id', $engagement->agreed_via_decision_id)
                ->where('outcome', 'approved')
                ->pluck('circle_party_id')
                ->filter()->unique();

            $bothSigned = $signed->contains($engagement->engaging_party_id)
                && $signed->contains($engagement->contractor_party_id);

            if ($bothSigned) {
                return $this->activate($engagement->fresh(), $signatory);
            }

            $this->audit->record(
                AuditEventType::EngagementAgreed, $engagement->circle, ActorType::User, $signatory->id,
                'engagement', $engagement->id, metadata: [
                    'party'   => $party->label(),
                    'comment' => $comment,
                    'awaits'  => $signed->contains($engagement->engaging_party_id)
                        ? $engagement->contractorParty?->label()
                        : $engagement->engagingParty?->label(),
                ],
            );

            return $engagement->fresh();
        });
    }

    /** The party a person speaks for in a Circle, or null if they are not in it. */
    public function partyFor(Circle $circle, User $user): ?CircleParty
    {
        return $this->partyOf($circle, $user);
    }

    /**
     * Both signatures are in.
     *
     * Also where the network is written (spec §21.5). Recorded on agreement
     * rather than on completion: a network built only from finished work would
     * be empty for everybody's first six months. `completed_count` is what
     * separates "we agreed something once" from "we finished it", and it is
     * kept apart for exactly that reason.
     */
    private function activate(Engagement $engagement, User $actor): Engagement
    {
        $engagement->forceFill([
            'status'       => EngagementStatus::Active,
            'agreed_at'    => now(),
            'activated_at' => now(),
            'starts_at'    => $engagement->starts_at ?? now(),
        ])->save();

        if ($engagement->decision !== null) {
            $engagement->decision->forceFill([
                'status'      => DecisionStatus::Approved,
                'resolved_at' => now(),
            ])->save();
        }

        // The seats this contract pays for expire with it.
        CircleMembership::where('engagement_id', $engagement->id)
            ->update(['expires_at' => $engagement->ends_at]);

        $this->discovery->recordRelationship(
            $engagement->engagingParty?->organisation,
            $engagement->contractorParty?->organisation,
        );

        $this->audit->record(
            AuditEventType::EngagementAgreed, $engagement->circle, ActorType::User, $actor->id,
            'engagement', $engagement->id, metadata: [
                'title'      => $engagement->title,
                'engaging'   => $engagement->engagingParty?->label(),
                'contractor' => $engagement->contractorParty?->label(),
                'active'     => true,
            ],
        );

        return $engagement->fresh();
    }

    /**
     * Stop the work without ending the contract.
     *
     * Either side may suspend. A contractor who has not been paid and a client
     * who has lost confidence want the same lever, and a product that gives it
     * only to the payer is telling contractors what it thinks of them.
     */
    public function suspend(Engagement $engagement, User $actor, string $reason): Engagement
    {
        $this->gate->authorise($actor, Permission::EngagementManage, $engagement->circle);
        $this->assertParty($engagement, $actor);

        abort_unless($engagement->status === EngagementStatus::Active, 422, 'Only an active engagement can be suspended.');
        abort_if(trim($reason) === '', 422, 'Suspending work needs a reason.');

        return DB::transaction(function () use ($engagement, $actor, $reason) {
            $engagement->forceFill([
                'status'       => EngagementStatus::Suspended,
                'suspended_at' => now(),
                'end_reason'   => $reason,
            ])->save();

            $this->audit->record(
                AuditEventType::EngagementSuspended, $engagement->circle, ActorType::User, $actor->id,
                'engagement', $engagement->id, metadata: [
                    'reason' => $reason,
                    'party'  => $this->partyOf($engagement->circle, $actor)?->label(),
                ],
            );

            return $engagement->fresh();
        });
    }

    public function resume(Engagement $engagement, User $actor, ?string $note = null): Engagement
    {
        $this->gate->authorise($actor, Permission::EngagementManage, $engagement->circle);
        $this->assertParty($engagement, $actor);

        abort_unless($engagement->status === EngagementStatus::Suspended, 422, 'This engagement is not suspended.');

        return DB::transaction(function () use ($engagement, $actor, $note) {
            $engagement->forceFill([
                'status'       => EngagementStatus::Active,
                'suspended_at' => null,
                // The reason it was suspended is not cleared. It stays until
                // the engagement ends and its record is compiled, because "was
                // this stopped and restarted" is one of the few things a
                // prospective hirer would actually want to know.
            ])->save();

            $this->audit->record(
                AuditEventType::EngagementResumed, $engagement->circle, ActorType::User, $actor->id,
                'engagement', $engagement->id, metadata: ['note' => $note],
            );

            return $engagement->fresh();
        });
    }

    /**
     * The contract ran its course.
     *
     * Refuses while a deliverable is outstanding, because "completed" is the
     * word that goes on the contractor's record and the client's. Marking an
     * engagement complete over unaccepted work would let one side write the
     * other's history.
     */
    public function complete(Engagement $engagement, User $actor, ?string $note = null, bool $force = false): Engagement
    {
        $this->gate->authorise($actor, Permission::EngagementManage, $engagement->circle);
        $this->assertParty($engagement, $actor);

        abort_if($engagement->status->isEnded(), 422, 'This engagement has already ended.');

        $outstanding = $engagement->deliverables()
            ->whereNotIn('status', [CommitmentStatus::Done->value, CommitmentStatus::Cancelled->value])
            ->count();

        abort_if(
            $outstanding > 0 && ! $force,
            422,
            sprintf('%d deliverable%s still outstanding. Accept or cancel them, or terminate instead.',
                $outstanding, $outstanding === 1 ? ' is' : 's are'),
        );

        return DB::transaction(function () use ($engagement, $actor, $note) {
            $party = $this->partyOf($engagement->circle, $actor);

            $engagement->forceFill([
                'status'            => EngagementStatus::Completed,
                'ended_at'          => now(),
                'end_reason'        => $note,
                'ended_by_party_id' => $party?->id,
                'ended_by_user_id'  => $actor->id,
            ])->save();

            $this->closeOut($engagement, $actor);

            $this->discovery->recordRelationship(
                $engagement->engagingParty?->organisation,
                $engagement->contractorParty?->organisation,
                completed: true,
            );

            $this->audit->record(
                AuditEventType::EngagementCompleted, $engagement->circle, ActorType::User, $actor->id,
                'engagement', $engagement->id, metadata: [
                    'title' => $engagement->title,
                    'note'  => $note,
                    'units' => $engagement->unitsUsed(),
                ],
            );

            return $engagement->fresh();
        });
    }

    /**
     * The contract was cut short.
     *
     * Carries a reason and the party that did it, and both go on the record.
     * A termination that reads the same as a completion is worth nothing to
     * the next company deciding whether to hire this contractor — and worth
     * nothing to the contractor either, when the termination was the client's.
     */
    public function terminate(Engagement $engagement, User $actor, string $reason): Engagement
    {
        $this->gate->authorise($actor, Permission::EngagementManage, $engagement->circle);
        $this->assertParty($engagement, $actor);

        abort_if($engagement->status->isEnded(), 422, 'This engagement has already ended.');
        abort_if(trim($reason) === '', 422, 'Ending a contract early needs a reason.');

        return DB::transaction(function () use ($engagement, $actor, $reason) {
            $party = $this->partyOf($engagement->circle, $actor);

            $engagement->forceFill([
                'status'            => EngagementStatus::Terminated,
                'ended_at'          => now(),
                'end_reason'        => $reason,
                'ended_by_party_id' => $party?->id,
                'ended_by_user_id'  => $actor->id,
            ])->save();

            $this->closeOut($engagement, $actor);

            $this->audit->record(
                AuditEventType::EngagementTerminated, $engagement->circle, ActorType::User, $actor->id,
                'engagement', $engagement->id, metadata: [
                    'title'  => $engagement->title,
                    'reason' => $reason,
                    'by'     => $party?->label(),
                    'units'  => $engagement->unitsUsed(),
                ],
            );

            return $engagement->fresh();
        });
    }

    /**
     * Close the seats and leave everything else alone.
     *
     * Nothing the principal did is removed. §20.7's rule that a removal
     * abandons rather than deletes applies to people too: the commitments they
     * owned, the branches they proposed and the comments they left all stay,
     * and the record is compiled from them.
     */
    private function closeOut(Engagement $engagement, User $actor): void
    {
        CircleMembership::where('engagement_id', $engagement->id)
            ->whereNull('revoked_at')
            ->update(['expires_at' => now()]);
    }

    /**
     * Expire engagements whose term has run out.
     *
     * A convenience for the record rather than a security control: check (7)
     * already refuses work past `ends_at` on the next request, whether or not
     * this has run. What this does is move the *status* to `expired`, so an
     * engagement nobody closed reads as expired rather than as active-forever
     * — which is what a record compiled from it would otherwise say.
     *
     * @return int how many were expired
     */
    public function expireDue(): int
    {
        $due = Engagement::query()
            ->whereIn('status', [EngagementStatus::Active->value, EngagementStatus::Suspended->value])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->get();

        foreach ($due as $engagement) {
            DB::transaction(function () use ($engagement) {
                $engagement->forceFill([
                    'status'   => EngagementStatus::Expired,
                    'ended_at' => $engagement->ends_at,
                ])->save();

                CircleMembership::where('engagement_id', $engagement->id)
                    ->whereNull('revoked_at')
                    ->update(['expires_at' => $engagement->ends_at]);

                $this->audit->record(
                    AuditEventType::EngagementExpired, $engagement->circle, ActorType::System, null,
                    'engagement', $engagement->id, metadata: [
                        'title'    => $engagement->title,
                        'ended_at' => $engagement->ends_at?->format(DATE_ATOM),
                        'units'    => $engagement->unitsUsed(),
                    ],
                );
            });
        }

        return $due->count();
    }

    // ----------------------------------------------------------- the meter

    /**
     * Record one billable unit.
     *
     * `source` makes the entry derived rather than asserted — an agent action
     * or an accepted deliverable is a thing that happened; a person's hours are
     * somebody's word. The record keeps the distinction, because a hirer
     * reading "412 hours" should be able to tell which kind of number it is.
     */
    public function meter(
        Engagement $engagement,
        float $quantity = 1.0,
        ?string $note = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?User $recordedBy = null,
        ?\DateTimeInterface $occurredAt = null,
    ): ?EngagementMeterEntry {
        abort_unless($engagement->fee_basis->isMetered(), 422, sprintf(
            'This engagement is a %s and has nothing to meter.', $engagement->fee_basis->label(),
        ));

        abort_if($quantity <= 0, 422, 'A meter entry counts something.');

        // The cap is enforced here rather than trusted to whoever is watching.
        // An hourly contract with a ceiling that can be exceeded has no ceiling.
        abort_if(
            $engagement->isOverCap(),
            422,
            sprintf('This engagement is capped at %d %s and has reached it.',
                $engagement->unit_cap, $engagement->fee_basis->unit()),
        );

        $attributes = [
            'engagement_id'       => $engagement->id,
            'unit'                => $engagement->fee_basis->unit(),
            'quantity_milli'      => (int) round($quantity * 1000),
            'note'                => $note,
            'source_type'         => $sourceType,
            'source_id'           => $sourceId,
            'recorded_by_user_id' => $recordedBy?->id,
            'occurred_at'         => $occurredAt ?? now(),
        ];

        // An asserted entry is always a new row. Two hours logged on Tuesday
        // and two on Wednesday are two entries with nothing to key on, and
        // matching them against each other would silently overwrite the first
        // — Postgres treats NULLs as distinct in the unique index, but
        // `updateOrCreate` matches them with `is null` and would find it.
        if ($sourceType === null) {
            return EngagementMeterEntry::create($attributes);
        }

        // A derived entry is keyed on what it came from, so a re-run of the
        // sweep or a double-approved action bills once. Returning the existing
        // row rather than throwing keeps the caller — usually a worker — simple.
        return EngagementMeterEntry::updateOrCreate(
            [
                'engagement_id' => $engagement->id,
                'source_type'   => $sourceType,
                'source_id'     => $sourceId,
            ],
            $attributes,
        );
    }

    /**
     * Bill for an agent action that actually ran (spec §21.6).
     *
     * Executed, not proposed. An agent does not get paid for asking, and that
     * distinction only exists because §20.4 writes the proposal to the ledger
     * before anything is attempted — this reads the half that happened.
     *
     * Silent where there is nothing to bill: the overwhelming majority of
     * actions belong to agents nobody hired, and a caller in the execution path
     * should not have to know which.
     */
    public function meterAgentAction(AgentAction $action): ?EngagementMeterEntry
    {
        if ($action->status !== AgentActionStatus::Executed) {
            return null;
        }

        $engagement = Engagement::query()
            ->where('circle_id', $action->circle_id)
            ->where('principal_type', PrincipalType::AgentInstance->value)
            ->where('principal_id', $action->agent_instance_id)
            ->where('fee_basis', FeeBasis::PerAction->value)
            ->where('status', EngagementStatus::Active->value)
            ->first();

        if ($engagement === null || $engagement->isOverCap()) {
            return null;
        }

        $entry = $this->meter(
            engagement: $engagement,
            quantity: 1.0,
            note: $action->tool_key,
            sourceType: 'agent_action',
            sourceId: $action->id,
            occurredAt: $action->executed_at ?? now(),
        );

        $this->audit->record(
            AuditEventType::EngagementMetered, $engagement->circle, ActorType::Agent, $action->agent_instance_id,
            'engagement', $engagement->id, metadata: [
                'tool'  => $action->tool_key,
                'unit'  => $engagement->fee_basis->unit(),
                'total' => $engagement->fresh()->unitsUsed(),
                'cap'   => $engagement->unit_cap,
            ],
        );

        return $entry;
    }

    /**
     * Bill for an accepted deliverable.
     *
     * Accepting the commitment is what closes the fee obligation, and it is the
     * hook a payment tool would hang from if one were ever registered under the
     * `financial` classification. None is (spec §21.7).
     */
    public function meterDeliverable(Commitment $commitment): ?EngagementMeterEntry
    {
        $engagement = $commitment->engagement;

        if ($engagement === null
            || $engagement->fee_basis !== FeeBasis::PerDeliverable
            || $engagement->status !== EngagementStatus::Active
            || $commitment->status !== CommitmentStatus::Done) {
            return null;
        }

        return $this->meter(
            engagement: $engagement,
            quantity: 1.0,
            note: $commitment->title,
            sourceType: 'commitment',
            sourceId: $commitment->id,
            occurredAt: $commitment->completed_at ?? now(),
        );
    }

    // -------------------------------------------------------------- helpers

    private function partyOf(Circle $circle, User $user): ?CircleParty
    {
        $membership = CircleMembership::where('circle_id', $circle->id)
            ->where('user_id', $user->id)
            ->first();

        $partyId = $membership?->effectivePartyId();

        return $partyId === null ? null : CircleParty::find($partyId);
    }

    /** Only the two companies in the contract may move it. */
    private function assertParty(Engagement $engagement, User $actor): void
    {
        $party = $this->partyOf($engagement->circle, $actor);

        abort_unless(
            $party !== null && in_array($party->id, [$engagement->engaging_party_id, $engagement->contractor_party_id], true),
            403,
            'This engagement is between two other companies.',
        );
    }

    /** What the signatories are actually agreeing to, in prose. */
    private function decisionBody(Engagement $engagement): string
    {
        $lines = [
            sprintf('%s engages %s.', $engagement->engagingParty?->label(), $engagement->contractorParty?->label()),
            sprintf('Principal: %s.', $engagement->principalName()),
            sprintf('Fee: %s%s.',
                $engagement->fee_basis->label(),
                $engagement->fee_amount_minor === null
                    ? ''
                    : sprintf(' — %s %s', number_format($engagement->fee_amount_minor / 100, 2), $engagement->currency),
            ),
        ];

        if ($engagement->unit_cap !== null) {
            $lines[] = sprintf('Capped at %d %s.', $engagement->unit_cap, $engagement->fee_basis->unit());
        }

        $lines[] = $engagement->scope_goal_id === null
            ? 'Scope: the whole Circle.'
            : sprintf('Scope: %s and everything beneath it.', $engagement->scopeGoal?->title);

        $lines[] = sprintf('Term: %s to %s.',
            $engagement->starts_at?->toFormattedDateString() ?? 'on agreement',
            $engagement->ends_at?->toFormattedDateString() ?? 'no end date',
        );

        if ($engagement->terms !== null) {
            $lines[] = $engagement->terms;
        }

        return implode("\n", $lines);
    }
}
