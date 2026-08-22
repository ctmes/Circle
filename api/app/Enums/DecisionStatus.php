<?php

namespace App\Enums;

enum DecisionStatus: string
{
    case Draft      = 'draft';
    case Pending    = 'pending';
    case Approved   = 'approved';
    case Rejected   = 'rejected';
    case Expired    = 'expired';
    /** The approved subject gained a new version, invalidating this approval. */
    case Superseded = 'superseded';

    public function isResolved(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Expired, self::Superseded], true);
    }
}
