<?php

namespace App\Services\Evidence;

use App\Models\Claim;
use App\Models\ClaimCitation;
use App\Models\Decision;
use App\Models\EvidenceVersion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What relied on a version, asked at the moment it stops being current.
 *
 * The product could already record that a document had been replaced, and could
 * still resolve a citation made in March to the March bytes. What it could not
 * do was answer the question anybody actually asks when a revision lands: *what
 * did this just invalidate, and who needs to know*. A record that preserves the
 * past perfectly and says nothing about the present is an archive, and people
 * do not open archives on the day something changes.
 *
 * Two kinds of reliance, and they are not the same kind of problem.
 *
 * A **claim** cites a version. The citation stays valid — it still resolves to
 * the bytes it cited, which is the whole point of immutable versions — but the
 * assertion it supports may now be about a document nobody is working from.
 * That is a judgement for the claim's author, so the author is who gets told.
 *
 * An **approval** was bound to an exact version (spec §8). Editing an approved
 * resource creates a new version and the old approval does not follow it
 * forward, which means there is now an approved decision whose subject is a
 * superseded document. Nothing is broken and nothing is automatically undone —
 * the approver is told, and what to do about it is theirs to decide.
 *
 * Deliberately not here: any state change. This service reads. Marking the
 * claims stale, or reopening the decisions, would be the software deciding that
 * a new revision invalidates a judgement a person made, and it frequently does
 * not — a drawing reissued for a different package changes nothing about the
 * load schedule somebody approved last week.
 */
class SupersessionImpact
{
    /**
     * @return array{
     *     claims: Collection<int, Claim>,
     *     decisions: Collection<int, Decision>,
     *     recipients: Collection<int, User>,
     * }
     */
    public function of(EvidenceVersion $version): array
    {
        $claims    = $this->claimsCiting($version);
        $decisions = $this->decisionsBoundTo($version);

        return [
            'claims'     => $claims,
            'decisions'  => $decisions,
            'recipients' => $this->recipients($claims, $decisions),
        ];
    }

    /** A flat count, for the places that only need to say whether it matters. */
    public function isEmpty(EvidenceVersion $version): bool
    {
        $impact = $this->of($version);

        return $impact['claims']->isEmpty() && $impact['decisions']->isEmpty();
    }

    /** @return Collection<int, Claim> */
    private function claimsCiting(EvidenceVersion $version): Collection
    {
        $claimIds = ClaimCitation::where('evidence_version_id', $version->id)
            ->pluck('claim_id')
            ->unique();

        if ($claimIds->isEmpty()) {
            return collect();
        }

        return Claim::whereIn('id', $claimIds)
            ->with('author')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Decisions whose subject is this exact version of this exact item.
     *
     * `subject_version` is stored as a string because a version identifier is
     * not always a number — it is whatever the approver was shown. Compared as
     * a string here for the same reason.
     *
     * @return Collection<int, Decision>
     */
    private function decisionsBoundTo(EvidenceVersion $version): Collection
    {
        return Decision::where('subject_type', 'evidence_item')
            ->where('subject_id', $version->evidence_item_id)
            ->where('subject_version', (string) $version->version_number)
            ->with('approver')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Who to tell.
     *
     * Claim authors and decision approvers, deduplicated. Agent-authored claims
     * contribute nobody: an agent has no inbox, and its output is a draft a
     * human already has to review. Unassigned decisions contribute nobody for
     * the same reason — there is no approver to tell yet.
     *
     * This list is a *candidate* list. Whether each of them may actually be
     * told is decided by Notifier against the gate, because someone who could
     * cite a document last month may have had their access narrowed since.
     *
     * @param  Collection<int, Claim>  $claims
     * @param  Collection<int, Decision>  $decisions
     * @return Collection<int, User>
     */
    private function recipients(Collection $claims, Collection $decisions): Collection
    {
        $users = collect();

        foreach ($claims as $claim) {
            if (! $claim->isAgentAuthored() && $claim->author !== null) {
                $users->push($claim->author);
            }
        }

        foreach ($decisions as $decision) {
            if ($decision->approver !== null) {
                $users->push($decision->approver);
            }
        }

        return $users->unique('id')->values();
    }
}
