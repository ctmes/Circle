<?php

namespace App\Services\Convening;

use App\Enums\PartyRole;
use App\Services\Goals\GoalService;
use Carbon\CarbonImmutable;

/**
 * Everything about a convened plan that is not a judgement (spec §23).
 *
 * This class is the other half of ConveningSchema's argument. The model returns
 * what it read; this turns that into something the application can show and
 * apply, and it does so with arithmetic, string comparison and a calendar —
 * no second model call, and the same answer on every re-run of the same output.
 *
 * Specifically, all of this happens here rather than in the schema:
 *
 *  - Resolving "20 business days after commencement" to a date.
 *  - Assembling a flat list of levels into a tree, enforcing the depth cap and
 *    repairing a level that jumps.
 *  - Matching "the Supplier" to the party the document named, and leaving a
 *    step unassigned when nothing matches.
 *  - Checking every citation against the pages that actually exist in the
 *    document that was retrieved, and dropping the ones that do not.
 *  - Counting anything that gets counted.
 *
 * Every repair and every drop is recorded in `notes`, which the review screen
 * shows above the plan. A resolver that silently fixed things would be handing
 * somebody a tidy plan with no way to tell which parts of it the software had
 * made up.
 */
class PlanResolver
{
    /**
     * @param  array  $output   Raw model output against ConveningSchema.
     * @param  list<array>  $sources  What retrieval actually supplied.
     * @param  \DateTimeInterface|null  $anchor  Overrides the document's own commencement date.
     */
    public function resolve(array $output, array $sources, ?\DateTimeInterface $anchor = null): array
    {
        $notes = [];

        $allowedVersionIds = array_values(array_filter(array_column($sources, 'evidence_version_id')));
        $pageCounts        = $this->pageCountsFrom($sources);
        $rejected          = 0;

        $mission = is_array($output['mission'] ?? null) ? $output['mission'] : [];

        // The commencement date is the origin every offset is measured from, so
        // it is the one date that cannot itself be an offset. A model that
        // returns one is not wrong about the contract — "commencing on the date
        // of the last signature" is a real clause — it just leaves the anchor
        // for a person to supply.
        $documentCommencement = $this->absoluteDate($mission['commences'] ?? null);

        if ($documentCommencement === null && $this->hasOffset($mission['commences'] ?? null)) {
            $notes[] = 'The document expresses its commencement date as a period rather than a date, '
                . 'so every other date has been measured from the start date you supply.';
        }

        $anchorDate = CarbonImmutable::instance($anchor ?? $documentCommencement ?? CarbonImmutable::now())
            ->startOfDay();

        $parties = $this->resolveParties(
            is_array($output['parties'] ?? null) ? $output['parties'] : [],
            $allowedVersionIds,
            $pageCounts,
            $notes,
            $rejected,
        );

        $steps = $this->resolveSteps(
            is_array($output['plan'] ?? null) ? $output['plan'] : [],
            $anchorDate,
            $parties,
            $allowedVersionIds,
            $pageCounts,
            $notes,
            $rejected,
        );

        $concludes = $this->resolveSchedule($mission['concludes'] ?? null, $anchorDate);

        // Flattened once, here, so every count below is over the same list the
        // tree was built from and none of them can disagree.
        $flat = $this->flatten($steps);

        return [
            'mission' => [
                'name'         => $this->text($mission['name'] ?? null) ?? 'Untitled engagement',
                'purpose'      => $this->text($mission['purpose'] ?? null) ?? '',
                'commences_on' => $anchorDate->toDateString(),
                'concludes_on' => $concludes['date']?->toDateString(),
                'concludes_note' => $concludes['note'],
                'basis'        => $this->basis($mission['basis'] ?? null),
                'citation'     => $this->resolveCitation(
                    $mission['citation'] ?? null, $allowedVersionIds, $pageCounts, $notes, $rejected,
                ),
            ],
            'anchor_date'    => $anchorDate->toDateString(),
            'anchor_source'  => $anchor !== null
                ? 'supplied'
                : ($documentCommencement !== null ? 'document' : 'today'),
            'parties'        => array_values($parties),
            'plan'           => $steps,
            'open_questions' => $this->openQuestions($output['open_questions'] ?? null),
            'uncertainty'    => $this->text($output['uncertainty'] ?? null),
            'notes'          => $notes,
            'counts'         => [
                'parties'            => count($parties),
                'steps'              => count($flat),
                'steps_inferred'     => count(array_filter($flat, fn (array $s) => $s['basis'] === 'inferred')),
                'steps_dated'        => count(array_filter($flat, fn (array $s) => $s['due_on'] !== null)),
                'steps_with_acceptance' => count(array_filter($flat, fn (array $s) => $s['acceptance_condition'] !== null)),
                'steps_assigned'     => count(array_filter($flat, fn (array $s) => $s['responsible_party_key'] !== null)),
                'citations'          => count(array_filter($flat, fn (array $s) => $s['citation'] !== null)),
                'citations_rejected' => $rejected,
            ],
        ];
    }

