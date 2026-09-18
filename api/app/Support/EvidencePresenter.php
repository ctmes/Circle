<?php

namespace App\Support;

use App\Models\EvidenceItem;
use App\Models\EvidenceVersion;

/**
 * One shape for an evidence item, wherever it is answered from.
 *
 * It was a private method on EvidenceController, which was fine while the
 * register was the only place a file appeared. A job now lists the documents
 * filed against it, and a second presenter would drift from this one within a
 * release — at which point the same file would carry a different trust status
 * depending on which screen you read it on, which is the one thing an evidence
 * vault must never do.
 *
 * The three trust axes stay separate here as everywhere else (spec §6): where
 * it came from, whether it is intact, and whether anybody has reviewed it are
 * three questions, never one badge.
 */
final class EvidencePresenter
{
    /** @return array<string, mixed> */
    public static function item(EvidenceItem $item): array
    {
        $current = $item->currentVersion();

        return [
            'id'               => $item->id,
            'resource_id'      => $item->resource_id,
            'name'             => $item->resource->name,
            'origin_status'    => $item->origin_status->value,
            'integrity_status' => $current?->integrityStatus()->value ?? $item->integrity_status->value,
            'review_status'    => $item->review_status->value,
            'classification'   => $item->classification->value,
            // Stated on every row, not just the detail pane: someone deciding
            // whether to put their commercials in needs to see, at a glance,
            // that the last person who did got a scope honoured.
            'restricted_to_party' => $item->restricted_to_party_id === null ? null : [
                'id'    => $item->restricted_to_party_id,
                'label' => $item->restrictedToParty?->label(),
            ],
            'agent_read'       => $item->agent_read,
            'downloadable'     => $item->downloadable,
            'source_label'     => $item->source_label,
            'source_url'       => $item->source_url,
            'uploader'         => ['id' => $item->uploader_user_id, 'name' => $item->uploader?->name],
            'expires_at'       => $item->expires_at?->toISOString(),
            'stale_at'         => $item->stale_at?->toISOString(),
            'created_at'       => $item->created_at?->toISOString(),
            'version_count'    => $item->versions()->count(),
            'current_version'  => $current ? self::version($current) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function version(EvidenceVersion $version): array
    {
        return [
            'id'                    => $version->id,
            'version_number'        => $version->version_number,
            'original_filename'     => $version->original_filename,
            'mime_type'             => $version->mime_type,
            'byte_size'             => $version->byte_size,
            'sha256'                => $version->sha256,
            'lane'                  => $version->lane(),
            'integrity_status'      => $version->integrityStatus()->value,
            'processing_status'     => $version->processing_status->value,
            'processing_error'      => $version->processing_error,
            'extracted_text_status' => $version->extracted_text_status->value,
            'preview_status'        => $version->preview_status->value,
            'transcript_status'     => $version->transcript_status->value,
            'metadata'              => $version->metadata_json,
            'created_by'            => ['id' => $version->created_by_user_id, 'name' => $version->createdBy?->name],
            'supersedes_version_id' => $version->supersedes_version_id,
            'created_at'            => $version->created_at?->toISOString(),
        ];
    }
}
