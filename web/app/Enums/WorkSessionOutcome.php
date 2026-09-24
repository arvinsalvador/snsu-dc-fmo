<?php

namespace App\Enums;

enum WorkSessionOutcome: string
{
    case Continuation = 'CONTINUATION';
    case Paused = 'PAUSED';
    case WaitingForMaterials = 'WAITING_FOR_MATERIALS';
    case NeedsFurtherInvestigation = 'NEEDS_FURTHER_INVESTIGATION';
    case ContributionComplete = 'CONTRIBUTION_COMPLETE';
    case WorkOrderCompletionSubmitted = 'WORK_ORDER_COMPLETION_SUBMITTED';
}
