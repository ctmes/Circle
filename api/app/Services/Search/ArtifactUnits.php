<?php

namespace App\Services\Search;

use App\Enums\ArtifactType;
use App\Enums\CitationType;
use App\Models\EvidenceVersion;
use App\Support\MediaType;

/**
 * Turns one derived artifact into the citable places inside it (spec §8).
 *
 * Each extractor already records where its text came from — pages from
 * ExtractDocumentText, sheets and A1 refs from ExtractSpreadsheet, timestamped
 * segments from TranscribeMedia. This is the one place that knows all four
 * shapes, so search, and only search, has to care about the difference.
 */
final class ArtifactUnits
{
    /** A single unit is never indexed beyond this; pages run to ~3k characters. */
    private const MAX_UNIT_CHARS = 6000;

    /** Guards against a 50,000-row sheet turning one hit into a full scan. */
    private const MAX_UNITS = 3000;

    /** Plain text has no natural boundary, so it is windowed at line breaks. */
    private const WINDOW_CHARS = 1200;

    /** @return list<SearchUnit> */
    public static function of(EvidenceVersion $version, ArtifactType $type, array $content): array
    {
        $units = match (true) {
            isset($content['pages'])      => self::fromPages($version, $type, $content['pages']),
            isset($content['sheets'])     => self::fromSheets($version, $type, $content['sheets']),
            isset($content['segments'])   => self::fromSegments($version, $type, $content['segments']),
            isset($content['paragraphs']) => self::fromParagraphs($version, $type, $content['paragraphs']),
            default                       => self::fromWholeText($version, $type, (string) ($content['text'] ?? '')),
        };

        // An extractor can emit an empty structure (a PDF of scans, a sheet of
        // nothing but formatting). Falling back keeps the item findable on its
        // whole text rather than dropping it out of the results entirely.
        if ($units === [] && ($content['text'] ?? '') !== '') {
            $units = self::fromWholeText($version, $type, (string) $content['text']);
        }

        return array_slice($units, 0, self::MAX_UNITS);
    }

    /** @return list<SearchUnit> */
    private static function fromPages(EvidenceVersion $version, ArtifactType $type, array $pages): array
    {
        $units = [];

        foreach ($pages as $page) {
            $text   = trim((string) ($page['text'] ?? ''));
            $number = (int) ($page['page'] ?? 0);

            if ($text === '' || $number < 1) {
                continue;
            }

            $units[] = new SearchUnit(
                versionId: $version->id,
                artifactType: $type,
                citationType: CitationType::DocumentPage,
                locator: ['page' => $number],
                label: "page {$number}",
                text: self::clip($text),
            );
        }

        return $units;
    }

    /**
     * A spreadsheet hit is only useful if it names the cells. The row's own A1
     * refs give the range directly — C2:D2, not "somewhere in row 2".
     *
     * @return list<SearchUnit>
     */
    private static function fromSheets(EvidenceVersion $version, ArtifactType $type, array $sheets): array
    {
        $units = [];

        foreach ($sheets as $sheet) {
            $name = (string) ($sheet['name'] ?? '');

            foreach ($sheet['rows'] ?? [] as $row) {
                $cells = $row['cells'] ?? [];

                if (! is_array($cells) || $cells === []) {
                    continue;
                }

                $text = trim(implode(' ', array_map(strval(...), array_values($cells))));

                if ($text === '') {
                    continue;
                }

                $range = self::rangeOf(array_keys($cells));

                $units[] = new SearchUnit(
                    versionId: $version->id,
                    artifactType: $type,
                    citationType: CitationType::SpreadsheetCell,
                    locator: array_filter(
                        ['sheet' => $name !== '' ? $name : null, 'range' => $range],
                        fn ($value) => $value !== null,
                    ),
                    label: self::sheetLabel($name, $range),
                    text: self::clip($text),
                    cells: array_map(strval(...), $cells),
                );
            }
        }

        return $units;
    }

    /**
     * Narrows a spreadsheet hit from "the row" to the cells that carried the
     * match — Load Schedule!C2 rather than Load Schedule!A2:E2.
     *
     * Which words matched is Postgres's decision, taken with the same stemmer
     * that decided the hit; the highlight markers in the headline are that
     * decision, read back. A cell that holds none of them is not the citation.
     */
    public static function narrowToMatch(SearchUnit $unit, string $snippet): SearchUnit
    {
        if ($unit->cells === null || $unit->citationType !== CitationType::SpreadsheetCell) {
            return $unit;
        }

        preg_match_all(
            '/' . preg_quote(EvidenceSearch::HIGHLIGHT_START, '/') . '(.*?)'
                . preg_quote(EvidenceSearch::HIGHLIGHT_STOP, '/') . '/su',
            $snippet,
            $found,
        );

        $matched = array_filter(array_map(trim(...), $found[1] ?? []));

        if ($matched === []) {
            return $unit;
        }

        $refs = [];

        foreach ($unit->cells as $ref => $value) {
            foreach ($matched as $term) {
                if (mb_stripos($value, $term) !== false) {
                    $refs[] = $ref;
                    break;
                }
            }
        }

        $range = self::rangeOf($refs);

        if ($range === null) {
            return $unit;
        }

        $sheet = $unit->locator['sheet'] ?? '';

        return new SearchUnit(
            versionId: $unit->versionId,
            artifactType: $unit->artifactType,
            citationType: $unit->citationType,
            locator: array_filter(
                ['sheet' => $sheet !== '' ? $sheet : null, 'range' => $range],
                fn ($value) => $value !== null,
            ),
            label: self::sheetLabel((string) $sheet, $range),
            text: $unit->text,
            cells: $unit->cells,
        );
    }

