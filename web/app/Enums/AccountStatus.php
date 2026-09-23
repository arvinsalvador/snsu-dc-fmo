<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case NeedsCorrection = 'NEEDS_CORRECTION';
    case Suspended = 'SUSPENDED';
    case Deactivated = 'DEACTIVATED';
}
