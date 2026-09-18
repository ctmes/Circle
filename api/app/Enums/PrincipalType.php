<?php

namespace App\Enums;

/**
 * Who an engagement or a record is about (spec §21.2, §21.3).
 *
 * Four kinds, and which ones are legal depends on where the enum is used —
 * deliberately, because the two contexts are asking different questions.
 *
 * An **engagement** names who is doing the work right now: a person, or an
 * agent *instance*, which is a blueprint bound to one Circle and is the
 * identity AccessGate actually authorises. `organisation` is never an
 * engagement principal — the company is the contractor party, and expressing
 * it twice would let the two disagree.
 *
 * A **record** names who carries the history afterwards: a person, a company,
 * or an agent *blueprint*. Not an instance, because an instance dies with its
 * Circle and a record that died with its Circle is the exact failure §21.3
 * exists to fix. WorkRecordService walks instance to blueprint when it
 * compiles, and that walk is the whole reason both cases exist here.
 */
enum PrincipalType: string
{
    case User           = 'user';
    case Organisation   = 'organisation';
    case AgentInstance  = 'agent_instance';
    case AgentBlueprint = 'agent_blueprint';

    public function isAgent(): bool
    {
        return in_array($this, [self::AgentInstance, self::AgentBlueprint], strict: true);
    }

    /** Whether an engagement may name this kind of principal. */
    public function canBeEngaged(): bool
    {
        return in_array($this, [self::User, self::AgentInstance], strict: true);
    }

    /** Whether a durable record may be kept against this kind of principal. */
    public function canHoldARecord(): bool
    {
        return in_array($this, [self::User, self::Organisation, self::AgentBlueprint], strict: true);
    }

    /** The model class this addresses, for resolving a principal by id. */
    public function modelClass(): string
    {
        return match ($this) {
            self::User           => \App\Models\User::class,
            self::Organisation   => \App\Models\Organisation::class,
            self::AgentInstance  => \App\Models\AgentInstance::class,
            self::AgentBlueprint => \App\Models\AgentBlueprint::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::User           => 'person',
            self::Organisation   => 'company',
            self::AgentInstance  => 'agent',
            self::AgentBlueprint => 'agent',
        };
    }
}
