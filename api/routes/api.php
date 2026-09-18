<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AgentRegistryController;
use App\Http\Controllers\Api\AgentStudioController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CircleController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\ConveningController;
use App\Http\Controllers\Api\DecisionController;
use App\Http\Controllers\Api\EngagementController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\GoalEvidenceController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\OrganisationController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PartyController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\WorkOpeningController;
use App\Http\Controllers\Api\WorkPackageController;
use App\Http\Controllers\Api\WorkRecordController;
use Illuminate\Support\Facades\Route;

/*
| Circle API (spec §15).
|
| Every route below the auth middleware resolves its Circle and runs an
| AccessGate check inside the controller. There is deliberately no "list all
| evidence in the organisation" style route — nothing is reachable except
| through a Circle the caller belongs to.
*/

/*
| The unauthenticated surface, and the only part of this API a stranger can
| reach. Throttled per IP, at two different rates, because the two halves are
| worth abusing for different reasons.
|
| Signing in and signing up are limited generously. The limit has to clear a
| whole team arriving at nine o'clock behind one office NAT address, and an
| account is worth little on its own here: organisation membership conveys no
| Circle access, and reaching any actual work needs an invitation somebody
| issued. Locking out a real office to slow a script down is the wrong trade.
|
| Asking for a reset link is limited hard, because it is the one endpoint that
| makes the server send mail to an address the caller chose. Unlimited, it is a
| way to bury somebody's inbox and to burn the sending reputation the
| invitations depend on. Nobody legitimately needs it six times a minute.
*/
Route::middleware('throttle:20,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware('throttle:6,1')->post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // --- Organisations -----------------------------------------------------
    Route::get('/organisations', [OrganisationController::class, 'index']);
    Route::post('/organisations', [OrganisationController::class, 'store']);
    Route::get('/organisations/{slug}', [OrganisationController::class, 'show']);

    // --- Circles -----------------------------------------------------------
    Route::get('/circles', [CircleController::class, 'index']);
    Route::post('/circles', [CircleController::class, 'store']);
    Route::get('/circles/{circle}', [CircleController::class, 'show']);
    Route::patch('/circles/{circle}', [CircleController::class, 'update']);
    Route::get('/circles/{circle}/overview', [CircleController::class, 'overview']);
    Route::post('/circles/{circle}/close', [CircleController::class, 'close']);
    Route::post('/circles/{circle}/archive', [CircleController::class, 'close']);

    // --- Membership --------------------------------------------------------
    Route::get('/circles/{circle}/members', [MembershipController::class, 'index']);

    // Per-user permission grants. Readable by anyone who can see the Circle:
    // an exception to the role vocabulary that only its author can see means
    // everyone else is working against a permission model that is not in force.
    Route::get('/circles/{circle}/grants', [PermissionController::class, 'index']);
    Route::post('/circles/{circle}/grants', [PermissionController::class, 'store']);
    Route::delete('/circles/{circle}/grants/{grant}', [PermissionController::class, 'destroy']);
    Route::post('/circles/{circle}/invitations', [MembershipController::class, 'invite']);
    Route::post('/invitations/{token}/accept', [MembershipController::class, 'accept']);
    Route::patch('/circles/{circle}/members/{membership}', [MembershipController::class, 'update']);
    Route::delete('/circles/{circle}/members/{membership}', [MembershipController::class, 'destroy']);

    // --- Evidence and media ------------------------------------------------
    Route::post('/circles/{circle}/uploads/sign', [EvidenceController::class, 'signUpload']);
    // The same signing and registration steps, batched, so a person can drop a
    // folder instead of adding a tender pack one file at a time.
    Route::post('/circles/{circle}/uploads/sign-batch', [EvidenceController::class, 'signUploadBatch']);
    Route::post('/circles/{circle}/evidence/batch', [EvidenceController::class, 'storeBatch']);
    Route::post('/circles/{circle}/evidence', [EvidenceController::class, 'store']);
    Route::get('/circles/{circle}/evidence', [EvidenceController::class, 'index']);
    Route::get('/evidence/{evidenceItem}', [EvidenceController::class, 'show']);
    Route::get('/evidence/{evidenceItem}/versions', [EvidenceController::class, 'versions']);
    Route::post('/evidence/{evidenceItem}/versions', [EvidenceController::class, 'addVersion']);
    Route::patch('/evidence/{evidenceItem}/access', [EvidenceController::class, 'updateAccess']);
    Route::post('/evidence/{evidenceItem}/review', [EvidenceController::class, 'review']);
    Route::post('/evidence/{evidenceItem}/mark-stale', [EvidenceController::class, 'markStale']);
    Route::get('/evidence-versions/{version}/download-url', [EvidenceController::class, 'downloadUrl']);
    // "What did this change affect" — the question nobody could ask before.
    Route::get('/evidence-versions/{version}/impact', [EvidenceController::class, 'impact']);
    // Search returns a locator, not a filename: page 4, Load Schedule!C2:D2,
    // 00:02:13 — each one directly citable on a claim.
    Route::get('/circles/{circle}/search', [SearchController::class, 'evidence']);

    // --- Claims ------------------------------------------------------------
    Route::post('/circles/{circle}/claims', [ClaimController::class, 'store']);
    Route::get('/circles/{circle}/claims', [ClaimController::class, 'index']);
    Route::get('/claims/{claim}', [ClaimController::class, 'show']);
    Route::post('/claims/{claim}/citations', [ClaimController::class, 'addCitation']);
    Route::post('/claims/{claim}/review', [ClaimController::class, 'review']);
    Route::post('/claims/{claim}/contest', [ClaimController::class, 'review']);

    // --- Decisions and commitments ----------------------------------------
    Route::post('/circles/{circle}/decisions', [DecisionController::class, 'store']);
    Route::get('/circles/{circle}/decisions', [DecisionController::class, 'index']);
    Route::post('/decisions/{decision}/approve', [DecisionController::class, 'approve']);
    Route::post('/decisions/{decision}/reject', [DecisionController::class, 'reject']);
    Route::post('/circles/{circle}/commitments', [DecisionController::class, 'storeCommitment']);
    Route::get('/circles/{circle}/commitments', [DecisionController::class, 'indexCommitments']);
    Route::patch('/commitments/{commitment}', [DecisionController::class, 'updateCommitment']);

    // --- Parties -----------------------------------------------------------
    Route::get('/circles/{circle}/parties', [PartyController::class, 'index']);
    Route::post('/circles/{circle}/parties', [PartyController::class, 'store']);
    Route::patch('/circles/{circle}/parties/{party}', [PartyController::class, 'update']);

    // --- Goals: the workflow spine -----------------------------------------
    Route::get('/circles/{circle}/goals', [GoalController::class, 'index']);
    Route::post('/circles/{circle}/goals', [GoalController::class, 'store']);
    Route::get('/goals/{goal}', [GoalController::class, 'show']);
    Route::patch('/goals/{goal}', [GoalController::class, 'update']);
    Route::patch('/goals/{goal}/progress', [GoalController::class, 'setProgress']);
    Route::post('/goals/{goal}/accept', [GoalController::class, 'accept']);
    // Rescheduling is its own verb because it demands a reason.
    Route::post('/goals/{goal}/reschedule', [GoalController::class, 'reschedule']);
    Route::post('/schedule-changes/{change}/agree', [GoalController::class, 'agreeReschedule']);

    // Documents filed against a node of the plan. Evidence still belongs to
    // the Circle rather than to a goal — this is an index onto the vault, not
    // a second copy of it, and it can never widen who may read an item.
    Route::get('/goals/{goal}/evidence', [GoalEvidenceController::class, 'index']);
    Route::post('/goals/{goal}/evidence', [GoalEvidenceController::class, 'attach']);
    Route::delete('/goals/{goal}/evidence/{evidenceItem}', [GoalEvidenceController::class, 'detach']);

    // --- Comments ----------------------------------------------------------
    // Branches of the plan. A branch holds proposed changes, not goals — see
    // GoalBranchService for why it is a change-set rather than a copy.
    Route::get('/circles/{circle}/branches', [BranchController::class, 'index']);
    Route::post('/circles/{circle}/branches', [BranchController::class, 'store']);
    Route::get('/branches/{branch}', [BranchController::class, 'show']);
    Route::post('/branches/{branch}/changes', [BranchController::class, 'stage']);
    Route::delete('/branch-changes/{change}', [BranchController::class, 'unstage']);
    Route::post('/branches/{branch}/propose', [BranchController::class, 'propose']);
    Route::post('/branches/{branch}/approve', [BranchController::class, 'approve']);
    Route::post('/branches/{branch}/refuse', [BranchController::class, 'refuse']);
    Route::post('/branches/{branch}/rebase', [BranchController::class, 'rebase']);
    Route::post('/branches/{branch}/merge', [BranchController::class, 'merge']);
    Route::post('/branches/{branch}/withdraw', [BranchController::class, 'withdraw']);

    Route::get('/circles/{circle}/threads', [CommentController::class, 'index']);
    Route::get('/circles/{circle}/inbox', [CommentController::class, 'inbox']);
    Route::post('/circles/{circle}/threads', [CommentController::class, 'store']);
    Route::post('/threads/{thread}/comments', [CommentController::class, 'reply']);
    Route::post('/threads/{thread}/share', [CommentController::class, 'share']);
    // Move a general discussion onto the object it turned out to be about.
    // The answer to §20.3: drift is not prevented, it is made recoverable.
    Route::post('/threads/{thread}/attach', [CommentController::class, 'attach']);
    Route::post('/threads/{thread}/resolve', [CommentController::class, 'resolve']);
    Route::post('/comments/{comment}/for-the-record', [CommentController::class, 'markForRecord']);
    Route::post('/circles/{circle}/mentions/read', [CommentController::class, 'readMentions']);
    // Who an @mention can actually reach — asked per visibility, because the
    // answer differs between a private thread and a Circle-wide one.
    Route::get('/circles/{circle}/mentionable', [CommentController::class, 'mentionable']);
    Route::get('/threads/{thread}/mentionable', [CommentController::class, 'threadMentionable']);

    // --- Agent studio and the action queue ---------------------------------
    Route::get('/circles/{circle}/agents', [AgentStudioController::class, 'index']);
    Route::post('/circles/{circle}/agents', [AgentStudioController::class, 'store']);
    Route::patch('/circles/{circle}/agents/{blueprint}', [AgentStudioController::class, 'update']);
    Route::post('/circles/{circle}/agents/{blueprint}/tools', [AgentStudioController::class, 'addTool']);
    Route::post('/circles/{circle}/agents/{blueprint}/instantiate', [AgentStudioController::class, 'instantiate']);
    Route::get('/circles/{circle}/agent-connections', [AgentStudioController::class, 'connections']);
    Route::post('/circles/{circle}/agent-connections', [AgentStudioController::class, 'connect']);
    Route::get('/circles/{circle}/agent-actions', [AgentStudioController::class, 'queue']);
    Route::post('/agent-actions/{action}/approve', [AgentStudioController::class, 'approve']);
    Route::post('/agent-actions/{action}/reject', [AgentStudioController::class, 'reject']);
    Route::post('/agent-actions/{action}/execute', [AgentStudioController::class, 'execute']);
    Route::get('/agent-tools/catalogue', [AgentStudioController::class, 'catalogue']);

    // --- Convening from an engagement of terms (spec 23) --------------------
    // The document is ordinary evidence, uploaded through the routes above; all
    // that is added here is reading it. `show` with an `anchor` re-resolves the
    // same proposal against a different start date without calling a model.
    Route::post('/circles/{circle}/convening', [ConveningController::class, 'store']);
    Route::get('/circles/{circle}/convening', [ConveningController::class, 'show']);
    Route::post('/circles/{circle}/convening/{artifact}/accept', [ConveningController::class, 'accept']);

    // Admission: the counterparty accepts a specific key, and the acceptance
    // becomes a decision in the packet rather than a setting nobody recalls.
    Route::post('/agent-connections/{connection}/admit', [AgentStudioController::class, 'admit']);
    Route::post('/agent-connections/{connection}/revoke', [AgentStudioController::class, 'revokeConnection']);

    /*
    | Open work, engagements and the portable record (spec §21).
    |
    | The three routes below that do NOT sit under /circles are the only ones
    | in this file addressable without a Circle, and they are the deliberate
    | exception to the rule at the top of it: /work answers for somebody who is
    | not in the Circle yet, /records answers for a principal whose Circles may
    | be closed and gone, and /agent-registry answers for somebody deciding
    | whether to hire a mandate. Each is bounded in its service rather than
    | here — DiscoveryService, WorkRecordService::visibleTo, and
    | BlueprintRegistry::listedFor respectively — so the boundary is one
    | readable thing per surface instead of a condition per endpoint.
    */

    // --- The open board ----------------------------------------------------
    Route::get('/work', [WorkOpeningController::class, 'board']);
    Route::get('/work/{opening}', [WorkOpeningController::class, 'showPublic']);
    Route::post('/work/{opening}/applications', [WorkOpeningController::class, 'apply']);
    Route::get('/my/applications', [WorkOpeningController::class, 'myApplications']);
    Route::post('/applications/{application}/withdraw', [WorkOpeningController::class, 'withdrawApplication']);

    // Posting and settling, inside the Circle the work belongs to.
    Route::get('/circles/{circle}/openings', [WorkOpeningController::class, 'index']);
    Route::post('/circles/{circle}/openings', [WorkOpeningController::class, 'store']);
    Route::patch('/openings/{opening}', [WorkOpeningController::class, 'update']);
    Route::post('/openings/{opening}/publish', [WorkOpeningController::class, 'publish']);
    Route::post('/openings/{opening}/close', [WorkOpeningController::class, 'close']);
    // Shortlisting is the act that lets somebody in; awarding is the act that
    // merges the assignment. Two routes because they are two decisions.
    Route::post('/applications/{application}/shortlist', [WorkOpeningController::class, 'shortlist']);
    Route::post('/applications/{application}/decline', [WorkOpeningController::class, 'decline']);
    Route::post('/applications/{application}/award', [WorkOpeningController::class, 'award']);

    // --- Engagements -------------------------------------------------------
    Route::get('/my/engagements', [EngagementController::class, 'mine']);
    Route::get('/circles/{circle}/engagements', [EngagementController::class, 'index']);
    Route::post('/circles/{circle}/engagements', [EngagementController::class, 'store']);
    Route::get('/engagements/{engagement}', [EngagementController::class, 'show']);
    Route::post('/engagements/{engagement}/agree', [EngagementController::class, 'agree']);
    Route::post('/engagements/{engagement}/suspend', [EngagementController::class, 'suspend']);
    Route::post('/engagements/{engagement}/resume', [EngagementController::class, 'resume']);
    Route::post('/engagements/{engagement}/complete', [EngagementController::class, 'complete']);
    Route::post('/engagements/{engagement}/terminate', [EngagementController::class, 'terminate']);
    Route::post('/engagements/{engagement}/meter', [EngagementController::class, 'meter']);

    // --- The portable record ----------------------------------------------
    Route::get('/my/record', [WorkRecordController::class, 'mine']);
    Route::get('/records/{principalType}/{principalId}', [WorkRecordController::class, 'show']);
    Route::post('/records/{record}/attest', [WorkRecordController::class, 'attest']);
    Route::post('/records/{record}/publish', [WorkRecordController::class, 'publish']);
    Route::post('/records/{record}/hide', [WorkRecordController::class, 'hide']);

    // --- Work packages -----------------------------------------------------
    Route::get('/packages', [WorkPackageController::class, 'index']);
    Route::get('/packages/{package}', [WorkPackageController::class, 'show']);
    Route::post('/circles/{circle}/packages', [WorkPackageController::class, 'capture']);
    Route::post('/packages/{package}/instantiate', [WorkPackageController::class, 'instantiate']);
    Route::post('/packages/{package}/fork', [WorkPackageController::class, 'fork']);
    Route::post('/packages/{package}/publish', [WorkPackageController::class, 'publish']);

    // --- Agents for hire ---------------------------------------------------
    Route::get('/agent-registry', [AgentRegistryController::class, 'index']);
    Route::get('/agent-registry/{version}', [AgentRegistryController::class, 'show']);
    Route::get('/circles/{circle}/agents/{blueprint}/versions', [AgentRegistryController::class, 'versions']);
    Route::post('/circles/{circle}/agents/{blueprint}/versions', [AgentRegistryController::class, 'publish']);

    // --- Agent, history and export -----------------------------------------
    // Running an authored agent. The Steward keeps its own route because its
    // mandate is the product's rather than a customer's, and because the demo
    // and the tests address it by name.
    Route::post('/circles/{circle}/agents/{blueprint}/run', [AgentController::class, 'run']);
    Route::post('/circles/{circle}/agent-runs/steward-brief', [AgentController::class, 'stewardBrief']);
    Route::get('/circles/{circle}/agent-runs', [AgentController::class, 'index']);
    Route::get('/agent-runs/{agentRun}', [AgentController::class, 'show']);
    Route::get('/circles/{circle}/history', [HistoryController::class, 'index']);
    Route::get('/circles/{circle}/history/verify', [HistoryController::class, 'verify']);
    Route::post('/circles/{circle}/exports', [HistoryController::class, 'createExport']);
    Route::get('/exports/{export}', [HistoryController::class, 'showExport']);
});