    // ------------------------------------------------------------- parties

    /**
     * @param  list<array>  $raw
     * @return array<string, array<string, mixed>>  keyed by the proposal-local key
     */
    private function resolveParties(
        array $raw,
        array $allowedVersionIds,
        array $pageCounts,
        array &$notes,
        int &$rejected,
    ): array {
        $parties = [];
        $seen    = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = $this->text($entry['display_name'] ?? null);

            if ($name === null) {
                continue;
            }

            $normalised = $this->normalise($name);

            // Two rows for one company is the commonest shape of a bad read —
            // "JWA Mats" and "JWA Mats Pty Ltd" — and admitting both would put
            // two parties in the Circle with one set of obligations split
            // between them.
            if (isset($seen[$normalised])) {
                $notes[] = sprintf('"%s" was proposed twice and has been listed once.', $name);

                continue;
            }

            $role = PartyRole::tryFrom((string) ($entry['party_role'] ?? ''));

            // Convener is not offered in the schema, but a model that returns
            // it anyway must not be able to claim the convening seat, which
            // belongs to the organisation that opened the Circle.
            if ($role === null || $role === PartyRole::Convener) {
                $notes[] = sprintf('"%s" had no usable role and has been listed as an observer.', $name);
                $role = PartyRole::Observer;
            }

            $key = 'p' . (count($parties) + 1);
            $seen[$normalised] = $key;

            $parties[$key] = [
                'key'          => $key,
                'display_name' => $name,
                'defined_term' => $this->text($entry['defined_term'] ?? null),
                'party_role'   => $role->value,
                'basis'        => $this->basis($entry['basis'] ?? null),
                'citation'     => $this->resolveCitation(
                    $entry['citation'] ?? null, $allowedVersionIds, $pageCounts, $notes, $rejected,
                ),
            ];
        }

