<?php

namespace App\Enums;

/** The audit vocabulary required by spec §11. */
enum AuditEventType: string
{
    case CircleCreated           = 'circle.created';
    case CircleMemberInvited     = 'circle.member_invited';
    case CircleMemberRoleChanged = 'circle.member_role_changed';
    case CircleMemberRemoved     = 'circle.member_removed';
    case CircleClosed            = 'circle.closed';
    case CircleProgressSet       = 'circle.progress_set';

    case ResourceUploaded            = 'resource.uploaded';
    case ResourceVersionCreated      = 'resource.version_created';
    case ResourceViewed              = 'resource.viewed';
    case ResourceDownloaded          = 'resource.downloaded';
    case ResourceShared              = 'resource.shared';
    case ResourceProcessingCompleted = 'resource.processing_completed';
    case ResourceProcessingFailed    = 'resource.processing_failed';
    case ResourceMarkedStale         = 'resource.marked_stale';

    case ClaimCreated   = 'claim.created';
    case ClaimUpdated   = 'claim.updated';
    case ClaimReviewed  = 'claim.reviewed';
    case ClaimContested = 'claim.contested';

    case DecisionCreated  = 'decision.created';
    case DecisionApproved = 'decision.approved';
    case DecisionRejected = 'decision.rejected';

    case CommitmentCreated = 'commitment.created';
    case CommitmentUpdated = 'commitment.updated';

    case AgentRunStarted       = 'agent.run_started';
    case AgentResourceRetrieved = 'agent.resource_retrieved';
    case AgentOutputCreated    = 'agent.output_created';
    case AgentRunFailed        = 'agent.run_failed';

    // Parties. Admitting or removing a company is a bigger event than
    // adding one of its people, and reads as such in the packet.
    case PartyInvited   = 'party.invited';
    case PartyJoined    = 'party.joined';
    case PartyWithdrawn = 'party.withdrawn';
    case PartySuspended = 'party.suspended';

    case GoalCreated   = 'goal.created';
    case GoalUpdated   = 'goal.updated';
    case GoalAccepted  = 'goal.accepted';
    case GoalAbandoned = 'goal.abandoned';

    // Recorded separately from goal.updated because a moved deadline is the
    // thing parties argue about, and it should never need reconstructing from
    // a diff of two generic update events.
    case GoalRescheduled      = 'goal.rescheduled';
    case GoalRescheduleAgreed = 'goal.reschedule_agreed';

    case CommentPosted        = 'comment.posted';
    case CommentMarkedRecord  = 'comment.marked_for_record';
    case CommentDeleted       = 'comment.deleted';
    case CommentThreadOpened  = 'comment.thread_opened';
    case CommentThreadShared  = 'comment.thread_shared';

    // The execution ledger's events. `proposed` and `rejected` matter as much
    // as `executed`: an agent that keeps asking for something it is refused is
    // a thing the record should make visible.
    case AgentBlueprintCreated  = 'agent.blueprint_created';
    case AgentBlueprintUpdated  = 'agent.blueprint_updated';
    case AgentBlueprintSuspended = 'agent.blueprint_suspended';
    case AgentAdmitted          = 'agent.admitted';
    case AgentRevoked           = 'agent.revoked';
    case AgentActionProposed    = 'agent.action_proposed';
    case AgentActionApproved    = 'agent.action_approved';
    case AgentActionRejected    = 'agent.action_rejected';
    case AgentActionExecuted    = 'agent.action_executed';
    case AgentActionFailed      = 'agent.action_failed';
    case AgentActionExpired     = 'agent.action_expired';

    case ExportCreated = 'export.created';
    case AccessDenied  = 'access.denied';
}
