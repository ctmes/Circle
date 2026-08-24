<?php

namespace App\Enums;

/**
 * Lifecycle of a goal or sub-goal.
 *
 * `in_review` and `met` are separate on purpose: work being finished and work
 * being accepted are different events, and in inter-company delivery the gap
 * between them is where the argument happens.
 */
enum GoalStatus: string
{
    case Draft     = 'draft';
    case Active    = 'active';
    case Blocked   = 'blocked';
    case InReview  = 'in_review';
    case Met       = 'met';
    case Abandoned = 'abandoned';

    public function isSettled(): bool
    {
        return in_array($this, [self::Met, self::Abandoned], true);
    }

    /** Whether work may still be recorded against it. */
    public function isOpen(): bool
    {
        return ! $this->isSettled();
    }
}