        return $parties;
    }

    /**
     * Which party a step belongs to.
     *
     * Matched on the company name and on the term the document uses for it,
     * because a scope of works says "the Supplier shall" far more often than it
     * repeats a company name. No fuzzy matching: a near-miss that assigned work
     * to the wrong company would be worse than leaving it unassigned, and
     * unassigned is a state this product already handles — it is what an
     * opening is posted against.
     *
     * @param  array<string, array<string, mixed>>  $parties
     */
    private function matchParty(?string $responsible, array $parties): ?string
    {
        if ($responsible === null) {
            return null;
        }

        $needle = $this->normalise($responsible);

        if ($needle === '') {
            return null;
        }

        foreach ($parties as $key => $party) {
            if ($this->normalise($party['display_name']) === $needle) {
                return $key;
            }

            if ($party['defined_term'] !== null && $this->normalise($party['defined_term']) === $needle) {
                return $key;
            }
        }

        return null;
    }

    // --------------------------------------------------------------- steps

    /**
     * Builds the tree from the flat list.
     *
     * A step attaches to the most recent step one level above it. Two failures
     * are repaired rather than refused, and both are reported: a level that
     * jumps more than one (attached to the deepest open parent), and a level
     * past the tree's cap (clamped). Refusing the whole plan over a nesting
     * mistake would throw away forty correct rows to punish one.
     *
     * @param  list<array>  $raw
     * @param  array<string, array<string, mixed>>  $parties
     * @return list<array<string, mixed>>
     */
    private function resolveSteps(
        array $raw,
        CarbonImmutable $anchor,
        array $parties,
        array $allowedVersionIds,
        array $pageCounts,
        array &$notes,
        int &$rejected,
    ): array {
        $maxDepth = GoalService::maxDepth();

        $flat    = [];
        $lastAt  = [];
        $clamped = 0;
        $jumped  = 0;

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $title = $this->text($entry['title'] ?? null);

            if ($title === null) {
                continue;
            }

            $level = max(1, (int) ($entry['level'] ?? 1));

            if ($level > $maxDepth) {
                $level = $maxDepth;
                $clamped++;
            }

            // The deepest level that currently has a node able to parent this
            // one. A step claiming level 3 with nothing at level 2 above it is
            // attached where it can actually go, rather than dropped.
            $deepestOpen = 0;

            for ($l = 1; $l <= $maxDepth; $l++) {
                if (isset($lastAt[$l])) {
                    $deepestOpen = $l;
                }
            }

            if ($level > $deepestOpen + 1) {
                $level = $deepestOpen + 1;
                $jumped++;
            }

            $starts = $this->resolveSchedule($entry['starts'] ?? null, $anchor);
            $due    = $this->resolveSchedule($entry['due'] ?? null, $anchor);

            $responsible = $this->text($entry['responsible_party'] ?? null);
            $partyKey    = $this->matchParty($responsible, $parties);

            if ($responsible !== null && $partyKey === null) {
                $notes[] = sprintf(
                    '"%s" is made responsible for "%s" but is not one of the parties, so the step is unassigned.',
                    $responsible,
                    $title,
                );
            }

            $position = count($flat);

            $flat[] = [
                'key'                    => 'n' . ($position + 1),
                'title'                  => $title,
                'description'            => $this->text($entry['description'] ?? null),
                'acceptance_condition'   => $this->text($entry['acceptance_condition'] ?? null),
                'responsible_party_key'  => $partyKey,
                'responsible_as_written' => $responsible,
                'starts_on'              => $starts['date']?->toDateString(),
                'starts_note'            => $starts['note'],
                'due_on'                 => $due['date']?->toDateString(),
                'due_note'               => $due['note'],
                'clause'                 => $this->text($entry['clause'] ?? null),
                'basis'                  => $this->basis($entry['basis'] ?? null),
                'citation'               => $this->resolveCitation(
                    $entry['citation'] ?? null, $allowedVersionIds, $pageCounts, $notes, $rejected,
                ),
                'level'                  => $level,
                'parent'                 => $level === 1 ? null : ($lastAt[$level - 1] ?? null),
            ];

            // This node is now the open parent at its level, and everything
            // below it is closed — a later sibling must not land inside the
            // node we have just finished.
            $lastAt[$level] = $position;

            for ($l = $level + 1; $l <= $maxDepth; $l++) {
                unset($lastAt[$l]);
            }
        }

        if ($clamped > 0) {
            $notes[] = sprintf(
                '%d step%s nested deeper than the %d levels a plan allows and %s been raised to the deepest level.',
                $clamped, $clamped === 1 ? '' : 's', $maxDepth, $clamped === 1 ? 'has' : 'have',
            );
        }

        if ($jumped > 0) {
            $notes[] = sprintf(
                '%d step%s skipped a level of nesting and %s been attached to the step above it.',
                $jumped, $jumped === 1 ? '' : 's', $jumped === 1 ? 'has' : 'have',
            );
        }

        return $this->assemble($flat, null);
    }

    /**
     * Turns the flat list with parent positions into the tree.
     *
     * Written as a second pass over indices rather than by holding references
     * into the tree as it is built: a PHP reference taken into an array element
     * survives into every later copy of that array, and a plan that aliased two
     * of its own branches would be a very hard thing to notice.
     *
     * @param  list<array<string, mixed>>  $flat
     * @return list<array<string, mixed>>
     */
    private function assemble(array $flat, ?int $parent): array
    {
        $out = [];

        foreach ($flat as $position => $node) {
            if (($node['parent'] ?? null) !== $parent) {
                continue;
            }

            $node['children'] = $this->assemble($flat, $position);
            unset($node['parent']);

            $out[] = $node;
        }

        return $out;
    }

    // ------------------------------------------------------------- dates

    /**
     * A schedule object becomes a date, or it does not.
     *
     * The note is as much the point as the date. A reviewer looking at
     * "24 October" needs to know whether the contract said the 24th of October
     * or said "twenty business days" and the software counted — those are two
     * very different things to be held to, and only one of them is worth
     * arguing with the counterparty about.
     *
     * @return array{date: CarbonImmutable|null, note: string|null}
     */
    private function resolveSchedule(mixed $schedule, CarbonImmutable $anchor): array
    {
        if (! is_array($schedule)) {
            return ['date' => null, 'note' => null];
        }

        $absolute = $this->absoluteDate($schedule);

        if ($absolute !== null) {
            return ['date' => $absolute, 'note' => null];
        }

        $offset = $schedule['after_commencement'] ?? null;

        if (! is_array($offset) || ! isset($offset['value'], $offset['unit'])) {
            return ['date' => null, 'note' => null];
        }

        $value = (int) $offset['value'];
        $unit  = (string) $offset['unit'];

        if ($value < 0) {
            return ['date' => null, 'note' => null];
        }

        $date = match ($unit) {
            'days'          => $anchor->addDays($value),
            'weeks'         => $anchor->addWeeks($value),
            'months'        => $anchor->addMonths($value),
            'business_days' => $this->addBusinessDays($anchor, $value),
            default         => null,
        };

        if ($date === null) {
            return ['date' => null, 'note' => null];
        }

        return [
            'date' => $date,
            'note' => sprintf(
                '%d %s after %s%s',
                $value,
                // "1 days after" is the kind of thing a reviewer reads as
                // sloppiness in the document rather than in us.
                $value === 1
                    ? rtrim(str_replace('_', ' ', $unit), 's')
                    : str_replace('_', ' ', $unit),
                $anchor->toDateString(),
                $unit === 'business_days' ? ' (weekends excluded; no public holiday calendar is applied)' : '',
            ),
        ];
    }

    /**
     * Weekdays only. There is no public holiday calendar in this system, and
     * inventing one for a jurisdiction nobody has stated would produce dates
     * that are confidently wrong rather than obviously approximate — so the
     * note on every business-day date says exactly what was counted.
     */
    private function addBusinessDays(CarbonImmutable $from, int $days): CarbonImmutable
    {
        $date = $from;

        for ($i = 0; $i < $days; $i++) {
            do {
                $date = $date->addDay();
            } while ($date->isWeekend());
        }

        return $date;
    }

    private function absoluteDate(mixed $schedule): ?CarbonImmutable
    {
        if (! is_array($schedule)) {
            return null;
        }

        $on = $this->text($schedule['on'] ?? null);

        if ($on === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($on)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasOffset(mixed $schedule): bool
    {
        return is_array($schedule) && is_array($schedule['after_commencement'] ?? null);
    }

    // --------------------------------------------------------- citations

    /**
     * A citation survives only if it points at something that exists.
     *
     * The same rule AgentRunner applies to a claim, for the same reason: a
     * citation nobody can follow is worse than none, because it reads as though
     * somebody checked. Where the page is wrong but the excerpt is not, the
     * excerpt is kept — a quotation a reader can search for is still evidence
     * of where this came from.
     */
    private function resolveCitation(
        mixed $citation,
        array $allowedVersionIds,
        array $pageCounts,
        array &$notes,
        int &$rejected,
    ): ?array {
        if (! is_array($citation) || $citation === []) {
            return null;
        }

        $versionId = $this->text($citation['evidence_version_id'] ?? null);

        if ($versionId === null && count($allowedVersionIds) === 1) {
            // One document was supplied, so there is nothing to be ambiguous
            // about and nothing for the model to mistype.
            $versionId = $allowedVersionIds[0];
        }

        if ($versionId === null || ! in_array($versionId, $allowedVersionIds, true)) {
            $rejected++;

            return null;
        }

        $page    = isset($citation['page']) ? (int) $citation['page'] : null;
        $excerpt = $this->text($citation['excerpt'] ?? null);
        $maxPage = $pageCounts[$versionId] ?? null;

        if ($page !== null && ($page < 1 || ($maxPage !== null && $page > $maxPage))) {
            $notes[] = sprintf(
                'A citation named page %d of a document with %s, so the page has been dropped.',
                $page,
                $maxPage === null ? 'no page index' : $maxPage . ' pages',
            );
            $page = null;
        }

        if ($page === null && $excerpt === null) {
            $rejected++;

            return null;
        }

        return array_filter([
            'evidence_version_id' => $versionId,
            'page'                => $page,
            'excerpt'             => $excerpt,
        ], fn ($v) => $v !== null);
    }

    /**
     * How many pages each retrieved document actually has, from the page index
     * retrieval already built. Nothing asks the model for this.
     *
     * @param  list<array>  $sources
     * @return array<string, int>
     */
    private function pageCountsFrom(array $sources): array
    {
        $counts = [];

        foreach ($sources as $source) {
            $pages = $source['content']['page_index'] ?? null;

            if (! is_array($pages) || $pages === []) {
                continue;
            }

            $numbers = array_filter(array_column($pages, 'page'), 'is_int');

            if ($numbers !== []) {
                $counts[$source['evidence_version_id']] = max($numbers);
            }
        }

        return $counts;
    }

    // ----------------------------------------------------------- helpers

    /** @return list<array<string, mixed>> every node in the tree, depth first */
    public function flatten(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            $out[] = $node;
            $out = array_merge($out, $this->flatten($node['children'] ?? []));
        }

        return $out;
    }

    private function openQuestions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $question = $this->text($entry['question'] ?? null);

            if ($question === null) {
                continue;
            }

            $out[] = [
                'question'       => $question,
                'why_it_matters' => $this->text($entry['why_it_matters'] ?? null),
            ];
        }

        return $out;
    }

    private function basis(mixed $value): string
    {
        return $value === 'stated' ? 'stated' : 'inferred';
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** Lowercased, unpunctuated, and without the definite article a contract puts in front of a defined term. */
    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return preg_replace('/^the\s+/u', '', $value) ?? $value;
    }
}
