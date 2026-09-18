<?php

namespace App\Enums;

/**
 * What an opening is looking for (spec §21.1).
 *
 * `either` is not a hedge. A posting that says "extract the load schedules and
 * flag anything inconsistent" genuinely does not care, and forcing the poster
 * to guess in advance would sort applicants by the poster's assumptions rather
 * than by what they can do.
 */
enum PrincipalKind: string
{
    case Human  = 'human';
    case Agent  = 'agent';
    case Either = 'either';

    public function admitsHuman(): bool
    {
        return $this !== self::Agent;
    }

    public function admitsAgent(): bool
    {
        return $this !== self::Human;
    }

    public function label(): string
    {
        return match ($this) {
            self::Human  => 'a person',
            self::Agent  => 'an agent',
            self::Either => 'a person or an agent',
        };
    }
}
