<?php

namespace App\Enums;

enum AssetCondition: string
{
    case Pristine = 'PRISTINE';
    case Good = 'GOOD';
    case Fair = 'FAIR';
    case NeedsRepair = 'NEEDS_REPAIR';
    case Damaged = 'DAMAGED';
    case Broken = 'BROKEN';
    case Unusable = 'UNUSABLE';
}
