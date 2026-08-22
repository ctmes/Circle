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
                Permission::AgentRun,
                Permission::ExportCreate,
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
                Permission::AgentRun,
            ],

            self::Contributor => [
                Permission::CircleView,
                Permission::ResourceView,
                Permission::ResourceUpload,
                Permission::ClaimCreate,
                Permission::CommitmentUpdate,
            ],

            self::Viewer => [
                Permission::CircleView,
                Permission::ResourceView,
            ],

            // The agent never receives view/download rights that a human role
            // implies. It reads only through resource.agent_read, and every
            // object it creates is a draft for human confirmation.
            self::Agent => [
                Permission::CircleView,
                Permission::ResourceAgentRead,
                Permission::ClaimCreate,
                Permission::DecisionCreate,
                Permission::CommitmentCreate,
            ],
        };
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), strict: true);
    }
}
