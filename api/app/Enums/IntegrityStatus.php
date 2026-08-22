<?php

namespace App\Enums;

enum IntegrityStatus: string
{
    /** Current binary hash matches the recorded hash. */
    case Intact = 'intact';
    /** A newer version exists. */
    case Superseded = 'superseded';
    /** File/version mismatch or integrity check failure. */
    case Changed = 'changed';
    /** Integrity not yet computed. */
    case Unknown = 'unknown';
}
