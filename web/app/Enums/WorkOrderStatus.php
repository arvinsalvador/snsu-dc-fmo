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
    case Assigned = 'ASSIGNED';
    case ForAssessment = 'FOR_ASSESSMENT';
    case AssessmentReview = 'ASSESSMENT_REVIEW';
    case ReadyForWork = 'READY_FOR_WORK';
    case Cancelled = 'CANCELLED';
}
