<?php

namespace App\Enums;

enum CircleStatus: string
{
    case Draft    = 'draft';
    case Active   = 'active';
    case Closing  = 'closing';
    case Archived = 'archived';

    /** Closed Circles block new access, mutations and agent runs (spec §16). */
    public function isClosed(): bool
    {
        return $this === self::Archived;
    }

    /** Only an active Circle accepts new evidence, claims and agent runs. */
    public function acceptsContributions(): bool
    {
        return $this === self::Active;
    }
}
