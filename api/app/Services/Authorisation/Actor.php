<?php

namespace App\Services\Authorisation;

use App\Enums\ActorType;

/**
 * Anything the gate can authorise. Implemented by User and AgentInstance so an
 * agent is a first-class principal rather than a user acting in disguise — the
 * agent never borrows user credentials (spec §2).
 */
interface Actor
{
    public function actorType(): ActorType;

    public function actorId(): string;

    public function actorLabel(): string;
}
