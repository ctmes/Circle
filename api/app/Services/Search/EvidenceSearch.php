<?php

namespace App\Services\Search;

use App\Enums\ArtifactType;
use App\Enums\Permission;
use App\Models\Circle;
use App\Models\EvidenceVersion;
use App\Services\Authorisation\AccessGate;
use App\Services\Authorisation\Actor;
use Illuminate\Support\Facades\DB;

/**
 * Search that answers with a citation, not a filename (spec §8, §12).
 *
 * Two passes, for two different reasons:
 *
 *   1. Postgres finds the *artifacts* whose extracted text matches, using the
 *      GIN index on derived_artifacts.search_vector. Cheap, and it touches no
 *      text the caller may not be allowed to read.
 *   2. Every surviving artifact is put through AccessGate per item before its
 *      text is loaded, then broken into citable units and ranked. Search is
 *      not a side door: an item you cannot view is an item you cannot find.
 *
 * The second pass sends unit texts back to Postgres rather than matching them
 * in PHP, so a hit inside a page is decided by the same stemmer and the same
 * dictionary that decided the hit on the document. A locator that disagreed
 * with the index would be worse than no locator at all.
 */
class EvidenceSearch
{
    /** Only text-bearing artifacts; thumbnails and frames have nothing to match. */
    private const SEARCHABLE = [
        ArtifactType::Extraction->value,
        ArtifactType::Transcript->value,
        ArtifactType::Ocr->value,
    ];

    /** Candidates considered before access filtering. */
    private const MAX_CANDIDATES = 60;

    /** Artifacts whose text is actually loaded and broken into units. */
    private const MAX_ARTIFACTS_READ = 15;

    /** One document should not fill the page with its own pages. */
    private const MAX_HITS_PER_ITEM = 3;

    /** Machine-derived text is never presented as if it were the record. */
    private const MACHINE_READ = [ArtifactType::Transcript->value, ArtifactType::Ocr->value];

    public const HIGHLIGHT_START = '[[HL]]';
    public const HIGHLIGHT_STOP  = '[[/HL]]';

    public function __construct(private readonly AccessGate $gate) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function search(Circle $circle, Actor $actor, string $query, ?string $lane = null, int $limit = 20): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $candidates = $this->candidateArtifacts($circle, $query);

        if ($candidates === []) {
            return [];
        }

        $readable = $this->readable($circle, $actor, $candidates, $lane);

        if ($readable === []) {
            return [];
        }

        $units = $this->unitsOf($readable);

        if ($units === []) {
            return [];
        }

