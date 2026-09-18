<?php

namespace App\Services\Ai;

/**
 * Trims a JSON Schema down to what Anthropic's structured outputs will accept.
 *
 * Structured outputs implements a subset of JSON Schema. It takes the basic
 * types, `enum`, `const`, `anyOf`, `allOf`, `$ref`/`$defs`, the string formats,
 * and `additionalProperties: false`. It refuses numeric bounds, string length
 * bounds and array cardinality — and it refuses them with a 400 naming one
 * keyword at a time, so a schema with four of them takes four round trips to
 * discover. Both of this application's schemas had them, and neither had ever
 * been sent to a live model, which is exactly how that goes unnoticed.
 *
 * This lives in the provider layer rather than in the schema builders, because
 * it is a fact about one vendor's API and not about what the application wants
 * to ask for. `OutputSchema` and `ConveningSchema` go on saying `minimum: 1` —
 * it documents the intent, it is what a second provider might well honour, and
 * every one of those bounds is enforced in PHP after the run regardless. The
 * Python and TypeScript SDKs do this same strip client-side; the PHP SDK does
 * not, so we do it here.
 *
 * Stripping rather than rejecting is deliberate. These are hints to a model,
 * never a validation boundary — the boundary is PlanResolver and AgentRunner,
 * which check every value they are given whether the model was told the range
 * or not.
 */
final class StructuredSchema
{
    /**
     * Keywords the API rejects.
     *
     * `minItems`/`maxItems`/`uniqueItems` are the "complex array constraints"
     * the documentation names without listing.
     */
    private const UNSUPPORTED = [
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
        'minLength', 'maxLength', 'pattern',
        'minItems', 'maxItems', 'uniqueItems',
        'minProperties', 'maxProperties',
    ];

    /**
     * How many optional parameters a schema declares, the way the grammar
     * compiler counts them.
     *
     * Every object contributes one for each property not named in its own
     * `required` list, at every level and at every place a shape is inlined —
     * so a schema that reuses one optional sub-object in four places pays for
     * it four times. The API refuses a schema over its limit, and the limit is
     * low enough that a schema written without watching this will hit it.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function countOptional(array $schema): int
    {
        $count = 0;

        if (($schema['type'] ?? null) === 'object' && is_array($schema['properties'] ?? null)) {
            $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

            foreach ($schema['properties'] as $name => $property) {
                if (! in_array($name, $required, true)) {
                    $count++;
                }

                if (is_array($property)) {
                    $count += self::countOptional($property);
                }
            }

            return $count;
        }

        if (($schema['type'] ?? null) === 'array' && is_array($schema['items'] ?? null)) {
            return self::countOptional($schema['items']);
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function prepare(array $schema): array
    {
        $out = [];

        foreach ($schema as $key => $value) {
            if (is_string($key) && in_array($key, self::UNSUPPORTED, true)) {
                continue;
            }

            $out[$key] = is_array($value) ? self::prepare($value) : $value;
        }

        return $out;
    }
}
