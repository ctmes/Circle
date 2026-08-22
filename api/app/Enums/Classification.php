<?php

namespace App\Enums;

enum Classification: string
{
    case Public       = 'public';
    case Internal     = 'internal';
    case Confidential = 'confidential';
    case Restricted   = 'restricted';
}
