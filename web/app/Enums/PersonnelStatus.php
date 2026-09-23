<?php

namespace App\Enums;

enum PersonnelStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case OnLeave = 'ON_LEAVE';
    case Unavailable = 'UNAVAILABLE';
}
