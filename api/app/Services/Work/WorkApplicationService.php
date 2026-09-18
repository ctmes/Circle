<?php

namespace App\Services\Work;

use App\Enums\ActorType;
use App\Enums\ApplicationStatus;
use App\Enums\AuditEventType;
use App\Enums\CircleRole;
use App\Enums\FeeBasis;
use App\Enums\PartyRole;
use App\Enums\PartyStatus;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Models\AgentBlueprintVersion;
use App\Models\AgentInstance;
use App\Models\CircleMembership;
use App\Models\CircleParty;
use App\Models\DecisionApproval;
use App\Models\Engagement;
use App\Models\Organisation;
use App\Models\User;
use App\Models\WorkApplication;
use App\Models\WorkOpening;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use App\Services\Goals\GoalBranchService;
use Illuminate\Support\Facades\DB;

/**
 * Applying, being let in, and being awarded the work (spec §21.1).
 *
 * The sequence is the design, and each step is a separate act on purpose:
 *
 *   apply       — outside the Circle entirely. Grants nothing.
 *   shortlist   — an owner admits the applicant's company as a party and
 *                 issues a seat bound to a *proposed* engagement, which check
 *                 (7) narrows to proposing and discussing, inside the scope of
 *                 the work applied for.
 *   propose     — the applicant opens the branch that would assign them the
 *                 work, and may argue for different dates while they are at it.
 *   award       — the posting party signs. The branch merges under §20.7's
 *                 unchanged rule, and the engagement activates.
 *
 * A company that receives forty applications exposes its Circle to none of
 * them. That is the whole reason applying and being admitted are two verbs.
 */
