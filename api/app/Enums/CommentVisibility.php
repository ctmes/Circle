<?php

namespace App\Enums;

/**
 * Who can read a thread.
 *
 * `Party` is the default. Evidence defaults the other way — shared unless
 * restricted — because evidence is the thing the Circle exists to pool, while a
 * party working out its own position in front of a counterparty is how a Circle
 * stops being used at all.
 */
enum CommentVisibility: string
{
    case Circle = 'circle';
    case Party  = 'party';

    public function requiresParty(): bool
    {
        return $this === self::Party;
    }
}
