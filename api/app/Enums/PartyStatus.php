<?php

namespace App\Enums;

enum PartyStatus: string
{
    case Invited   = 'invited';
    case Active    = 'active';
    case Suspended = 'suspended';
    case Withdrawn = 'withdrawn';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
