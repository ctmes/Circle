<?php

namespace App\Enums;

/**
 * Where an application stands (spec §21.1).
 *
 * `shortlisted` is a state of its own rather than a flag because it is the
 * moment access changes hands: applying grants nothing, and shortlisting
 * admits the applicant's company to the Circle. A model that went straight
 * from `submitted` to `awarded` would have no name for the act that let forty
 * strangers see somebody's project.
 */
enum ApplicationStatus: string
{
    case Submitted   = 'submitted';
    case Shortlisted = 'shortlisted';
    case Declined    = 'declined';
    case Withdrawn   = 'withdrawn';
    case Awarded     = 'awarded';

    /** Whether the applicant has been admitted to the Circle. */
    public function isAdmitted(): bool
    {
        return in_array($this, [self::Shortlisted, self::Awarded], strict: true);
    }

    /** Whether this application is still in play. */
    public function isLive(): bool
    {
        return in_array($this, [self::Submitted, self::Shortlisted], strict: true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Submitted   => 'submitted',
            self::Shortlisted => 'shortlisted',
            self::Declined    => 'declined',
            self::Withdrawn   => 'withdrawn',
            self::Awarded     => 'awarded',
        };
    }
}
