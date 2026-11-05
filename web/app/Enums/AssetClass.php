<?php

namespace App\Enums;

enum AssetClass: string
{
    case Equipment = 'EQUIPMENT';
    case FacilityComponent = 'FACILITY_COMPONENT';
    case UtilitySystem = 'UTILITY_SYSTEM';
    case SafetyEquipment = 'SAFETY_EQUIPMENT';
    case Vehicle = 'VEHICLE';
    case Other = 'OTHER';
}
