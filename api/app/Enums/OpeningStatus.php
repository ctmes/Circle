<?php

namespace App\Enums;

/**
 * The life of a posting (spec §21.1).
 *
 * `closed` and `filled` are separate because "we stopped looking" and "we
 * found somebody" are different answers to an applicant, and an opening that
 * cannot say which teaches people not to apply to the next one.
 */
enum OpeningStatus: string
{
    case Draft     = 'draft';
    case Open      = 'open';
    case Closed    = 'closed';
    case Filled    = 'filled';
    case Withdrawn = 'withdrawn';

    /** Whether a new application may be submitted against it. */
    public function acceptsApplications(): bool
    {
        return $this === self::Open;
    }

    /** Whether anyone outside the posting party may see it at all. */
    public function isDiscoverable(): bool
    {
        return in_array($this, [self::Open, self::Filled], strict: true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'draft',
            self::Open      => 'open',
            self::Closed    => 'closed to applications',
            self::Filled    => 'filled',
            self::Withdrawn => 'withdrawn',
        };
    }
}
