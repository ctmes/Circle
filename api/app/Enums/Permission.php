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

    case GoalCreate = 'goal.create';
    case GoalUpdate = 'goal.update';
    case GoalAccept = 'goal.accept';

    // Proposing a revision of the plan is a different right from making one.
    // Anyone who can contribute may draft a branch; agreeing to merge it binds
    // a company, so it sits with the roles that can bind one — and can be
    // delegated to a named person without moving them to that role.
    case GoalBranch = 'goal.branch';
    case GoalMerge  = 'goal.merge';

    case CommentCreate   = 'comment.create';
    case CommentModerate = 'comment.moderate';

    case PartyManage = 'party.manage';

    // Finding a counterparty rather than already knowing one (spec §21).
    //
    // There is deliberately no `work.apply`. An applicant is not a member of
    // the Circle they are applying to — that is the entire point of §21.1's
    // admission sequence — so applying cannot be a Circle permission without
    // pretending the applicant is already inside. It is authorised instead by
    // the opening's visibility, which is the only thing that can answer it.
    case WorkPost         = 'work.post';
    case WorkAward        = 'work.award';
    case EngagementManage = 'engagement.manage';
    case PackagePublish   = 'package.publish';
    case RecordAttest     = 'record.attest';

    // Authoring an agent is a different right from running one, and both are
    // different from letting one act. Kept separate so a Circle can allow
    // agent work without allowing anyone to widen an agent's mandate.
    case AgentAuthor  = 'agent.author';
    case AgentConnect = 'agent.connect';
    case AgentExecute = 'agent.execute';
    case AgentApprove = 'agent.approve';

    /**
     * Whether exercising this permission changes something.
     *
     * Used by AccessGate to stop a read-only agent before its declared action
     * list is even consulted. Listed positively rather than by excluding reads,
     * so a permission added later defaults to being treated as a write.
     */
    public function isWrite(): bool
    {
        return ! in_array($this, [
            self::CircleView,
            self::ResourceView,
            self::ResourceDownload,
            self::ResourceAgentRead,
        ], strict: true);
    }
}
