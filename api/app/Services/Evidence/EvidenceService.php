<?php

namespace App\Services\Evidence;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\Classification;
use App\Enums\IntegrityStatus;
use App\Enums\OriginStatus;
use App\Enums\ProcessingStatus;
use App\Enums\ReviewStatus;
use App\Models\Circle;
use App\Models\CircleResource;
use App\Models\EvidenceItem;
use App\Models\EvidenceVersion;
use App\Models\User;
use App\Services\Audit\AuditChain;
use App\Services\Decisions\DecisionService;
use App\Support\MediaType;
use Illuminate\Support\Facades\DB;

/**
 * Creates and versions evidence.
 *
 * The invariant this class exists to protect: an uploaded original is never
 * overwritten and never mutated. A replacement is always a new version row that
 * points back at what it supersedes, so citations against the old version stay
 * resolvable forever (spec §7).
 */
class EvidenceService
{
    public function __construct(
        private readonly AuditChain $audit,
        private readonly EvidenceStorage $storage,
        private readonly DecisionService $decisions,
        private readonly \App\Services\Notifications\Notifier $notifier,
    ) {}

    /**
     * Registers an object that has already been uploaded to the signed URL.
     * The version starts in `processing`; the queue verifies hash/MIME and runs
     * extraction before it becomes `ready`.
     */
    public function createItem(
        Circle $circle,
        User $uploader,
        string $storageKey,
        string $originalFilename,
        ?string $displayName = null,
        ?string $declaredMimeType = null,
        Classification $classification = Classification::Internal,
        ?string $sourceLabel = null,
        ?string $sourceUrl = null,
        bool $agentRead = false,
        bool $downloadable = true,
        ?\DateTimeInterface $expiresAt = null,
        ?string $restrictedToPartyId = null,
    ): EvidenceItem {
        return DB::transaction(function () use (
            $circle, $uploader, $storageKey, $originalFilename, $displayName,
            $declaredMimeType, $classification, $sourceLabel, $sourceUrl,
            $agentRead, $downloadable, $expiresAt, $restrictedToPartyId
        ) {
            $resource = CircleResource::create([
                'circle_id'       => $circle->id,
                'resource_type'   => 'evidence_item',
                'name'            => $displayName ?: $originalFilename,
                'created_by_type' => 'user',
                'created_by_id'   => $uploader->id,
                'status'          => 'active',
            ]);

            $item = EvidenceItem::create([
                'resource_id' => $resource->id,
                // The uploader authenticated to reach this code path, so the
                // origin is an authenticated upload — never "verified".
                'origin_status'    => OriginStatus::AuthenticatedUpload,
                'integrity_status' => IntegrityStatus::Unknown,
                'review_status'    => ReviewStatus::Unreviewed,
                'classification'   => $classification,
                // Null is the default and means the whole Circle: evidence is
                // what a Circle exists to pool, and an item nobody chose to
                // narrow is everybody's.
                'restricted_to_party_id' => $restrictedToPartyId,
                'source_label'     => $sourceLabel,
                'source_url'       => $sourceUrl,
                'uploader_user_id' => $uploader->id,
                'expires_at'       => $expiresAt,
                'agent_read'       => $agentRead,
                'downloadable'     => $downloadable,
            ]);

            $version = $this->makeVersion($item, $uploader, $storageKey, $originalFilename, $declaredMimeType, 1, null);

            $this->audit->record(
                AuditEventType::ResourceUploaded,
                $circle,
                ActorType::User,
                $uploader->id,
                'evidence_item',
                $item->id,
                (string) $version->version_number,
                [
                    'filename'       => $originalFilename,
                    'storage_key'    => $storageKey,
                    'classification' => $classification->value,
                    'agent_read'     => $agentRead,
                    // In the audit trail from the start: "who could see this,
                    // and from when" is the question a scoped item invites.
                    'restricted_to_party_id' => $restrictedToPartyId,
                ],
            );

            return $item->refresh();
        });
    }

