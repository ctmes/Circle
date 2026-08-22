<?php

namespace App\Support;

use App\Enums\CitationType;
use App\Models\EvidenceVersion;

/**
 * Validates that a citation's locator actually matches its type (spec §8).
 *
 * A citation is the load-bearing part of the trust model — "show me where this
 * came from". A malformed locator that silently stores anyway would produce a
 * citation that looks precise and resolves to nothing.
 */
final class CitationLocator
{
    /** Infers the most precise type the cited media can support. */
    public static function inferType(EvidenceVersion $version, ?array $locator): CitationType
    {
        if ($locator === null || $locator === []) {
            return CitationType::Generic;
        }

        return match (true) {
            isset($locator['sheet']), isset($locator['range'])   => CitationType::SpreadsheetCell,
            isset($locator['page'])                              => CitationType::DocumentPage,
            isset($locator['start_seconds'])                     => $version->lane() === MediaType::LANE_AUDIO
                                                                        ? CitationType::AudioTimestamp
                                                                        : CitationType::VideoTimestamp,
            isset($locator['start_char'])                        => CitationType::TextRange,
            isset($locator['url'])                               => CitationType::UrlSnapshot,
            default                                              => CitationType::Generic,
        };
    }

    /** @throws \InvalidArgumentException when the locator cannot resolve. */
    public static function validate(CitationType $type, ?array $locator): void
    {
        if ($type === CitationType::Generic || $type === CitationType::Image) {
            return;
        }

        if ($locator === null || $locator === []) {
            throw new \InvalidArgumentException("A {$type->value} citation requires a locator.");
        }

        match ($type) {
            CitationType::DocumentPage    => self::requirePositiveInt($locator, 'page'),
            CitationType::TextRange       => self::requireCharRange($locator),
            CitationType::SpreadsheetCell => self::requireSpreadsheet($locator),
            CitationType::VideoTimestamp,
            CitationType::AudioTimestamp  => self::requireTimeRange($locator),
            CitationType::UrlSnapshot     => self::requireKey($locator, 'url'),
            default                       => null,
        };
    }

    private static function requireKey(array $locator, string $key): void
    {
        if (! array_key_exists($key, $locator) || $locator[$key] === null || $locator[$key] === '') {
            throw new \InvalidArgumentException("Locator is missing required key '{$key}'.");
        }
    }

    private static function requirePositiveInt(array $locator, string $key): void
    {
        self::requireKey($locator, $key);

        if (! is_int($locator[$key]) || $locator[$key] < 1) {
            throw new \InvalidArgumentException("Locator '{$key}' must be a positive integer.");
        }
    }

    private static function requireCharRange(array $locator): void
    {
        foreach (['start_char', 'end_char'] as $key) {
            self::requireKey($locator, $key);

            if (! is_int($locator[$key]) || $locator[$key] < 0) {
                throw new \InvalidArgumentException("Locator '{$key}' must be a non-negative integer.");
            }
        }

        if ($locator['end_char'] < $locator['start_char']) {
            throw new \InvalidArgumentException('Locator end_char must not precede start_char.');
        }
    }

    private static function requireSpreadsheet(array $locator): void
    {
        if (! isset($locator['sheet']) && ! isset($locator['range'])) {
            throw new \InvalidArgumentException("A spreadsheet citation requires 'sheet' and/or 'range'.");
        }

        // Accept a single cell (F12) or a range (F12:H12).
        if (isset($locator['range']) && ! preg_match('/^[A-Z]+\d+(:[A-Z]+\d+)?$/i', (string) $locator['range'])) {
            throw new \InvalidArgumentException("Locator 'range' must be an A1 reference such as F12 or F12:H12.");
        }
    }

    private static function requireTimeRange(array $locator): void
    {
        self::requireKey($locator, 'start_seconds');

        if (! is_numeric($locator['start_seconds']) || $locator['start_seconds'] < 0) {
            throw new \InvalidArgumentException("Locator 'start_seconds' must be a non-negative number.");
        }

        if (isset($locator['end_seconds'])) {
            if (! is_numeric($locator['end_seconds'])) {
                throw new \InvalidArgumentException("Locator 'end_seconds' must be a number.");
            }

            if ($locator['end_seconds'] < $locator['start_seconds']) {
                throw new \InvalidArgumentException('Locator end_seconds must not precede start_seconds.');
            }
        }
    }
}
