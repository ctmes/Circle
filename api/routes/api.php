<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CircleController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\DecisionController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\OrganisationController;
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

    // --- Agent, history and export -----------------------------------------
    Route::post('/circles/{circle}/agent-runs/steward-brief', [AgentController::class, 'stewardBrief']);
    Route::get('/circles/{circle}/agent-runs', [AgentController::class, 'index']);
    Route::get('/agent-runs/{agentRun}', [AgentController::class, 'show']);
    Route::get('/circles/{circle}/history', [HistoryController::class, 'index']);
    Route::get('/circles/{circle}/history/verify', [HistoryController::class, 'verify']);
    Route::post('/circles/{circle}/exports', [HistoryController::class, 'createExport']);
    Route::get('/exports/{export}', [HistoryController::class, 'showExport']);
});
