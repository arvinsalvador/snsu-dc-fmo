<?php

namespace App\Enums;

enum WorkOrderStatus: string
{
    case Submitted = 'SUBMITTED';
    case ForScreening = 'FOR_SCREENING';
    case NeedsInformation = 'NEEDS_INFORMATION';
    case ForApproval = 'FOR_APPROVAL';
    case Approved = 'APPROVED';
    case Disapproved = 'DISAPPROVED';
}
