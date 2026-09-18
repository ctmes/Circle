<?php

namespace App\Services\Evidence;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\EvidenceItem;
use App\Models\Goal;
use App\Models\User;
use App\Services\Audit\AuditChain;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Filing evidence against a node of the plan (spec §20.2).
 *
 * Three rules this class exists to hold in one place.
 *
 * The link is an index, never a grant. Attaching a document to a job does not
 * widen who may read it: a party-scoped item stays scoped, and `visibleTo`
 * filters a job's file list through exactly the rule the register uses. The
 * alternative — a job list that answers from the join table directly — is a
 * leak that would look like a feature, because the file names alone are most
 * of what a competitor wanted.
 *
 * Filing is idempotent. Dropping a folder onto a job somebody already filed it
 * against is an ordinary accident, and it should be a no-op rather than a
 * duplicate row or an error.
 *
 * Both directions are recorded. The filing is itself a claim about relevance —
 * "this drawing is what that package was built to" — made by a person on a
 * date, and taking it off again is a claim that it is not. An argument about
 * the work later is routinely an argument about exactly that.
 */
class GoalFiling
{
    public function __construct(private readonly AuditChain $audit) {}

    /**
     * File a document against a goal.
     *
     * @return bool Whether this attached it, as opposed to finding it already filed.
     */
    public function attach(Goal $goal, EvidenceItem $item, User $actor): bool
    {
        if ($goal->evidence()->whereKey($item->id)->exists()) {
            return false;
        }

        $goal->evidence()->attach($item->id, [
            'id'                  => (string) Str::ulid(),
            'attached_by_user_id' => $actor->id,
            'attached_at'         => now(),
        ]);

        $this->audit->record(
            AuditEventType::GoalEvidenceAttached,
            $goal->circle,
            ActorType::User,
            $actor->id,
            'goal',
            $goal->id,
            metadata: [
                'evidence_item_id' => $item->id,
                'filename'         => $item->currentVersion()?->original_filename ?? $item->resource?->name,
                'goal_title'       => $goal->title,
            ],
        );

        return true;
    }

    /** @return bool Whether this removed a filing that was actually there. */
    public function detach(Goal $goal, EvidenceItem $item, User $actor): bool
    {
        if ($goal->evidence()->whereKey($item->id)->doesntExist()) {
            return false;
        }

        $goal->evidence()->detach($item->id);

        $this->audit->record(
            AuditEventType::GoalEvidenceDetached,
            $goal->circle,
            ActorType::User,
            $actor->id,
            'goal',
            $goal->id,
            metadata: [
                'evidence_item_id' => $item->id,
                'filename'         => $item->currentVersion()?->original_filename ?? $item->resource?->name,
                'goal_title'       => $goal->title,
            ],
        );

        return true;
    }

    /**
     * What a reader sitting in $partyId may see filed against this goal.
     *
     * @return Collection<int, EvidenceItem>
     */
    public function visibleTo(Goal $goal, ?string $partyId, ?string $convenerPartyId): Collection
    {
        return $goal->evidence()
            ->with(['resource', 'versions', 'uploader', 'restrictedToParty'])
            ->get()
            ->filter(fn (EvidenceItem $i) => $i->isVisibleToParty($partyId, $convenerPartyId))
            ->values();
    }
}
