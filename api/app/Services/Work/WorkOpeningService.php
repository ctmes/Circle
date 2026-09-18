<?php

namespace App\Services\Work;

use App\Enums\ActorType;
use App\Enums\ApplicationStatus;
use App\Enums\AuditEventType;
use App\Enums\FeeBasis;
use App\Enums\OpeningStatus;
use App\Enums\Permission;
use App\Enums\PrincipalKind;
use App\Enums\WorkVisibility;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\CircleParty;
use App\Models\Goal;
use App\Models\User;
use App\Models\WorkOpening;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use Illuminate\Support\Facades\DB;

/**
 * Posting work nobody has been found for (spec §21.1).
 *
 * The rule that shapes this class: an opening may reach outside the Circle,
 * and nothing else may. Every method here either writes a posting or changes
 * its state; none of them reads the tree on an outsider's behalf, and the one
 * that renders an opening for an outsider renders WorkOpening::publicView().
 */
class WorkOpeningService
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly AuditChain $audit,
        private readonly DiscoveryService $discovery,
    ) {}

    /**
     * Draft a posting.
     *
     * Created at `draft` rather than `open`. A posting is the first thing in
     * this product a stranger sees, and one that went live the instant
     * somebody typed a title would be published half-written — the visibility
     * decision in particular deserves a second look before it reaches anyone.
     */
    public function post(
        Circle $circle,
        User $author,
        string $title,
        ?Goal $goal = null,
        ?string $brief = null,
        ?string $acceptanceCondition = null,
        PrincipalKind $principalKind = PrincipalKind::Either,
        WorkVisibility $visibility = WorkVisibility::Network,
        FeeBasis $feeBasis = FeeBasis::Fixed,
        ?int $feeAmountMinor = null,
        string $currency = 'AUD',
        ?int $estimatedUnits = null,
        ?\DateTimeInterface $termStartsAt = null,
        ?\DateTimeInterface $termEndsAt = null,
        ?\DateTimeInterface $closesAt = null,
    ): WorkOpening {
        $this->gate->authorise($author, Permission::WorkPost, $circle, subject: $goal);

        $party = $this->partyFor($circle, $author);

        if ($goal !== null) {
            abort_unless($goal->circle_id === $circle->id, 422, 'That work is in another Circle.');

            // Posting work that already has somebody answerable for it is
            // either a mistake or a re-tender, and the two need different
            // conversations. Refusing here forces the second one to start by
            // releasing the party that currently holds it.
            abort_if(
                $goal->responsible_party_id !== null,
                422,
                sprintf('%s is already the responsible party for that work.', $goal->responsibleParty?->label() ?? 'Another company'),
            );
        }

        return DB::transaction(function () use (
            $circle, $author, $party, $title, $goal, $brief, $acceptanceCondition,
            $principalKind, $visibility, $feeBasis, $feeAmountMinor, $currency,
            $estimatedUnits, $termStartsAt, $termEndsAt, $closesAt
        ) {
            $opening = WorkOpening::create([
                'circle_id'            => $circle->id,
                'posted_by_party_id'   => $party->id,
                'created_by_user_id'   => $author->id,
                'goal_id'              => $goal?->id,
                'title'                => $title,
                'brief'                => $brief,
                'acceptance_condition' => $acceptanceCondition ?? $goal?->acceptance_condition,
                'principal_kind'       => $principalKind,
                'status'               => OpeningStatus::Draft,
                'visibility'           => $visibility,
                'fee_basis'            => $feeBasis,
                'fee_amount_minor'     => $feeAmountMinor,
                'currency'             => $currency,
                'estimated_units'      => $estimatedUnits,
                'term_starts_at'       => $termStartsAt,
                'term_ends_at'         => $termEndsAt,
                'closes_at'            => $closesAt,
            ]);

            $this->audit->record(
                AuditEventType::OpeningPosted, $circle, ActorType::User, $author->id,
                'work_opening', $opening->id, metadata: [
                    'title'      => $title,
                    'party'      => $party->label(),
                    'visibility' => $visibility->value,
                    'kind'       => $principalKind->value,
                    'goal'       => $goal?->title,
                ],
            );

            return $opening;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(WorkOpening $opening, User $actor, array $attributes): WorkOpening
    {
        $this->gate->authorise($actor, Permission::WorkPost, $opening->circle, subject: $opening->goal);
        $this->assertPostingSide($opening, $actor);

        abort_if(
            in_array($opening->status, [OpeningStatus::Filled, OpeningStatus::Withdrawn], true),
            422,
            'This opening has been settled and cannot be edited.',
        );

        $editable = array_intersect_key($attributes, array_flip([
            'title', 'brief', 'acceptance_condition', 'principal_kind', 'visibility',
            'fee_basis', 'fee_amount_minor', 'currency', 'estimated_units',
            'term_starts_at', 'term_ends_at', 'closes_at',
        ]));

        abort_if($editable === [], 422, 'That change would alter nothing.');

        // Narrowing visibility after people have applied is allowed; widening
        // it is not. An applicant who bid on the understanding that four
        // companies could see the posting should not discover afterwards that
        // it went public — and their bid is already in.
        if (isset($editable['visibility']) && $opening->applications()->where('status', '!=', ApplicationStatus::Withdrawn->value)->exists()) {
            $proposed = $editable['visibility'] instanceof WorkVisibility
                ? $editable['visibility']
                : WorkVisibility::from($editable['visibility']);

            abort_if(
                $proposed->width() > $opening->visibility->width(),
                422,
                'Applications are already in. Visibility can be narrowed from here, not widened.',
            );
        }

        return DB::transaction(function () use ($opening, $actor, $editable) {
            $before = $opening->only(array_keys($editable));

            $opening->fill($editable)->save();

            $this->audit->record(
                AuditEventType::OpeningUpdated, $opening->circle, ActorType::User, $actor->id,
                'work_opening', $opening->id, metadata: [
                    'changed' => array_keys($editable),
                    'before'  => $before,
                ],
            );

            return $opening->fresh();
        });
    }

    /** Make a draft live. */
    public function publish(WorkOpening $opening, User $actor): WorkOpening
    {
        $this->gate->authorise($actor, Permission::WorkPost, $opening->circle, subject: $opening->goal);
        $this->assertPostingSide($opening, $actor);

        abort_unless($opening->status === OpeningStatus::Draft, 422, 'This opening is not a draft.');
        abort_if($opening->title === '', 422, 'An opening needs a title.');

        // A Circle that is closed, expired or not accepting contributions
        // cannot honour anything somebody applies for. The gate would refuse
        // the eventual award; refusing the posting is the same answer given
        // before anybody wastes a bid on it.
        abort_unless(
            $opening->circle->acceptsContributions(),
            422,
            'This Circle is not accepting contributions, so work posted from it could not be taken up.',
        );

        return DB::transaction(function () use ($opening, $actor) {
            $opening->forceFill([
                'status'    => OpeningStatus::Open,
                'posted_at' => now(),
            ])->save();

            $this->audit->record(
                AuditEventType::OpeningPosted, $opening->circle, ActorType::User, $actor->id,
                'work_opening', $opening->id, metadata: [
                    'title'      => $opening->title,
                    'visibility' => $opening->visibility->value,
                    'reach'      => $this->reachSummary($opening),
                    'live'       => true,
                ],
            );

            return $opening->fresh();
        });
    }

    /**
     * Stop taking applications without filling the role.
     *
     * Distinct from `filled` because "we stopped looking" and "we found
     * somebody" are different answers to give the people who applied, and an
     * opening that cannot tell them apart teaches them not to apply next time.
     */
    public function close(WorkOpening $opening, User $actor, ?string $reason = null): WorkOpening
    {
        $this->gate->authorise($actor, Permission::WorkPost, $opening->circle, subject: $opening->goal);
        $this->assertPostingSide($opening, $actor);

        abort_unless(
            in_array($opening->status, [OpeningStatus::Draft, OpeningStatus::Open], true),
            422,
            'This opening is already settled.',
        );

        return DB::transaction(function () use ($opening, $actor, $reason) {
            $opening->forceFill([
                'status'    => OpeningStatus::Closed,
                'closes_at' => $opening->closes_at ?? now(),
            ])->save();

            // Everyone still in the running is told, in the record if nowhere
            // else. §20.5's missing notification transport means nothing
            // leaves the browser — but a live application against a closed
            // opening is a state that should not survive the closing.
            $declined = $opening->applications()
                ->whereIn('status', [ApplicationStatus::Submitted->value, ApplicationStatus::Shortlisted->value])
                ->update([
                    'status'          => ApplicationStatus::Declined->value,
                    'decided_at'      => now(),
                    'decision_reason' => $reason ?? 'The opening was closed.',
                ]);

            $this->audit->record(
                AuditEventType::OpeningClosed, $opening->circle, ActorType::User, $actor->id,
                'work_opening', $opening->id, metadata: [
                    'title'             => $opening->title,
                    'reason'            => $reason,
                    'applicants_closed' => $declined,
                ],
            );

            return $opening->fresh();
        });
    }

    public function withdraw(WorkOpening $opening, User $actor, ?string $reason = null): WorkOpening
    {
        $opening = $this->close($opening, $actor, $reason ?? 'The opening was withdrawn.');

        $opening->forceFill(['status' => OpeningStatus::Withdrawn])->save();

        return $opening->fresh();
    }

    /**
     * Mark an opening filled. Called by WorkApplicationService::award(), not
     * by a controller — an opening becomes filled *because* somebody was
     * awarded it, and letting the two be set independently is how a posting
     * ends up filled by nobody.
     */
    public function markFilled(WorkOpening $opening, User $actor): WorkOpening
    {
        $opening->forceFill([
            'status'    => OpeningStatus::Filled,
            'filled_at' => now(),
        ])->save();

        $this->audit->record(
            AuditEventType::OpeningFilled, $opening->circle, ActorType::User, $actor->id,
            'work_opening', $opening->id, metadata: ['title' => $opening->title],
        );

        return $opening->fresh();
    }

    /**
     * How many companies this opening can currently reach.
     *
     * Recorded on publication so the packet can say what "network" meant on
     * the day, rather than what it means now. A posting seen by two companies
     * and one seen by two hundred are different acts, and the number changes
     * underneath the record every time somebody signs a contract.
     */
    public function reachSummary(WorkOpening $opening): array
    {
        $organisationId = $opening->postedByParty?->organisation_id;

        return match ($opening->visibility) {
            WorkVisibility::Party  => ['scope' => 'party', 'organisations' => 1],
            WorkVisibility::Circle => [
                'scope'   => 'circle',
                'parties' => CircleParty::where('circle_id', $opening->circle_id)->count(),
            ],
            WorkVisibility::Network => [
                'scope'         => 'network',
                'organisations' => $organisationId === null
                    ? 0
                    : \App\Models\OrganisationRelationship::query()
                        ->where('organisation_a_id', $organisationId)
                        ->orWhere('organisation_b_id', $organisationId)
                        ->count(),
            ],
            WorkVisibility::Public => ['scope' => 'public'],
        };
    }

    /**
     * An opening as an outsider sees it, with the reason they can see it.
     *
     * "You have worked with them twice" is a materially different invitation
     * from a job-board listing, and it is the whole argument for `network`
     * being the default.
     *
     * @return array<string, mixed>
     */
    public function viewFor(WorkOpening $opening, User $viewer): array
    {
        $view = $opening->publicView();

        $relationship = $this->discovery->relationshipBetween(
            $opening->postedByParty?->organisation_id,
            $this->discovery->organisationIdsFor($viewer)->first(),
        );

        $view['visibility']   = $opening->visibility->value;
        $view['why_visible']  = match (true) {
            $this->discovery->isOnPostingSide($viewer, $opening) => 'You posted this.',
            $relationship !== null                               => sprintf(
                'You have been engaged with %s %d time%s.',
                $opening->postedByParty?->label() ?? 'this company',
                $relationship->engagements_count,
                $relationship->engagements_count === 1 ? '' : 's',
            ),
            $opening->visibility === WorkVisibility::Public      => 'This opening is open to anyone signed in.',
            default                                              => 'You are a member of the Circle this was posted from.',
        };
        $view['can_apply'] = $opening->acceptsApplications() && ! $this->discovery->isOnPostingSide($viewer, $opening);

        return $view;
    }

    // -------------------------------------------------------------- helpers

    /**
     * The party this person posts on behalf of.
     *
     * effectivePartyId() rather than the raw column, so the convener's own
     * staff — who often have no party row — post as the convener rather than
     * as nobody. An opening with no posting party has nobody to pay for it.
     */
    private function partyFor(Circle $circle, User $user): CircleParty
    {
        $membership = CircleMembership::where('circle_id', $circle->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $partyId = $membership->effectivePartyId();

        abort_if(
            $partyId === null,
            422,
            'This Circle has no parties yet, so there is nobody for an opening to be posted by.',
        );

        return CircleParty::findOrFail($partyId);
    }

    /**
     * Only the company that posted it may change it.
     *
     * The gate has already established that this person may post work in this
     * Circle. It has not established that they may edit *another company's*
     * posting, and `work.post` on a convener would otherwise mean exactly that.
     */
    private function assertPostingSide(WorkOpening $opening, User $actor): void
    {
        abort_unless(
            $this->discovery->isOnPostingSide($actor, $opening),
            403,
            sprintf('This opening belongs to %s.', $opening->postedByParty?->label() ?? 'another company'),
        );
    }
}
