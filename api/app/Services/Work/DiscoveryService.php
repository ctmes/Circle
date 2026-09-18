<?php

namespace App\Services\Work;

use App\Enums\OpeningStatus;
use App\Enums\WorkVisibility;
use App\Models\CircleMembership;
use App\Models\Engagement;
use App\Models\Organisation;
use App\Models\OrganisationRelationship;
use App\Models\User;
use App\Models\WorkOpening;
use Illuminate\Support\Collection;

/**
 * Who may see which openings (spec §21.5).
 *
 * This is the one place in the product that answers a question without a
 * Circle in hand, and the reason it is a service of its own rather than a
 * scope on a model: the exception to §15 should live somewhere a reader can
 * find and audit in full, not be spread across four controllers.
 *
 * It answers exactly one question — which openings — and returns openings.
 * Nothing here can be pointed at a goal, a thread or an evidence item, so
 * there is no widening of it that does not require writing a new class.
 */
class DiscoveryService
{
    /**
     * The organisations this user belongs to.
     *
     * @return Collection<int, string>
     */
    public function organisationIdsFor(User $user): Collection
    {
        return $user->organisationMemberships()->pluck('organisation_id')->unique()->values();
    }

    /**
     * Everyone this user's companies have completed work with.
     *
     * Derived from `organisation_relationships`, which EngagementService
     * writes when a contract is agreed. A network you can add yourself to by
     * declaring it would be worth nothing, so there is no writer here.
     *
     * @return Collection<int, string>
     */
    public function networkIdsFor(User $user): Collection
    {
        $mine = $this->organisationIdsFor($user);

        if ($mine->isEmpty()) {
            return collect();
        }

        $asA = OrganisationRelationship::whereIn('organisation_a_id', $mine)->pluck('organisation_b_id');
        $asB = OrganisationRelationship::whereIn('organisation_b_id', $mine)->pluck('organisation_a_id');

        // Own companies included: an opening posted by one arm of a group is
        // visible to another, and excluding them would make the narrowest
        // case — a company posting to itself — the one that fails.
        return $asA->concat($asB)->concat($mine)->unique()->values();
    }

    /**
     * The companies in the network that are not this user's own.
     *
     * networkIdsFor() deliberately includes the user's own organisations, so
     * that a company posting to itself is not the one case visibility fails
     * on. That makes it the wrong number to *show* somebody: an empty network
     * would read as one. This is the number a person means when they ask how
     * many companies they have worked with.
     *
     * @return Collection<int, string>
     */
    public function counterpartyIdsFor(User $user): Collection
    {
        $mine = $this->organisationIdsFor($user);

        return $this->networkIdsFor($user)->reject(fn (string $id) => $mine->contains($id))->values();
    }

