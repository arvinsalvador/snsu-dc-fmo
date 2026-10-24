<?php

namespace App\Services;

use App\Models\RoutineTaskOccurrence;
use Illuminate\Validation\ValidationException;

class RoutineTaskAvailabilityService
{
    public function ensureStartable(RoutineTaskOccurrence $occurrence): void
    {
        $this->ensureAvailable($occurrence);
        if (! $occurrence->isStartable()) {
            throw ValidationException::withMessages([
                'task' => 'This task cannot be started because it is already in progress or has been closed.',
            ]);
        }
    }

    public function ensureActionable(RoutineTaskOccurrence $occurrence): void
    {
        $this->ensureAvailable($occurrence);
        if (! $occurrence->isActionable()) {
            throw ValidationException::withMessages([
                'task' => 'This task has already been completed and is read-only.',
            ]);
        }
    }

    public function ensureAvailable(RoutineTaskOccurrence $occurrence): void
    {
        if (! $occurrence->isAvailable()) {
            throw ValidationException::withMessages([
                'task' => 'This task is not available to start yet. It will become available on '.$occurrence->availableAt()->format('F j, Y').'.',
            ]);
        }
    }
}
