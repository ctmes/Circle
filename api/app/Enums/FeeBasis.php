<?php

namespace App\Enums;

/**
 * How an engagement's fee is counted (spec §21.2).
 *
 * `per_action` exists because an agent's unit of work is an approved action,
 * and `agent_actions` is already the meter — §20.4 wrote that ledger before
 * anybody thought of billing from it, and it turns out to be the right shape.
 */
enum FeeBasis: string
{
    case Fixed          = 'fixed';
    case Hourly         = 'hourly';
    case Daily          = 'daily';
    case PerDeliverable = 'per_deliverable';
    case PerAction      = 'per_action';

    /**
     * Whether this basis accrues against a meter rather than being settled as
     * one figure. A metered basis with an empty meter means no work was done;
     * a fixed one with an empty meter means nothing at all.
     */
    public function isMetered(): bool
    {
        return in_array($this, [self::Hourly, self::Daily, self::PerDeliverable, self::PerAction], strict: true);
    }

    /** The unit a meter entry counts, for display and for the record. */
    public function unit(): string
    {
        return match ($this) {
            self::Fixed          => 'engagement',
            self::Hourly         => 'hour',
            self::Daily          => 'day',
            self::PerDeliverable => 'deliverable',
            self::PerAction      => 'action',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Fixed          => 'fixed fee',
            self::Hourly         => 'per hour',
            self::Daily          => 'per day',
            self::PerDeliverable => 'per deliverable',
            self::PerAction      => 'per approved action',
        };
    }
}
