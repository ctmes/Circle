<?php

namespace App\Enums;

/**
 * What happens if a tool call is wrong.
 *
 * Classified by consequence rather than by what the tool is called, because the
 * approval rules have to hold for tools nobody has written yet.
 */
enum SideEffect: string
{
    /** Pure reads and computation inside the Circle. */
    case None = 'none';

    /** Creates or changes objects in this Circle only. Reversible. */
    case CircleWrite = 'circle_write';

    /** Reaches outside the Circle to read. Leaks context if misdirected. */
    case ExternalRead = 'external_read';

    /** Writes outside the Circle — email, a connected system, another party. */
    case ExternalWrite = 'external_write';

    /** Money, or a binding commercial position. */
    case Financial = 'financial';

    /** Whether an action of this class needs a human before it runs. */
    public function requiresApprovalByDefault(): bool
    {
        return $this !== self::None;
    }

    /** The floor a blueprint may not go below when declaring a tool. */
    public function minimumApprovalRole(): ?CircleRole
    {
        return match ($this) {
            self::None         => null,
            self::CircleWrite  => CircleRole::Reviewer,
            self::ExternalRead => CircleRole::Reviewer,
            self::ExternalWrite, self::Financial => CircleRole::Owner,
        };
    }

    /** Whether the approver must belong to the party bearing the consequence. */
    public function requiresOwningParty(): bool
    {
        return in_array($this, [self::ExternalWrite, self::Financial], true);
    }
}
