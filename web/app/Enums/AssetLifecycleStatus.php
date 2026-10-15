<?php

namespace App\Enums;

enum AssetLifecycleStatus: string
{
    case Active = 'ACTIVE';
    case UnderRepair = 'UNDER_REPAIR';
    case ForDisposalReview = 'FOR_DISPOSAL_REVIEW';
    case ApprovedForDisposal = 'APPROVED_FOR_DISPOSAL';
    case Disposed = 'DISPOSED';
    case Retired = 'RETIRED';
}
