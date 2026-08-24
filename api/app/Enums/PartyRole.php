<?php

namespace App\Enums;

/**
 * What an organisation is doing in a Circle.
 *
 * This is a commercial position, not a permission set — permissions still come
 * from each member's CircleRole. It exists so the record reads correctly: an
 * export that says "contractor" is worth more than one that says "external".
 */
enum PartyRole: string
{
    case Convener      = 'convener';
    case Principal     = 'principal';
    case Contractor    = 'contractor';
    case Subcontractor = 'subcontractor';
    case Advisor       = 'advisor';
    case Observer      = 'observer';

    /** Parties that can be handed responsibility for a goal. */
    public function canBeResponsible(): bool
    {
        return $this !== self::Observer;
    }
}
