<?php

namespace App\Enums;

enum AssessmentOutcome: string
{
    case ReadyForWork = 'READY_FOR_WORK';
    case NeedsInformation = 'NEEDS_INFORMATION';
    case NeedsFurtherInvestigation = 'NEEDS_FURTHER_INVESTIGATION';
    case NeedsMaterials = 'NEEDS_MATERIALS';
    case MaterialsUnavailable = 'MATERIALS_UNAVAILABLE';
    case BeyondFmoScope = 'BEYOND_FMO_SCOPE';
    case ExternalAssistanceRequired = 'EXTERNAL_ASSISTANCE_REQUIRED';
    case RecommendCancellation = 'RECOMMEND_CANCELLATION';
}
