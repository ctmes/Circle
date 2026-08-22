<?php

namespace App\Enums;

/** Meaning/review status (spec §6). Orthogonal to origin and integrity. */
enum ReviewStatus: string
{
    case Unreviewed = 'unreviewed';
    case Reviewed   = 'reviewed';
    case Approved   = 'approved';
    case Derived    = 'derived';
    case Contested  = 'contested';
    case Stale      = 'stale';
}
