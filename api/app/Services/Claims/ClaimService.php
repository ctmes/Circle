<?php

namespace App\Services\Claims;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\CitationType;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Models\Circle;
use App\Models\Claim;
use App\Models\ClaimCitation;
use App\Models\ClaimReview;
use App\Models\EvidenceVersion;
use App\Models\Goal;
use App\Models\User;
use App\Services\Audit\AuditChain;
use App\Support\CitationLocator;
use Illuminate\Support\Facades\DB;

class ClaimService
{
    public function __construct(private readonly AuditChain $audit) {}

    /**
     * @param  list<array{evidence_version_id: string, citation_type?: string, locator?: array, excerpt?: string}>  $citations
     */
    public function create(
        Circle $circle,
        User $author,
        string $statement,
        ClaimType $type,
        array $citations = [],
        ?float $confidence = null,
        ?Goal $goal = null,
    ): Claim {
        return DB::transaction(function () use ($circle, $author, $statement, $type, $citations, $confidence, $goal) {
            $claim = Claim::create([
                'circle_id'   => $circle->id,
                // Which part of the plan this is about. Null is a real answer —
                // plenty of claims are about the mission rather than one node —
                // but it is now an answer somebody gave rather than the only
                // one the API could record.
                'goal_id'     => $goal?->id,
                'author_type' => 'user',
                'author_id'   => $author->id,
                'statement'   => $statement,
                'claim_type'  => $type,
                // A person stating something on the record is "attested": a
                // verified identity made a statement. It is not yet reviewed.
                'status'      => ClaimStatus::Attested,
                'confidence'  => $confidence,
            ]);

            foreach ($citations as $citation) {
                $this->addCitation($claim, $citation);
            }

            $this->audit->record(
                AuditEventType::ClaimCreated, $circle, ActorType::User, $author->id,
                'claim', $claim->id, metadata: [
                    'claim_type'     => $type->value,
                    'citation_count' => count($citations),
                    'goal_id'        => $goal?->id,
                ],
            );

            return $claim->load('citations');
        });
    }

    public function addCitation(Claim $claim, array $citation): ClaimCitation
    {
        $version = EvidenceVersion::with('evidenceItem.resource')->findOrFail($citation['evidence_version_id']);

        // A citation may only point at evidence inside the same Circle.
        if ($version->evidenceItem->resource->circle_id !== $claim->circle_id) {
            throw new \RuntimeException('Cited evidence belongs to a different Circle.');
        }

        $locator = $citation['locator'] ?? null;
        $type = isset($citation['citation_type'])
            ? CitationType::from($citation['citation_type'])
            : CitationLocator::inferType($version, $locator);

        CitationLocator::validate($type, $locator);

        return ClaimCitation::create([
            'claim_id'            => $claim->id,
            'evidence_version_id' => $version->id,
            'citation_type'       => $type,
            'locator_json'        => $locator,
            'excerpt'             => $citation['excerpt'] ?? null,
        ]);
    }

    /**
     * Records a review outcome. Reviews are append-only; the claim's status
     * reflects the latest one but every review remains readable.
     */
    public function review(Claim $claim, User $reviewer, string $outcome, ?string $comment = null): Claim
    {
        return DB::transaction(function () use ($claim, $reviewer, $outcome, $comment) {
            ClaimReview::create([
                'claim_id'         => $claim->id,
                'reviewer_user_id' => $reviewer->id,
                'outcome'          => $outcome,
                'comment'          => $comment,
            ]);

            $status = match ($outcome) {
                'reviewed'          => ClaimStatus::Reviewed,
                'contested'         => ClaimStatus::Contested,
                'rejected'          => ClaimStatus::Rejected,
                'changes_requested' => ClaimStatus::UnderReview,
                default             => throw new \InvalidArgumentException("Unknown review outcome: {$outcome}"),
            };

            $claim->forceFill(['status' => $status])->save();

            $this->audit->record(
                $outcome === 'contested' ? AuditEventType::ClaimContested : AuditEventType::ClaimReviewed,
                $claim->circle, ActorType::User, $reviewer->id,
                'claim', $claim->id, metadata: [
                    'outcome' => $outcome,
                    'comment' => $comment,
                    'status'  => $status->value,
                ],
            );

            return $claim;
        });
    }
}
