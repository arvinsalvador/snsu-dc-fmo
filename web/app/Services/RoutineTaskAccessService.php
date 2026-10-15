<?php

namespace App\Services;

use App\Models\FmoPersonnel;
use App\Models\InspectionRun;
use App\Models\RoutineTaskOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class RoutineTaskAccessService
{
    /** @param Builder<RoutineTaskOccurrence> $query @return Builder<RoutineTaskOccurrence> */
    public function scopeOccurrences(Builder $query, User $user): Builder
    {
        if ($user->can('routine_tasks.view_all')) {
            return $query;
        }

        $personnel = $this->activePersonnel($user);
        if (! $personnel) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('template.assignments', fn (Builder $assignment) => $assignment
            ->where('fmo_personnel_id', $personnel->id)
            ->whereNull('unassigned_at'));
    }

    public function assignedTo(RoutineTaskOccurrence $occurrence, User $user): bool
    {
        $personnel = $this->activePersonnel($user);
        if (! $personnel) {
            return false;
        }

        return $occurrence->template()->whereHas('assignments', fn (Builder $assignment) => $assignment
            ->where('fmo_personnel_id', $personnel->id)
            ->whereNull('unassigned_at'))->exists();
    }

    /** @param Builder<InspectionRun> $query @return Builder<InspectionRun> */
    public function scopeInspectionRuns(Builder $query, User $user): Builder
    {
        if ($user->can('inspections.view_all')) {
            return $query;
        }

        $personnel = $this->activePersonnel($user);
        if (! $personnel) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('occurrence.template.assignments', fn (Builder $assignment) => $assignment
            ->where('fmo_personnel_id', $personnel->id)
            ->whereNull('unassigned_at'));
    }

    public function assignedToInspection(InspectionRun $run, User $user): bool
    {
        $personnel = $this->activePersonnel($user);
        if (! $personnel) {
            return false;
        }

        return $run->occurrence()->whereHas('template.assignments', fn (Builder $assignment) => $assignment
            ->where('fmo_personnel_id', $personnel->id)
            ->whereNull('unassigned_at'))->exists();
    }

    private function activePersonnel(User $user): ?FmoPersonnel
    {
        $personnel = $user->personnelProfile()->with('user')->first();

        return $personnel?->isAssignable() ? $personnel : null;
    }
}
