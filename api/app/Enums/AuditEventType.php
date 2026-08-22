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

    case ExportCreated = 'export.created';
    case AccessDenied  = 'access.denied';
}