    /**
     * Openings this user may see, newest first.
     *
     * The four visibilities are evaluated as one query rather than four
     * filtered passes, because an opening the user can see two ways is still
     * one opening and a union would have to dedupe it.
     *
     * @return \Illuminate\Database\Eloquent\Builder<WorkOpening>
     */
    public function openingsFor(User $user, bool $includeFilled = false)
    {
        $circleIds = $user->circleMemberships()
            ->whereNull('revoked_at')
            ->where('invite_status', 'active')
            ->pluck('circle_id');

        $partyIds = CircleMembership::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->whereNotNull('circle_party_id')
            ->pluck('circle_party_id');

        $network = $this->networkIdsFor($user);

        $statuses = $includeFilled
            ? [OpeningStatus::Open->value, OpeningStatus::Filled->value]
            : [OpeningStatus::Open->value];

        return WorkOpening::query()
            ->whereIn('status', $statuses)
            ->where(function ($q) use ($circleIds, $partyIds, $network) {
                // Anyone signed in. Opt-in per opening, never the default.
                $q->where('visibility', WorkVisibility::Public->value);

                // Companies we have finished work with. `whereHas` on the
                // posting party's organisation rather than a join, because a
                // party may be named before its organisation exists (§20.1)
                // and such a party has no network at all.
                $q->orWhere(fn ($w) => $w
                    ->where('visibility', WorkVisibility::Network->value)
                    ->whereHas('postedByParty', fn ($p) => $p->whereIn('organisation_id', $network)));

                $q->orWhere(fn ($w) => $w
                    ->where('visibility', WorkVisibility::Circle->value)
                    ->whereIn('circle_id', $circleIds));

                $q->orWhere(fn ($w) => $w
                    ->where('visibility', WorkVisibility::Party->value)
                    ->whereIn('posted_by_party_id', $partyIds));
            })
            // An opening past its own closing date is not discoverable, even
            // at `open`. Nothing sweeps the moment a clock ticks over, and an
            // applicant who writes a bid for a posting that shut yesterday
            // will not write a second one.
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now()))
            ->with(['postedByParty.organisation', 'goal', 'circle'])
            ->latest('posted_at');
    }

    /**
     * Whether one specific opening is visible to this user.
     *
     * Deliberately implemented as a query against openingsFor() rather than as
     * a second copy of the rules. Two implementations of a visibility rule
     * disagree eventually, and the one that disagrees generously is the one
     * that leaks.
     */
    public function canSee(User $user, WorkOpening $opening): bool
    {
        // The posting party always sees its own, whatever the status — a draft
        // is invisible to everyone else and has to be editable by its author.
        if ($this->isOnPostingSide($user, $opening)) {
            return true;
        }

        return $this->openingsFor($user, includeFilled: true)
            ->where('work_openings.id', $opening->id)
            ->exists();
    }

    /** Whether this user sits with the company that posted the opening. */
    public function isOnPostingSide(User $user, WorkOpening $opening): bool
    {
        $membership = CircleMembership::query()
            ->where('circle_id', $opening->circle_id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->first();

        return $membership !== null
            && $membership->effectivePartyId() === $opening->posted_by_party_id;
    }

    /**
     * Record that two companies have worked together (spec §21.5).
     *
     * Called by EngagementService when a contract is agreed rather than when
     * it completes: a network built only from completed work would be empty
     * for everybody's first six months, and an engagement that was agreed and
     * then terminated is still a relationship — `completed_count` is what
     * distinguishes them, and it is kept separately for that reason.
     */
    public function recordRelationship(?Organisation $one, ?Organisation $other, bool $completed = false): ?OrganisationRelationship
    {
        if ($one === null || $other === null || $one->id === $other->id) {
            return null;
        }

        [$a, $b] = OrganisationRelationship::pair($one->id, $other->id);

        $relationship = OrganisationRelationship::firstOrNew([
            'organisation_a_id' => $a,
            'organisation_b_id' => $b,
        ]);

        $relationship->first_engaged_at ??= now();
        $relationship->last_engaged_at    = now();
        $relationship->engagements_count  = ($relationship->engagements_count ?? 0) + ($completed ? 0 : 1);
        $relationship->completed_count    = ($relationship->completed_count ?? 0) + ($completed ? 1 : 0);
        $relationship->save();

        return $relationship;
    }

    /**
     * The relationship between two companies, if there is one.
     *
     * Used to tell an applicant why they can see a posting — "you have worked
     * with them twice" is a materially different invitation from a job board
     * listing, and the difference is worth showing.
     */
    public function relationshipBetween(?string $oneId, ?string $otherId): ?OrganisationRelationship
    {
        if ($oneId === null || $otherId === null) {
            return null;
        }

        [$a, $b] = OrganisationRelationship::pair($oneId, $otherId);

        return OrganisationRelationship::where('organisation_a_id', $a)
            ->where('organisation_b_id', $b)
            ->first();
    }

    /**
     * Engagements a user's companies hold, across every Circle.
     *
     * The contractor's side of the product: "what am I currently engaged on"
     * is a question no Circle can answer, because the answer spans several.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Engagement>
     */
    public function engagementsFor(User $user)
    {
        $partyIds = CircleMembership::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->whereNotNull('circle_party_id')
            ->pluck('circle_party_id');

        return Engagement::query()
            ->where(function ($q) use ($user, $partyIds) {
                $q->where(fn ($w) => $w
                    ->where('principal_type', \App\Enums\PrincipalType::User->value)
                    ->where('principal_id', $user->id));

                $q->orWhereIn('contractor_party_id', $partyIds);
                $q->orWhereIn('engaging_party_id', $partyIds);
            })
            ->with(['circle', 'engagingParty.organisation', 'contractorParty.organisation', 'scopeGoal'])
            ->latest('created_at');
    }
}
