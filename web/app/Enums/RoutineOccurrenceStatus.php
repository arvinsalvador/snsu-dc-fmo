<?php

namespace App\Enums;

enum RoutineOccurrenceStatus: string
{
    case Upcoming = 'UPCOMING';
    case Due = 'DUE';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Overdue = 'OVERDUE';
    case CompletedLate = 'COMPLETED_LATE';
    case PartiallyCompleted = 'PARTIALLY_COMPLETED';
    case UnableToComplete = 'UNABLE_TO_COMPLETE';
    case WaitingForSupplies = 'WAITING_FOR_SUPPLIES';

    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::CompletedLate, self::UnableToComplete], true);
    }
}
