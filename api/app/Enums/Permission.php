<?php

namespace App\Enums;

/**
 * Action-level permissions (spec §10). Every authorised request resolves to
 * exactly one of these.
 */
enum Permission: string
{
    case CircleView          = 'circle.view';
    case CircleManageMembers = 'circle.manage_members';
    case CircleClose         = 'circle.close';

    case ResourceView      = 'resource.view';
    case ResourceDownload  = 'resource.download';
    case ResourceUpload    = 'resource.upload';
    case ResourceShare     = 'resource.share';
    case ResourceDelete    = 'resource.delete';
    case ResourceAgentRead = 'resource.agent_read';

    case ClaimCreate  = 'claim.create';
    case ClaimReview  = 'claim.review';
    case ClaimApprove = 'claim.approve';

    case DecisionCreate  = 'decision.create';
    case DecisionApprove = 'decision.approve';

    case CommitmentCreate = 'commitment.create';
    case CommitmentUpdate = 'commitment.update';

    case AgentRun     = 'agent.run';
    case ExportCreate = 'export.create';
}
