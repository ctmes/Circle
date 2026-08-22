<?php

namespace App\Enums;

/**
 * A claim is never silently promoted to fact (spec §5). Status transitions are
 * explicit and always carry an actor.
 */
enum ClaimStatus: string
{
    case Draft       = 'draft';
    case Attested    = 'attested';
    case Derived     = 'derived';
    case UnderReview = 'under_review';
    case Reviewed    = 'reviewed';
    case Approved    = 'approved';
    case Contested   = 'contested';
    case Rejected    = 'rejected';
}
