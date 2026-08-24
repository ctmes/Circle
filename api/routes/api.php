<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AgentStudioController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CircleController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\DecisionController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\OrganisationController;
use App\Http\Controllers\Api\PartyController;
use Illuminate\Support\Facades\Route;

/*
| Circle API (spec §15).
|
| Every route below the auth middleware resolves its Circle and runs an
| AccessGate check inside the controller. There is deliberately no "list all
| evidence in the organisation" style route — nothing is reachable except
| through a Circle the caller belongs to.
*/

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // --- Organisations -----------------------------------------------------
    Route::get('/organisations', [OrganisationController::class, 'index']);
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
    Route::post('/circles/{circle}/invitations', [MembershipController::class, 'invite']);
    Route::post('/invitations/{token}/accept', [MembershipController::class, 'accept']);
    Route::patch('/circles/{circle}/members/{membership}', [MembershipController::class, 'update']);
    Route::delete('/circles/{circle}/members/{membership}', [MembershipController::class, 'destroy']);

    // --- Evidence and media ------------------------------------------------
    Route::post('/circles/{circle}/uploads/sign', [EvidenceController::class, 'signUpload']);
    Route::post('/circles/{circle}/evidence', [EvidenceController::class, 'store']);
    Route::get('/circles/{circle}/evidence', [EvidenceController::class, 'index']);
    Route::get('/evidence/{evidenceItem}', [EvidenceController::class, 'show']);
    Route::get('/evidence/{evidenceItem}/versions', [EvidenceController::class, 'versions']);
    Route::post('/evidence/{evidenceItem}/versions', [EvidenceController::class, 'addVersion']);
    Route::patch('/evidence/{evidenceItem}/access', [EvidenceController::class, 'updateAccess']);
    Route::post('/evidence/{evidenceItem}/review', [EvidenceController::class, 'review']);
    Route::post('/evidence/{evidenceItem}/mark-stale', [EvidenceController::class, 'markStale']);
    Route::get('/evidence-versions/{version}/download-url', [EvidenceController::class, 'downloadUrl']);

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

    // --- Comments ----------------------------------------------------------
    Route::get('/circles/{circle}/threads', [CommentController::class, 'index']);
    Route::get('/circles/{circle}/inbox', [CommentController::class, 'inbox']);
    Route::post('/circles/{circle}/threads', [CommentController::class, 'store']);
    Route::post('/threads/{thread}/comments', [CommentController::class, 'reply']);
    Route::post('/threads/{thread}/share', [CommentController::class, 'share']);
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

    // --- Agent, history and export -----------------------------------------
    Route::post('/circles/{circle}/agent-runs/steward-brief', [AgentController::class, 'stewardBrief']);
    Route::get('/circles/{circle}/agent-runs', [AgentController::class, 'index']);
    Route::get('/agent-runs/{agentRun}', [AgentController::class, 'show']);
    Route::get('/circles/{circle}/history', [HistoryController::class, 'index']);
    Route::get('/circles/{circle}/history/verify', [HistoryController::class, 'verify']);
    Route::post('/circles/{circle}/exports', [HistoryController::class, 'createExport']);
    Route::get('/exports/{export}', [HistoryController::class, 'showExport']);
});
