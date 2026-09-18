<?php

namespace App\Enums;

/**
 * MVP Circle roles (spec §4). Role grants are the *first* gate only; the
 * effective permission is the intersection of Circle membership, resource
 * policy and action policy — see App\Services\Authorisation\AccessGate.
 */
enum CircleRole: string
{
    case Owner       = 'owner';
    case Approver    = 'approver';
    case Reviewer    = 'reviewer';
    case Contributor = 'contributor';
    case Viewer      = 'viewer';
    case Agent       = 'agent';

    /** @return array<int, Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),

            self::Approver => [
                Permission::CircleView,
                Permission::ResourceView,
                Permission::ResourceDownload,
                Permission::ResourceUpload,
                Permission::ClaimCreate,
                Permission::ClaimReview,
                Permission::ClaimApprove,
                Permission::DecisionCreate,
                Permission::DecisionApprove,
                Permission::CommitmentCreate,
                Permission::CommitmentUpdate,
                Permission::GoalCreate,
                Permission::GoalUpdate,
                Permission::GoalAccept,
                Permission::GoalBranch,
                Permission::GoalMerge,
                Permission::CommentCreate,
                Permission::AgentRun,
                Permission::AgentApprove,
                Permission::ExportCreate,
                // May post work and capture the shape of it. Awarding,
                // engaging and attesting a record all bind the company to a
                // counterparty, so they stay with the owner.
                Permission::WorkPost,
                Permission::PackagePublish,
            ],

            self::Reviewer => [
                Permission::CircleView,
                Permission::ResourceView,
                Permission::ResourceDownload,
                Permission::ResourceUpload,
                Permission::ClaimCreate,
                Permission::ClaimReview,
                Permission::CommitmentCreate,
                Permission::CommitmentUpdate,
                Permission::GoalUpdate,
                Permission::GoalBranch,
                Permission::CommentCreate,
                Permission::AgentRun,
            ],

            self::Contributor => [
                Permission::CircleView,
                Permission::ResourceView,
                Permission::ResourceUpload,
                Permission::ClaimCreate,
                Permission::CommitmentUpdate,
                Permission::GoalUpdate,
                // May propose a revision, but not agree to one. Drafting is
                // how someone without authority still gets heard.
                Permission::GoalBranch,
                Permission::CommentCreate,
            ],

            self::Viewer => [
                Permission::CircleView,
                Permission::ResourceView,
            ],

            // The agent never receives view/download rights that a human role
            // implies. It reads only through resource.agent_read. This list is
            // a *floor*, not a grant: the blueprint's execution_mode and
            // allowed_actions narrow it, and an agent holds nothing it has not
            // declared. Anything with a side effect still lands in the
            // agent_actions ledger and waits for a human.
            self::Agent => [
                Permission::CircleView,
                Permission::ResourceAgentRead,
                Permission::ClaimCreate,
                Permission::DecisionCreate,
                Permission::CommitmentCreate,
                Permission::CommitmentUpdate,
                Permission::GoalCreate,
                Permission::GoalUpdate,
                Permission::CommentCreate,
                Permission::AgentExecute,
            ],
        };
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), strict: true);
    }
}
