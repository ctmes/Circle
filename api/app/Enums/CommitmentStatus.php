<?php

namespace App\Enums;

enum CommitmentStatus: string
{
    /** Agent-created commitments start here and require human confirmation. */
    case Draft     = 'draft';
    case Open      = 'open';
    case Blocked   = 'blocked';
    case Done      = 'done';
    case Cancelled = 'cancelled';
}