        return $this->rank($units, $readable, $query, $limit);
    }

    /**
     * The index pass. Ranking here is document-level and only decides which
     * artifacts are worth opening.
     *
     * @return list<object>
     */
    private function candidateArtifacts(Circle $circle, string $query): array
    {
        $types = implode(',', array_map(fn (string $t) => "'{$t}'", self::SEARCHABLE));

        return DB::select(
            "SELECT id,
                    parent_resource_id AS version_id,
                    artifact_type,
                    ts_rank(search_vector, websearch_to_tsquery('english', ?)) AS doc_rank
               FROM derived_artifacts
              WHERE circle_id = ?
                AND parent_resource_type = 'evidence_version'
                AND status = 'ready'
                AND artifact_type IN ({$types})
                AND search_vector @@ websearch_to_tsquery('english', ?)
              ORDER BY doc_rank DESC
              LIMIT ?",
            [$query, $circle->id, $query, self::MAX_CANDIDATES],
        );
    }

    /**
     * Access filtering, before a single character of extracted text is read.
     *
     * @param  list<object>  $candidates
     * @return array<string, array{artifact: object, version: EvidenceVersion}>  keyed by artifact id
     */
    private function readable(Circle $circle, Actor $actor, array $candidates, ?string $lane): array
    {
        $versions = EvidenceVersion::with('evidenceItem.resource')
            ->whereIn('id', array_values(array_filter(array_column($candidates, 'version_id'))))
            ->get()
            ->keyBy('id');

        $readable  = [];
        $decisions = [];

        foreach ($candidates as $artifact) {
            $version  = $versions->get($artifact->version_id);
            $resource = $version?->evidenceItem?->resource;

            // A derived artifact whose parent has gone is not searchable; so is
            // one whose parent lives in another Circle.
            if ($resource === null || $resource->circle_id !== $circle->id) {
                continue;
            }

            if ($lane !== null && $version->lane() !== $lane) {
                continue;
            }

            $decisions[$resource->id] ??= $this->gate->allows($actor, Permission::ResourceView, $circle, $resource);

            if (! $decisions[$resource->id]) {
                continue;
            }

            $readable[$artifact->id] = ['artifact' => $artifact, 'version' => $version];

            if (count($readable) >= self::MAX_ARTIFACTS_READ) {
                break;
            }
        }

        return $readable;
    }

    /**
     * @param  array<string, array{artifact: object, version: EvidenceVersion}>  $readable
     * @return list<SearchUnit>
     */
    private function unitsOf(array $readable): array
    {
        $contents = DB::table('derived_artifacts')
            ->whereIn('id', array_keys($readable))
            ->pluck('content_json', 'id');

        $units = [];

        foreach ($readable as $artifactId => $row) {
            $content = json_decode((string) $contents->get($artifactId), true);

            if (! is_array($content)) {
                continue;
            }

            $units = array_merge($units, ArtifactUnits::of(
                $row['version'],
                ArtifactType::from($row['artifact']->artifact_type),
                $content,
            ));
        }

        return $units;
    }

    /**
     * The locator pass: which unit matched, how well, and what the reader sees.
     *
     * @param  list<SearchUnit>  $units
     * @param  array<string, array{artifact: object, version: EvidenceVersion}>  $readable
     * @return list<array<string, mixed>>
     */
    private function rank(array $units, array $readable, string $query, int $limit): array
    {
        $options = sprintf(
            'MaxFragments=1,MaxWords=34,MinWords=16,ShortWord=3,StartSel=%s,StopSel=%s',
            self::HIGHLIGHT_START,
            self::HIGHLIGHT_STOP,
        );

        $matches = DB::select(
            "SELECT t.pos - 1 AS idx,
                    ts_rank(to_tsvector('english', t.txt), q.query) AS rank,
                    ts_headline('english', t.txt, q.query, ?) AS snippet
               FROM jsonb_array_elements_text(?::jsonb) WITH ORDINALITY AS t(txt, pos),
                    websearch_to_tsquery('english', ?) AS q(query)
              WHERE to_tsvector('english', t.txt) @@ q.query
              ORDER BY rank DESC",
            [
                $options,
                // A single bad byte from an extractor must not silently turn
                // the whole search into "no results"; it degrades to U+FFFD.
                json_encode(
                    array_map(fn (SearchUnit $unit) => $unit->text, $units),
                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                ),
                $query,
            ],
        );

        $perItem = [];
        $hits    = [];

        foreach ($matches as $match) {
            $unit = $units[(int) $match->idx] ?? null;

            if ($unit === null) {
                continue;
            }

            $version = $this->versionOf($readable, $unit->versionId);

            if ($version === null) {
                continue;
            }

            $itemId = $version->evidence_item_id;
            $perItem[$itemId] ??= 0;

            if ($perItem[$itemId] >= self::MAX_HITS_PER_ITEM) {
                continue;
            }

            $perItem[$itemId]++;

            $snippet = (string) $match->snippet;
            $hits[]  = $this->present(
                ArtifactUnits::narrowToMatch($unit, $snippet),
                $version,
                (float) $match->rank,
                $snippet,
            );

            if (count($hits) >= $limit) {
                break;
            }
        }

        return $hits;
    }

    /**
     * @param  array<string, array{artifact: object, version: EvidenceVersion}>  $readable
     */
    private function versionOf(array $readable, string $versionId): ?EvidenceVersion
    {
        foreach ($readable as $row) {
            if ($row['version']->id === $versionId) {
                return $row['version'];
            }
        }

        return null;
    }

    /**
     * The payload is deliberately shaped like a citation: locator and
     * citation_type can be posted to /claims unchanged.
     *
     * @return array<string, mixed>
     */
    private function present(SearchUnit $unit, EvidenceVersion $version, float $rank, string $snippet): array
    {
        $item = $version->evidenceItem;

        return [
            'evidence_item_id'    => $item->id,
            'name'                => $item->resource->name,
            'evidence_version_id' => $version->id,
            'version_number'      => $version->version_number,
            'filename'            => $version->original_filename,
            'lane'                => $version->lane(),
            'integrity_status'    => $version->integrityStatus()->value,
            'review_status'       => $item->review_status->value,
            'citation_type'       => $unit->citationType->value,
            'locator'             => $unit->locator,
            'locator_label'       => $unit->label,
            'snippet'             => $snippet,
            'rank'                => round($rank, 6),
            'artifact_type'       => $unit->artifactType->value,
            // A transcript or an OCR reading is a model's account of the
            // original, never the original itself (spec §5).
            'machine_read'        => in_array($unit->artifactType->value, self::MACHINE_READ, true),
        ];
    }
}
