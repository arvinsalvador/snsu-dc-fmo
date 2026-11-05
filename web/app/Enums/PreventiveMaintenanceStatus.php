<?php

namespace App\Enums;

enum PreventiveMaintenanceStatus: string
{
    case Upcoming = 'UPCOMING';
    case Available = 'AVAILABLE';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Overdue = 'OVERDUE';
    case Cancelled = 'CANCELLED';

    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }
}
