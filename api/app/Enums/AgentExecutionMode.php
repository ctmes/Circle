<?php

namespace App\Enums;

/**
 * How far an agent may go, independent of the tools it declares.
 *
 * Checked in AccessGate before the tool list is consulted, so this single
 * column is a working kill switch: dropping an agent to ReadOnly stops every
 * action it could take, everywhere, without editing its tools.
 */
enum AgentExecutionMode: string
{
    /** Reads permitted context. Writes nothing, anywhere. */
    case ReadOnly = 'read_only';

    /** May create drafts for humans — claims, goals, decision requests. */
    case Propose = 'propose';

    /** May run tools with side effects, subject to the approval gate. */
    case Execute = 'execute';

    public function canWrite(): bool
    {
        return $this !== self::ReadOnly;
    }

    public function canExecute(): bool
    {
        return $this === self::Execute;
    }

    /** Permissions this mode allows a blueprint to declare at all. */
    public function ceiling(): array
    {
        return match ($this) {
            self::ReadOnly => [
                Permission::CircleView,
                Permission::ResourceAgentRead,
            ],
            self::Propose => [
                Permission::CircleView,
                Permission::ResourceAgentRead,
                Permission::ClaimCreate,
                Permission::DecisionCreate,
                Permission::CommitmentCreate,
                Permission::GoalCreate,
                Permission::CommentCreate,
            ],
            self::Execute => [
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
}
