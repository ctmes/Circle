<?php

namespace App\Enums;

/**
 * How a stint ended, as it appears on a portable record (spec §21.3).
 *
 * Distinct from EngagementStatus because a record outlives the engagement it
 * was compiled from — the engagement row belongs to a Circle that may be
 * closed and exported, and the record has to keep reading correctly without it.
 */
enum WorkOutcome: string
{
    case Completed  = 'completed';
    case Terminated = 'terminated';
    case Expired    = 'expired';
    case Abandoned  = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Completed  => 'completed',
            self::Terminated => 'terminated early',
            self::Expired    => 'ran to expiry',
            self::Abandoned  => 'abandoned',
        };
    }
}