class WorkApplicationService
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly AuditChain $audit,
        private readonly DiscoveryService $discovery,
        private readonly GoalBranchService $branches,
        private readonly EngagementService $engagements,
        private readonly WorkOpeningService $openings,
    ) {}

    /**
     * Offer to do the work.
     *
     * Not authorised through AccessGate, and it cannot be: the applicant is
     * not a member of this Circle. The opening's visibility is the only thing
     * that can answer whether they may apply, which is why `work.apply` is
     * deliberately absent from the Permission enum.
     */
    public function apply(
        WorkOpening $opening,
        User $applicant,
        Organisation $organisation,
        ?string $statement = null,
        ?FeeBasis $feeBasis = null,
        ?int $feeAmountMinor = null,
        ?string $currency = null,
        ?string $availability = null,
        ?AgentBlueprintVersion $offeredAgent = null,
    ): WorkApplication {
        abort_unless(
            $this->discovery->canSee($applicant, $opening),
            403,
            'This opening is not open to you.',
        );

        abort_unless($opening->acceptsApplications(), 422, sprintf('This opening is %s.', $opening->status->label()));

        abort_if(
            $this->discovery->isOnPostingSide($applicant, $opening),
            422,
            'You posted this opening.',
        );

        abort_unless(
            $applicant->organisationMemberships()->where('organisation_id', $organisation->id)->exists(),
            403,
            'You cannot apply on behalf of a company you do not belong to.',
        );

        // The posting says what it is looking for, and the offer has to match
        // it. Sorting this out at award time would mean a client reads forty
        // bids to find that half of them are the wrong kind of thing.
        if ($offeredAgent !== null) {
            abort_unless($opening->principal_kind->admitsAgent(), 422, sprintf(
                'This opening is looking for %s.', $opening->principal_kind->label(),
            ));

            abort_unless(
                $offeredAgent->organisation_id === $organisation->id,
                403,
                'An agent is offered by the company that authored it. Its mistakes land there.',
            );

            abort_unless(
                $offeredAgent->isListed(),
                422,
                'That version of the agent has not been published for hire.',
            );
        } else {
            abort_unless($opening->principal_kind->admitsHuman(), 422, sprintf(
                'This opening is looking for %s.', $opening->principal_kind->label(),
            ));
        }

        // One live application per company, enforced by a unique index as well
        // as here. A company that wants to change its offer narrows the one it
        // has — re-applying to dodge a refusal turns a negotiation into a queue.
        $existing = WorkApplication::where('work_opening_id', $opening->id)
            ->where('organisation_id', $organisation->id)
            ->first();

        if ($existing !== null && $existing->status !== ApplicationStatus::Withdrawn) {
            abort(422, sprintf(
                '%s has already applied for this, and that application is %s.',
                $organisation->name,
                $existing->status->label(),
            ));
        }

        return DB::transaction(function () use (
            $opening, $applicant, $organisation, $statement, $feeBasis,
            $feeAmountMinor, $currency, $availability, $offeredAgent, $existing
        ) {
            $application = WorkApplication::updateOrCreate(
                ['work_opening_id' => $opening->id, 'organisation_id' => $organisation->id],
                [
                    'applicant_user_id'          => $applicant->id,
                    'agent_blueprint_version_id' => $offeredAgent?->id,
                    'status'                     => ApplicationStatus::Submitted,
                    'fee_basis'                  => $feeBasis ?? $opening->fee_basis,
                    'fee_amount_minor'           => $feeAmountMinor ?? $opening->fee_amount_minor,
                    'currency'                   => $currency ?? $opening->currency,
                    'statement'                  => $statement,
                    'availability'               => $availability,
                    'submitted_at'               => now(),
                    // A re-application after a withdrawal starts clean. Any
                    // earlier decision belonged to a different offer.
                    'decided_at'                 => null,
                    'decision_reason'            => null,
                    'decided_by_user_id'         => null,
                ],
            );

            // Recorded against the Circle even though the applicant is not in
            // it. The posting party's chain is where this belongs: it is their
            // hiring decision, and the packet should show who was considered.
            $this->audit->record(
                AuditEventType::ApplicationSubmitted, $opening->circle, ActorType::User, $applicant->id,
                'work_application', $application->id, metadata: [
                    'opening'      => $opening->title,
                    'organisation' => $organisation->name,
                    'kind'         => $offeredAgent === null ? 'human' : 'agent',
                    'agent'        => $offeredAgent?->name,
                    'reapplied'    => $existing !== null,
                ],
            );

            return $application->fresh();
        });
    }

    public function withdraw(WorkApplication $application, User $actor, ?string $reason = null): WorkApplication
    {
        abort_unless(
            $actor->organisationMemberships()->where('organisation_id', $application->organisation_id)->exists(),
            403,
            'This application belongs to another company.',
        );

        abort_unless($application->status->isLive(), 422, sprintf(
            'This application is already %s.', $application->status->label(),
        ));

        return DB::transaction(function () use ($application, $actor, $reason) {
            $application->forceFill([
                'status'          => ApplicationStatus::Withdrawn,
                'decided_at'      => now(),
                'decision_reason' => $reason,
            ])->save();

            // A withdrawal takes the access back with it. Leaving a shortlisted
            // applicant inside a Circle they have walked away from is exactly
            // the drift the admission sequence exists to prevent.
            $this->revokeSeats($application, 'the application was withdrawn');

            $this->audit->record(
                AuditEventType::ApplicationWithdrawn, $application->opening->circle, ActorType::User, $actor->id,
                'work_application', $application->id, metadata: [
                    'organisation' => $application->organisation?->name,
                    'reason'       => $reason,
                ],
            );

            return $application->fresh();
        });
    }

    /**
     * Let them in.
     *
     * The moment access changes hands, and the reason it is a verb of its own.
     * Four things happen together, and none of them makes sense without the
     * others: the applicant's company becomes a party, a contract is proposed,
     * a seat is issued against that contract, and a branch is opened for the
     * assignment.
     *
     * The contract is created here rather than at award because it is what
     * *bounds* the access being granted: check (7) reads a proposed engagement
     * as "may propose and discuss, within this scope, and nothing else". Grant
     * the seat first and add the contract later, and there is a window in which
     * a shortlisted stranger is an ordinary contributor.
     */
    public function shortlist(WorkApplication $application, User $actor, ?string $note = null): WorkApplication
    {
        $opening = $application->opening;

        $this->gate->authorise($actor, Permission::WorkAward, $opening->circle, subject: $opening->goal);
        $this->assertPostingSide($opening, $actor);

        abort_unless(
            $application->status === ApplicationStatus::Submitted,
            422,
            sprintf('This application is %s.', $application->status->label()),
        );

        abort_unless(
            $opening->circle->acceptsContributions(),
            422,
            'This Circle is no longer accepting contributions.',
        );

        return DB::transaction(function () use ($application, $opening, $actor, $note) {
            $party    = $this->admitParty($application, $opening, $actor);
            $engaging = $opening->postedByParty;

            $principal = $this->resolvePrincipal($application, $opening, $party);

            $engagement = $this->engagements->propose(
                circle: $opening->circle,
                actor: $actor,
                engaging: $engaging,
                contractor: $party,
                principalType: $principal['type'],
                principalId: $principal['id'],
                title: $opening->title,
                scope: $opening->goal,
                feeBasis: $application->fee_basis ?? $opening->fee_basis,
                feeAmountMinor: $application->fee_amount_minor ?? $opening->fee_amount_minor,
                currency: $application->currency ?? $opening->currency,
                unitCap: $opening->estimated_units,
                startsAt: $opening->term_starts_at,
                endsAt: $opening->term_ends_at,
                terms: $application->statement,
                openingId: $opening->id,
                blueprintVersionId: $application->agent_blueprint_version_id,
            );

            // The seat. Contributor rather than viewer, because check (7) is
            // what narrows it — and narrowing at the gate rather than at the
            // role means awarding the work widens their access by changing one
            // status rather than by re-issuing a membership.
            $this->engagements->issueSeat($engagement, $application->applicant, CircleRole::Contributor);

            $branch = $this->openAssignmentBranch($application, $opening, $party, $engagement);

            $application->forceFill([
                'status'            => ApplicationStatus::Shortlisted,
                'admitted_party_id' => $party->id,
                'goal_branch_id'    => $branch?->id,
                'shortlisted_at'    => now(),
                'decided_by_user_id' => $actor->id,
                'decision_reason'   => $note,
            ])->save();

            $this->audit->record(
                AuditEventType::ApplicationShortlisted, $opening->circle, ActorType::User, $actor->id,
                'work_application', $application->id, metadata: [
                    'organisation' => $application->organisation?->name,
                    'party'        => $party->label(),
                    'engagement'   => $engagement->id,
                    'branch'       => $branch?->id,
                    'scope'        => $opening->goal?->title,
                    'note'         => $note,
                ],
            );

            return $application->fresh();
        });
    }

    public function decline(WorkApplication $application, User $actor, ?string $reason = null): WorkApplication
    {
        $opening = $application->opening;

        $this->gate->authorise($actor, Permission::WorkAward, $opening->circle, subject: $opening->goal);
        $this->assertPostingSide($opening, $actor);

        abort_unless($application->status->isLive(), 422, sprintf(
            'This application is already %s.', $application->status->label(),
        ));

        return DB::transaction(function () use ($application, $opening, $actor, $reason) {
            $application->forceFill([
                'status'             => ApplicationStatus::Declined,
                'decided_at'         => now(),
                'decision_reason'    => $reason,
                'decided_by_user_id' => $actor->id,
            ])->save();

            // Declining a shortlisted applicant takes the access back. The
            // party row stays — they were genuinely here, and the packet
            // should say so — but the seat and the proposed contract do not.
            $this->revokeSeats($application, $reason ?? 'the application was declined');

            $this->audit->record(
                AuditEventType::ApplicationDeclined, $opening->circle, ActorType::User, $actor->id,
                'work_application', $application->id, metadata: [
                    'organisation' => $application->organisation?->name,
                    'reason'       => $reason,
                ],
            );

            return $application->fresh();
        });
    }

    /**
     * Award the work.
     *
     * Two signatures land here, and only one of them is new.
     *
     * The applicant's is already in: they wrote the offer, named their fee and
     * proposed the branch. Recording that as their party's agreement is not a
     * shortcut — it is what an application *is*, and asking a contractor to
     * press "I agree to my own bid" would be theatre. The approval row carries
     * the application id so the packet can show what was signed.
     *
     * The posting party's is given here, by an owner, and it is the one that
     * matters. §20.7's merge rule is untouched: the branch lands only when
     * every affected party has signed, which is why there is no separate
     * "accept" that could assign work nobody agreed to.
     */
    public function award(WorkApplication $application, User $actor, ?string $comment = null): Engagement
    {
        $opening = $application->opening;

        $this->gate->authorise($actor, Permission::WorkAward, $opening->circle, subject: $opening->goal);
        $this->assertPostingSide($opening, $actor);

        abort_unless(
            $application->status === ApplicationStatus::Shortlisted,
            422,
            'Only a shortlisted application can be awarded. Shortlist it first — that is what lets them in.',
        );

        $engagement = Engagement::where('work_opening_id', $opening->id)
            ->where('contractor_party_id', $application->admitted_party_id)
            ->firstOrFail();

        $branch = $application->branch;

        // A draft branch has not been offered yet. Awarding one would merge a
        // proposal the applicant is still writing — and the applicant's
        // signature on it would be a signature on something they never sent.
        abort_if(
            $branch !== null && $branch->isDraft(),
            422,
            sprintf('%s has not proposed their assignment branch yet.', $application->organisation?->name ?? 'The applicant'),
        );

        $engagingParty = $opening->postedByParty;

        abort_if($engagingParty === null, 422, 'The party that posted this opening no longer exists.');

        return DB::transaction(function () use ($application, $opening, $actor, $branch, $engagement, $comment, $engagingParty) {
            if ($branch !== null && $branch->isOpen()) {
                // The applicant's side, recorded from the offer they made. An
                // application *is* an agreement to the assignment it proposes;
                // asking a contractor to press "I agree to my own bid" would
                // be a step that always succeeds and proves nothing.
                DecisionApproval::updateOrCreate(
                    ['decision_id' => $branch->decision_id, 'actor_user_id' => $application->applicant_user_id],
                    [
                        'circle_party_id' => $application->admitted_party_id,
                        'outcome'         => 'approved',
                        'subject_type'    => 'goal_branch',
                        'subject_id'      => $branch->id,
                        'comment'         => sprintf('Offered in application %s.', $application->id),
                        'occurred_at'     => $application->submitted_at ?? now(),
                    ],
                );

                // The posting party's side, recorded for the packet. It is not
                // what makes the merge legal — §20.7 asks for the signature of
                // every party the diff *lands on*, and an unassigned goal lands
                // only on the applicant — but awarding is the client's act and
                // the record should carry their name on it.
                DecisionApproval::updateOrCreate(
                    ['decision_id' => $branch->decision_id, 'actor_user_id' => $actor->id],
                    [
                        'circle_party_id' => $engagingParty->id,
                        'outcome'         => 'approved',
                        'subject_type'    => 'goal_branch',
                        'subject_id'      => $branch->id,
                        'comment'         => $comment ?? 'Awarded.',
                        'occurred_at'     => now(),
                    ],
                );

                // merge() refuses on a conflict, which is exactly the check
                // that should stop an award made against a plan that moved
                // while the bids were coming in.
                $this->branches->merge($branch->fresh(), $actor);
            }

            // The contract is signed separately from the assignment: one is the
            // plan changing, the other is the terms. Both signatures go in here
            // — the client's by awarding, the contractor's from the offer they
            // made — and the second one activates the engagement.
            $this->engagements->signFor($engagement, $engagingParty, $actor, $comment ?? 'Awarded.');

            $this->engagements->signFor(
                $engagement->fresh(),
                $engagement->contractorParty,
                $application->applicant,
                sprintf('Terms offered in application %s.', $application->id),
                $application->submitted_at,
            );

            $application->forceFill([
                'status'             => ApplicationStatus::Awarded,
                'decided_at'         => now(),
                'decided_by_user_id' => $actor->id,
            ])->save();

            // Everyone else is told the outcome rather than left waiting.
            $others = WorkApplication::where('work_opening_id', $opening->id)
                ->where('id', '!=', $application->id)
                ->whereIn('status', [ApplicationStatus::Submitted->value, ApplicationStatus::Shortlisted->value])
                ->get();

            foreach ($others as $other) {
                $other->forceFill([
                    'status'          => ApplicationStatus::Declined,
                    'decided_at'      => now(),
                    'decision_reason' => 'The work was awarded to another applicant.',
                ])->save();

                $this->revokeSeats($other, 'the work was awarded elsewhere');
            }

            $this->openings->markFilled($opening, $actor);

            $this->audit->record(
                AuditEventType::ApplicationAwarded, $opening->circle, ActorType::User, $actor->id,
                'work_application', $application->id, metadata: [
                    'organisation'   => $application->organisation?->name,
                    'engagement'     => $engagement->id,
                    'branch_merged'  => $branch?->fresh()?->status === 'merged',
                    'others_declined' => $others->count(),
                ],
            );

            return $engagement->fresh();
        });
    }

    // -------------------------------------------------------------- helpers

    /**
     * Admit the applicant's company as a party.
     *
     * `firstOrNew` on the organisation rather than a blind create: a company
     * already in this Circle for other work does not get a second party row.
     * Two party rows for one company would let a scope allow what a deny on
     * the other row was written to refuse.
     */
    private function admitParty(WorkApplication $application, WorkOpening $opening, User $actor): CircleParty
    {
        $existing = CircleParty::where('circle_id', $opening->circle_id)
            ->where('organisation_id', $application->organisation_id)
            ->first();

        if ($existing !== null) {
            if (! $existing->isActive()) {
                $existing->forceFill([
                    'status'       => PartyStatus::Active,
                    'withdrawn_at' => null,
                ])->save();
            }

            return $existing;
        }

        $party = CircleParty::create([
            'circle_id'          => $opening->circle_id,
            'organisation_id'    => $application->organisation_id,
            'display_name'       => $application->organisation?->name ?? 'Contractor',
            // A commercial position, not a permission set. The posting party's
            // own position decides whether this company is a contractor to the
            // principal or a subcontractor to a contractor — which is the
            // distinction that makes a four-deep tree read correctly.
            'party_role'         => $opening->postedByParty?->party_role === PartyRole::Contractor
                ? PartyRole::Subcontractor
                : PartyRole::Contractor,
            'status'             => PartyStatus::Active,
            'is_convener'        => false,
            'invited_by_user_id' => $actor->id,
            'joined_at'          => now(),
        ]);

        $this->audit->record(
            AuditEventType::PartyJoined, $opening->circle, ActorType::User, $actor->id,
            'circle_party', $party->id, metadata: [
                'organisation' => $party->label(),
                'via'          => 'work_application',
                'opening'      => $opening->title,
            ],
        );

        return $party;
    }

    /**
     * Who or what will actually do the work.
     *
     * For an agent this instantiates the hired *version* into this Circle —
     * an instance is a blueprint bound to one Circle and is the identity
     * AccessGate authorises. The blueprint stays with its author's
     * organisation, which is what keeps §20.4's liability rule intact: an
     * agent acts for the company that wrote it, and hiring it does not move
     * that.
     *
     * @return array{type: PrincipalType, id: string}
     */
    private function resolvePrincipal(WorkApplication $application, WorkOpening $opening, CircleParty $party): array
    {
        if (! $application->isAgentApplication()) {
            return ['type' => PrincipalType::User, 'id' => $application->applicant_user_id];
        }

        $version = $application->blueprintVersion;

        abort_if($version === null, 422, 'The agent version offered in this application no longer exists.');

        $instance = AgentInstance::firstOrCreate(
            [
                'agent_blueprint_id' => $version->agent_blueprint_id,
                'circle_id'          => $opening->circle_id,
            ],
            ['status' => 'active'],
        );

        $this->audit->record(
            AuditEventType::AgentInstantiated, $opening->circle, ActorType::User, $application->applicant_user_id,
            'agent_instance', $instance->id, metadata: [
                'blueprint' => $version->name,
                'version'   => $version->version_number,
                'hash'      => $version->content_hash,
                'for_party' => $party->label(),
                'via'       => 'work_application',
            ],
        );

        return ['type' => PrincipalType::AgentInstance, 'id' => $instance->id];
    }

    /**
     * Draft the branch that would assign the work.
     *
     * Authored by the applicant, not by the person shortlisting them: it is the
     * applicant's proposal, and the record should say so. They may add their
     * own changes to it — different dates, a sub-goal they think is missing —
     * before proposing it, which is the point of applying being a branch at all.
     *
     * Null where the opening has no goal. A Circle that posted work before it
     * drew a plan has nothing to assign yet, and the engagement is the whole
     * agreement in that case.
     */
    private function openAssignmentBranch(
        WorkApplication $application,
        WorkOpening $opening,
        CircleParty $party,
        Engagement $engagement,
    ): ?\App\Models\GoalBranch {
        if ($opening->goal === null) {
            return null;
        }

        $branch = $this->branches->open(
            circle: $opening->circle,
            author: $application->applicant,
            name: sprintf('Assign "%s" to %s', $opening->title, $party->label()),
            intent: sprintf(
                'Proposed in response to the opening "%s". %s',
                $opening->title,
                $application->statement ?? '',
            ),
        );

        $this->branches->stage(
            branch: $branch,
            author: $application->applicant,
            changeType: 'update',
            goal: $opening->goal,
            attributes: ['responsible_party_id' => $party->id],
        );

        return $branch->fresh();
    }

    /**
     * Take back a seat that was issued against an application.
     *
     * The party row stays and so does everything the applicant wrote. Only the
     * access goes — §20.7's rule that a removal abandons rather than deletes,
     * applied to people.
     */
    private function revokeSeats(WorkApplication $application, string $why): void
    {
        if ($application->admitted_party_id === null) {
            return;
        }

        $engagements = Engagement::where('work_opening_id', $application->work_opening_id)
            ->where('contractor_party_id', $application->admitted_party_id)
            ->where('status', \App\Enums\EngagementStatus::Proposed->value)
            ->get();

        foreach ($engagements as $engagement) {
            $engagement->forceFill([
                'status'     => \App\Enums\EngagementStatus::Terminated,
                'ended_at'   => now(),
                'end_reason' => $why,
            ])->save();

            CircleMembership::where('engagement_id', $engagement->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }
    }

    private function assertPostingSide(WorkOpening $opening, User $actor): void
    {
        abort_unless(
            $this->discovery->isOnPostingSide($actor, $opening),
            403,
            sprintf('This opening belongs to %s.', $opening->postedByParty?->label() ?? 'another company'),
        );
    }
}
