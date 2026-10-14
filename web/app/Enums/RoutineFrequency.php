<?php

namespace App\Enums;

enum RoutineFrequency: string
{
    case Daily = 'DAILY';
    case Weekly = 'WEEKLY';
    case Monthly = 'MONTHLY';
}