    /**
     * Adds a replacement version. The previous version is superseded, not
     * replaced — both remain retrievable and citable.
     */
    public function addVersion(
        EvidenceItem $item,
        User $uploader,
        string $storageKey,
        string $originalFilename,
        ?string $declaredMimeType = null,
    ): EvidenceVersion {
        [$version, $previous] = DB::transaction(function () use ($item, $uploader, $storageKey, $originalFilename, $declaredMimeType) {
            $previous = $item->versions()->orderByDesc('version_number')->lockForUpdate()->first();
            $nextNumber = ($previous?->version_number ?? 0) + 1;

            $version = $this->makeVersion(
                $item, $uploader, $storageKey, $originalFilename,
                $declaredMimeType, $nextNumber, $previous?->id,
            );

            // A new version invalidates the item-level review state: the
            // reviewed thing is no longer the current thing (spec §8).
            $item->forceFill([
                'integrity_status' => IntegrityStatus::Unknown,
                'review_status'    => ReviewStatus::Unreviewed,
            ])->save();

            $circle = $item->resource->circle;

            // An approval was given against an exact version. A replacement
            // does not inherit it — the old approval is marked superseded so
            // "which version was approved" keeps exactly one answer (spec §8).
            $supersededApprovals = $this->decisions->supersedeApprovalsFor(
                'evidence_item',
                $item->id,
                (string) $version->version_number,
            );

            $this->audit->record(
                AuditEventType::ResourceVersionCreated,
                $circle,
                ActorType::User,
                $uploader->id,
                'evidence_item',
                $item->id,
                (string) $version->version_number,
                [
                    'filename'              => $originalFilename,
                    'supersedes_version_id' => $previous?->id,
                    'supersedes_version'    => $previous?->version_number,
                    'approvals_superseded'  => $supersededApprovals,
                ],
            );

            return [$version, $previous];
        });

        // After the commit, and only where something actually relied on the old
        // version. Lineage was always exact; what was missing was anybody being
        // told on the day, which is the only day it is useful to know.
        if ($previous !== null) {
            $this->notifier->evidenceSuperseded($previous, $version);
        }

        return $version;
    }

    private function makeVersion(
        EvidenceItem $item,
        User $uploader,
        string $storageKey,
        string $originalFilename,
        ?string $declaredMimeType,
        int $versionNumber,
        ?string $supersedesVersionId,
    ): EvidenceVersion {
        $lane = MediaType::laneFor($originalFilename, $declaredMimeType);

        return EvidenceVersion::create([
            'evidence_item_id'  => $item->id,
            'version_number'    => $versionNumber,
            'storage_key'       => $storageKey,
            'original_filename' => $originalFilename,
            // Declared by the client and therefore untrusted; the processing
            // job overwrites this with the detected type.
            'mime_type'         => MediaType::canonicalMime($originalFilename, $declaredMimeType),
            'processing_status' => ProcessingStatus::Pending,
            // Lanes that cannot produce a given artifact are marked skipped up
            // front, so "pending forever" never masks an unsupported format.
            'extracted_text_status' => $this->initialStatus($lane, [MediaType::LANE_DOCUMENT, MediaType::LANE_SPREADSHEET, MediaType::LANE_IMAGE]),
            'preview_status'        => $this->initialStatus($lane, [MediaType::LANE_IMAGE, MediaType::LANE_VIDEO, MediaType::LANE_DOCUMENT]),
            'transcript_status'     => $this->initialStatus($lane, [MediaType::LANE_VIDEO, MediaType::LANE_AUDIO]),
            'metadata_json'         => ['lane' => $lane],
            'created_by_user_id'    => $uploader->id,
            'supersedes_version_id' => $supersedesVersionId,
        ]);
    }

    private function initialStatus(string $lane, array $applicableLanes): ProcessingStatus
    {
        return in_array($lane, $applicableLanes, true) ? ProcessingStatus::Pending : ProcessingStatus::Skipped;
    }

    // ------------------------------------------------------------- review

    public function review(EvidenceItem $item, User $reviewer, ReviewStatus $status, ?string $comment = null): EvidenceItem
    {
        $item->forceFill(['review_status' => $status])->save();

        $this->audit->record(
            $status === ReviewStatus::Contested ? AuditEventType::ClaimContested : AuditEventType::ClaimReviewed,
            $item->resource->circle,
            ActorType::User,
            $reviewer->id,
            'evidence_item',
            $item->id,
            (string) $item->currentVersion()?->version_number,
            ['review_status' => $status->value, 'comment' => $comment],
        );

        return $item;
    }

    public function markStale(EvidenceItem $item, ActorType $actorType, ?string $actorId, ?string $reason = null): EvidenceItem
    {
        $item->forceFill([
            'review_status' => ReviewStatus::Stale,
            'stale_at'      => now(),
        ])->save();

        $this->audit->record(
            AuditEventType::ResourceMarkedStale,
            $item->resource->circle,
            $actorType,
            $actorId,
            'evidence_item',
            $item->id,
            metadata: ['reason' => $reason],
        );

        return $item;
    }
}
