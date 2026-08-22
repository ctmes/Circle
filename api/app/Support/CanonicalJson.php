<?php

namespace App\Support;

/**
 * Deterministic JSON encoding for hashing.
 *
 * Two encodings of the same logical value must produce byte-identical output on
 * any machine, or the audit chain will not re-verify. Object keys are therefore
 * sorted recursively and escaping is pinned.
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        $encoded = json_encode(
            self::normalise($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        return $encoded;
    }

    private static function normalise(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (! is_array($value)) {
            return $value;
        }

        // A list keeps its order; a map is sorted by key so encoding is stable.
        if (array_is_list($value)) {
            return array_map(self::normalise(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(self::normalise(...), $value);
    }
}
