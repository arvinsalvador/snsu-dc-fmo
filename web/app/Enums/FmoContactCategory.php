<?php

namespace App\Enums;

enum FmoContactCategory: string
{
    case General = 'GENERAL_FACILITIES_INQUIRY';
    case ExistingWorkOrder = 'EXISTING_WORK_ORDER_QUESTION';
    case Urgent = 'URGENT_FACILITIES_CONCERN';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General Facilities Inquiry',
            self::ExistingWorkOrder => 'Question About Existing Work Order',
            self::Urgent => 'Urgent Facilities Concern',
            self::Other => 'Other',
        };
    }
}
