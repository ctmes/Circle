<?php

namespace App\Enums;

/**
 * The life of one attempted agent action.
 *
 * Written at Proposed, before anything is attempted, so a refused or failed
 * action leaves the same trail as a successful one.
 */
enum AgentActionStatus: string
{
    case Proposed         = 'proposed';
    case AwaitingApproval = 'awaiting_approval';
    case Approved         = 'approved';
    case Rejected         = 'rejected';
    case Executing        = 'executing';
    case Executed         = 'executed';
    case Failed           = 'failed';
    case Cancelled        = 'cancelled';
    case Expired          = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Rejected, self::Executed, self::Failed,
            self::Cancelled, self::Expired,
        ], true);
    }

    public function isPendingHuman(): bool
    {
        return $this === self::AwaitingApproval;
    }

    /** Only an approved action may run. */
    public function canExecute(): bool
    {
        return $this === self::Approved;
    }
}
