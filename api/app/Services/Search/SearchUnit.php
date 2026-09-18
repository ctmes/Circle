<?php

namespace App\Services\Search;

use App\Enums\ArtifactType;
use App\Enums\CitationType;

/**
 * One citable place inside a piece of evidence: a PDF page, a spreadsheet row,
 * a transcript segment, a span of characters.
 *
 * The locator carried here is exactly the shape ClaimCitation stores, so a
 * search result can become a citation without being reinterpreted on the way.
 */
final class SearchUnit
{
    /**
     * @param  array<string, string>|null  $cells  A1 ref => value, for a
     *         spreadsheet row. Kept so the range can be narrowed to the cells
     *         that actually matched once Postgres has said which words did.
     */
    public function __construct(
        public readonly string $versionId,
        public readonly ArtifactType $artifactType,
        public readonly CitationType $citationType,
        public readonly ?array $locator,
        public readonly string $label,
        public readonly string $text,
        public readonly ?array $cells = null,
    ) {}
}
