<?php

namespace App\Enums;

enum PreventiveMaintenanceFrequency: string
{
    case Daily = 'DAILY';
    case Weekly = 'WEEKLY';
    case Monthly = 'MONTHLY';
    case Quarterly = 'QUARTERLY';
    case Semiannual = 'SEMIANNUAL';
    case Annual = 'ANNUAL';
    case Custom = 'CUSTOM';
}
