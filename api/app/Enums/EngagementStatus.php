<?php

namespace App\Enums;

/**
 * The life of a temp contract (spec §21.2).
 *
 * `completed`, `terminated` and `expired` are three different facts about the
 * same person leaving, and the record has to be able to tell them apart in a
 * year. Collapsing them into "ended" is how a track record stops meaning
 * anything: a contract that ran its course and one that was cut short read
 * identically, so neither is worth reading.
 */
enum EngagementStatus: string
{
    case Proposed   = 'proposed';
    case Active     = 'active';
    case Suspended  = 'suspended';
    case Completed  = 'completed';
    case Terminated = 'terminated';
    case Expired    = 'expired';

    /**
     * Whether the principal may still change anything under this engagement.
     *
     * Read by AccessGate as check (7). Listed positively so a status added
     * later denies writes until somebody decides otherwise — the safe default
     * for a check whose whole job is to narrow.
     */
    public function permitsWork(): bool
    {
        return $this === self::Active;
    }

    /** Whether the engagement has finished, however it finished. */
    public function isEnded(): bool
    {
        return in_array($this, [self::Completed, self::Terminated, self::Expired], strict: true);
    }

    /** The outcome this status becomes on a work record. */
    public function outcome(): ?WorkOutcome
    {
        return match ($this) {
            self::Completed  => WorkOutcome::Completed,
            self::Terminated => WorkOutcome::Terminated,
            self::Expired    => WorkOutcome::Expired,
            default          => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Proposed   => 'proposed',
            self::Active     => 'active',
            self::Suspended  => 'suspended',
            self::Completed  => 'completed',
            self::Terminated => 'terminated early',
            self::Expired    => 'expired',
        };
    }
}
