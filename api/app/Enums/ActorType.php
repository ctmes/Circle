<?php

namespace App\Enums;

enum ActorType: string
{
    case User   = 'user';
    case Agent  = 'agent';
    case System = 'system';
}
