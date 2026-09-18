<?php

namespace App\Enums;

/**
 * The things the product will tell somebody about.
 *
 * Five, and the list is meant to stay short. Each one is something that
 * happened *to the recipient* and that they cannot discover any other way
 * without opening the application and remembering to look — which is the
 * failure this enum exists to end.
 *
 * Every kind declares the permission a recipient must hold to be told. That is
 * not bookkeeping: a notification names an object, and naming an object to
 * somebody whose gate would refuse it is a disclosure the rest of the product
 * spends seven checks preventing. Notifier runs the gate before it sends, and
 * this is where it learns what to ask.
 */
enum NotificationKind: string
{
    /** You have been invited into a Circle and hold the token to accept. */
    case Invited = 'invited';

    /** A decision names you as its approver, and only you can resolve it. */
    case DecisionAssigned = 'decision_assigned';

    /** A document you cited, or approved against, has been superseded. */
    case EvidenceSuperseded = 'evidence_superseded';

    /** A due date moved, and your party's agreement is what it waits on. */
    case ScheduleChangeProposed = 'schedule_change_proposed';

    /** Somebody named you in a thread you can read. */
    case Mentioned = 'mentioned';

    /**
     * The permission the recipient must hold for this message to be legitimate.
     *
     * Invitation is the one kind with no permission, and necessarily so: the
     * recipient is not a member yet and the gate would refuse them everything.
     * What bounds that message instead is the token — it carries no Circle
     * content beyond the name and the purpose the inviter chose to state.
     */
    public function requires(): ?Permission
    {
        return match ($this) {
            self::Invited                 => null,
            self::DecisionAssigned        => Permission::DecisionApprove,
            self::EvidenceSuperseded      => Permission::ResourceView,
            self::ScheduleChangeProposed  => Permission::GoalUpdate,
            self::Mentioned               => Permission::CommentCreate,
        };
    }

    /** The subject line, which has to be legible in a notification shade. */
    public function subject(string $circleName): string
    {
        return match ($this) {
            self::Invited                => "You have been invited to {$circleName}",
            self::DecisionAssigned       => "A decision is waiting on you in {$circleName}",
            self::EvidenceSuperseded     => "A document you relied on has changed in {$circleName}",
            self::ScheduleChangeProposed => "A date moved in {$circleName} and needs your agreement",
            self::Mentioned              => "You were mentioned in {$circleName}",
        };
    }

    /** What the single button in the message says. */
    public function callToAction(): string
    {
        return match ($this) {
            self::Invited                => 'Accept the invitation',
            self::DecisionAssigned       => 'Open the decision',
            self::EvidenceSuperseded     => 'See what changed',
            self::ScheduleChangeProposed => 'Review the change',
            self::Mentioned              => 'Open the thread',
        };
    }
}
