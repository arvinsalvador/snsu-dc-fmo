<?php

namespace App\Enums;

enum AssetStatus: string
{
    case Active = 'ACTIVE';
    case OutOfService = 'OUT_OF_SERVICE';
    case Retired = 'RETIRED';
    case Missing = 'MISSING';
}
