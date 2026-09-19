<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The one place a period becomes a date.
 *
 * It lived inside PlanResolver while contracts were the only thing that spoke
 * in periods. Meetings speak in them too — "push delivery back two weeks",
 * "we need the traffic plan within ten working days" — and a second copy of
 * the arithmetic would be a second answer to "what is twenty business days
 * from the first of March".
 *
 * Nothing here is asked of a model. The model reads the period out of the
 * prose; this counts it.
 */
final class WorkingCalendar
{
    public const UNITS = ['days', 'business_days', 'weeks', 'months'];

    /**
     * `$value` may be negative: "bring it forward a week" is a period too.
     */
    public static function add(CarbonImmutable $from, int $value, string $unit): ?CarbonImmutable
    {
        return match ($unit) {
            'days'          => $from->addDays($value),
            'weeks'         => $from->addWeeks($value),
            'months'        => $from->addMonths($value),
            'business_days' => self::addBusinessDays($from, $value),
            default         => null,
        };
    }

    /**
     * How a computed date explains itself: "20 business days after 2027-03-01".
     *
     * The note matters as much as the date. A reviewer looking at the 29th of
     * March needs to know whether somebody said the 29th of March or said
     * "twenty business days" and the software counted.
     */
    public static function describe(int $value, string $unit, CarbonImmutable $from, string $relation = 'after'): string
    {
        $magnitude = abs($value);
        $label     = str_replace('_', ' ', $unit);

        if ($magnitude === 1) {
            $label = rtrim($label, 's');
        }

        // "bring it forward three days" is three days *before*, whatever
        // phrase the caller used for the anchor.
        if ($value < 0) {
            $relation = preg_replace('/^after/', 'before', $relation) ?? $relation;
        }

        return sprintf(
            '%d %s %s %s%s',
            $magnitude,
            $label,
            $relation,
            $from->toDateString(),
            $unit === 'business_days' ? ' (weekends excluded; no public holiday calendar is applied)' : '',
        );
    }

    /**
     * Weekdays only. There is no public holiday calendar in this system, and
     * inventing one for a jurisdiction nobody has stated would produce dates
     * that are confidently wrong rather than obviously approximate — so the
     * note on every business-day date says exactly what was counted.
     */
    public static function addBusinessDays(CarbonImmutable $from, int $days): CarbonImmutable
    {
        $step = $days < 0 ? -1 : 1;
        $date = $from;

        for ($i = 0; $i < abs($days); $i++) {
            do {
                $date = $date->addDays($step);
            } while ($date->isWeekend());
        }

        return $date;
    }
}