    private static function sheetLabel(string $name, ?string $range): string
    {
        return $name !== '' && $range !== null ? "{$name}!{$range}" : ($range ?? $name);
    }

    /** @return list<SearchUnit> */
    private static function fromSegments(EvidenceVersion $version, ArtifactType $type, array $segments): array
    {
        $timestamp = $version->lane() === MediaType::LANE_AUDIO
            ? CitationType::AudioTimestamp
            : CitationType::VideoTimestamp;

        $units = [];

        foreach ($segments as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));

            if ($text === '' || ! isset($segment['start'])) {
                continue;
            }

            $start = (float) $segment['start'];
            $end   = isset($segment['end']) ? (float) $segment['end'] : null;

            $units[] = new SearchUnit(
                versionId: $version->id,
                artifactType: $type,
                citationType: $timestamp,
                locator: array_filter(
                    ['start_seconds' => $start, 'end_seconds' => $end],
                    fn ($value) => $value !== null,
                ),
                label: $end !== null && $end > $start
                    ? self::clock($start) . '–' . self::clock($end)
                    : self::clock($start),
                text: self::clip($text),
            );
        }

        return $units;
    }

    /**
     * PhpWord gives paragraphs, and ExtractDocumentText joins them with a blank
     * line. Re-walking that join recovers the character offsets a text_range
     * citation resolves against.
     *
     * @return list<SearchUnit>
     */
    private static function fromParagraphs(EvidenceVersion $version, ArtifactType $type, array $paragraphs): array
    {
        $units  = [];
        $offset = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraph = (string) $paragraph;
            $length    = mb_strlen($paragraph);
            $start     = $offset;
            $offset   += $length + 2;   // the "\n\n" the extractor writes between paragraphs

            if (trim($paragraph) === '') {
                continue;
            }

            $units[] = self::textRange($version, $type, $paragraph, $start, $start + $length);
        }

        return $units;
    }

    /** @return list<SearchUnit> */
    private static function fromWholeText(EvidenceVersion $version, ArtifactType $type, string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        // OCR is a machine reading of an image and carries no internal
        // coordinates, so the only honest locator is "this image".
        if ($type === ArtifactType::Ocr) {
            return [new SearchUnit(
                versionId: $version->id,
                artifactType: $type,
                citationType: CitationType::Image,
                locator: null,
                label: 'the image',
                text: self::clip($text),
            )];
        }

        $units  = [];
        $offset = 0;

        foreach (self::windows($text) as $window) {
            $length = mb_strlen($window);

            if (trim($window) !== '') {
                $units[] = self::textRange($version, $type, $window, $offset, $offset + $length);
            }

            $offset += $length;
        }

        return $units;
    }

    private static function textRange(
        EvidenceVersion $version,
        ArtifactType $type,
        string $text,
        int $start,
        int $end,
    ): SearchUnit {
        return new SearchUnit(
            versionId: $version->id,
            artifactType: $type,
            citationType: CitationType::TextRange,
            locator: ['start_char' => $start, 'end_char' => $end],
            label: "characters {$start}–{$end}",
            text: self::clip($text),
        );
    }

    /**
     * Splits on line breaks and regroups into windows, so a locator's character
     * range never cuts a line in half.
     *
     * @return list<string>
     */
    private static function windows(string $text): array
    {
        $lines   = preg_split("/(?<=\n)/", $text) ?: [$text];
        $windows = [];
        $current = '';

        foreach ($lines as $line) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($line) > self::WINDOW_CHARS) {
                $windows[] = $current;
                $current   = '';
            }

            $current .= $line;
        }

        if ($current !== '') {
            $windows[] = $current;
        }

        return $windows;
    }

    /**
     * The A1 span covering a row's populated cells: C2 alone, or C2:D2 when the
     * row spreads. Column letters are compared by numeric index so AA sorts
     * after Z rather than before it.
     */
    private static function rangeOf(array $refs): ?string
    {
        $first = null;
        $last  = null;

        foreach ($refs as $ref) {
            if (! preg_match('/^([A-Z]+)(\d+)$/i', (string) $ref, $matches)) {
                continue;
            }

            $index = self::columnIndex(strtoupper($matches[1]));

            if ($first === null || $index < $first[0]) {
                $first = [$index, strtoupper((string) $ref)];
            }

            if ($last === null || $index > $last[0]) {
                $last = [$index, strtoupper((string) $ref)];
            }
        }

        if ($first === null) {
            return null;
        }

        return $first[1] === $last[1] ? $first[1] : "{$first[1]}:{$last[1]}";
    }

    private static function columnIndex(string $letters): int
    {
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index;
    }

    /** Media timecode, zero-padded so timestamps line up down a column. */
    private static function clock(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    private static function clip(string $text): string
    {
        return mb_substr($text, 0, self::MAX_UNIT_CHARS);
    }
}
