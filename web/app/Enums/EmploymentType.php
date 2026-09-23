<?php

namespace App\Enums;

enum EmploymentType: string
{
    case JobOrder = 'JOB_ORDER';
    case Regular = 'REGULAR';
    case Contractual = 'CONTRACTUAL';
    case Casual = 'CASUAL';
    case Other = 'OTHER';
}
