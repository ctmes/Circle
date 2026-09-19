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

    // Taking a Circle out of everyone's reach, and bringing it back. Both land
    // on the Circle's own chain, which deletion leaves intact, so a restored
    // Circle's history says it was gone and for how long.
    case CircleDeleted           = 'circle.deleted';
    case CircleRestored          = 'circle.restored';

    // Renaming the mission or restating its purpose. Recorded on its own
    // rather than folded into a generic update, because every claim, decision
    // and commitment in the packet was made under some wording of it, and a
    // reader who cannot see which wording is reading the wrong record.
    case CircleDetailsChanged    = 'circle.details_changed';

    // A per-user permission override. Recorded separately from a role change
    // because it is the exception, and an exception nobody can find later is
    // indistinguishable from a mistake.
    case PermissionGranted = 'permission.granted';
    case PermissionRevoked = 'permission.revoked';

    case ResourceUploaded            = 'resource.uploaded';
    case ResourceVersionCreated      = 'resource.version_created';
    case ResourceViewed              = 'resource.viewed';
    case ResourceDownloaded          = 'resource.downloaded';
    case ResourceShared              = 'resource.shared';
    case ResourceProcessingCompleted = 'resource.processing_completed';
    case ResourceProcessingFailed    = 'resource.processing_failed';
    case ResourceMarkedStale         = 'resource.marked_stale';
    case ResourceScopeChanged        = 'resource.scope_changed';

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

    // Filing a document against a piece of work, and taking it off again.
    // Recorded because the filing is itself a claim about relevance: "this
    // drawing is what that package was built to" is a statement somebody made
    // on a date, and an argument about the work later is an argument about
    // exactly that.
    case GoalEvidenceAttached = 'goal.evidence_attached';
    case GoalEvidenceDetached = 'goal.evidence_detached';

    // Proposing a revision of the plan, and the parties answering it. Kept
    // distinct from goal.updated so the packet can show that a change was
    // negotiated rather than simply made.
    case BranchOpened    = 'branch.opened';
    case BranchProposed  = 'branch.proposed';
    case BranchApproved  = 'branch.approved';
    case BranchRefused   = 'branch.refused';
    case BranchMerged    = 'branch.merged';
    case BranchWithdrawn = 'branch.withdrawn';

    case CommentPosted        = 'comment.posted';
    case CommentMarkedRecord  = 'comment.marked_for_record';
    case CommentDeleted       = 'comment.deleted';
    case CommentThreadOpened  = 'comment.thread_opened';
    case CommentThreadShared  = 'comment.thread_shared';
    /** A general discussion was moved onto the object it turned out to be about. */
    case CommentThreadAttached = 'comment.thread_attached';

    // The execution ledger's events. `proposed` and `rejected` matter as much
    // as `executed`: an agent that keeps asking for something it is refused is
    // a thing the record should make visible.
    case AgentBlueprintCreated  = 'agent.blueprint_created';
    case AgentBlueprintUpdated  = 'agent.blueprint_updated';
    case AgentBlueprintSuspended = 'agent.blueprint_suspended';
    case AgentInstantiated      = 'agent.instantiated';
    // Proposing a connection and admitting one are different events with
    // different signatories. Collapsing them would let "we were shown a
    // fingerprint" and "we accepted it" read identically in the packet.
    case AgentConnectionProposed = 'agent.connection_proposed';
    case AgentAdmitted          = 'agent.admitted';
    case AgentRevoked           = 'agent.revoked';
    case AgentActionProposed    = 'agent.action_proposed';
    case AgentActionApproved    = 'agent.action_approved';
    case AgentActionRejected    = 'agent.action_rejected';
    case AgentActionExecuted    = 'agent.action_executed';
    case AgentActionFailed      = 'agent.action_failed';
    case AgentActionExpired     = 'agent.action_expired';

    // Finding a counterparty (spec §21.1). `shortlisted` is recorded as its
    // own event because it is the moment access changes hands — an applicant
    // who was never shortlisted never saw the Circle, and the packet should be
    // able to prove that rather than merely imply it.
    case OpeningPosted        = 'opening.posted';
    case OpeningUpdated       = 'opening.updated';
    case OpeningClosed        = 'opening.closed';
    case OpeningFilled        = 'opening.filled';
    case ApplicationSubmitted = 'application.submitted';
    case ApplicationShortlisted = 'application.shortlisted';
    case ApplicationDeclined  = 'application.declined';
    case ApplicationWithdrawn = 'application.withdrawn';
    case ApplicationAwarded   = 'application.awarded';

    // The temp contract (spec §21.2). Suspension and termination are distinct
    // from completion for the same reason the statuses are: a stint that was
    // cut short and one that ran its course must not read alike.
    case EngagementProposed   = 'engagement.proposed';
    case EngagementAgreed     = 'engagement.agreed';
    case EngagementSuspended  = 'engagement.suspended';
    case EngagementResumed    = 'engagement.resumed';
    case EngagementCompleted  = 'engagement.completed';
    case EngagementTerminated = 'engagement.terminated';
    case EngagementExpired    = 'engagement.expired';
    case EngagementMetered    = 'engagement.metered';

    // The portable record (spec §21.3). Compiling and attesting are separate:
    // the platform computes the numbers, and the counterparty signs them.
    case RecordCompiled  = 'record.compiled';
    case RecordAttested  = 'record.attested';
    case RecordPublished = 'record.published';
    case RecordHidden    = 'record.hidden';

    // The durable artifact (spec §21.4).
    case PackageCaptured     = 'package.captured';
    case PackageInstantiated = 'package.instantiated';
    case PackageForked       = 'package.forked';

    // A mandate somebody else can hire (spec §21.6).
    case BlueprintVersionPublished = 'agent.blueprint_version_published';

    // Convening from an engagement of terms (spec 23). Two events, because
    // they are two acts by two different kinds of actor: the agent read a
    // document and proposed a shape, and a person accepted some version of
    // it. A record that collapsed them would read as though the software
    // wrote the plan.
    case CircleConvened     = 'circle.convened';
    case CirclePlanAccepted = 'circle.plan_accepted';

    // Meeting transcripts (spec 24). Recorded on the Circle the meeting landed
    // in, by the agent that applied it, with the import and the person whose
    // connector sent it — so "why did this goal close on Tuesday" answers
    // with a meeting, a quotation and a name.
    case TranscriptApplied = 'transcript.applied';

    case ExportCreated = 'export.created';
    case AccessDenied  = 'access.denied';
}
