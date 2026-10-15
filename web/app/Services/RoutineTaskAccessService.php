<?php

namespace App\Services;

use App\Models\FmoPersonnel;
use App\Models\InspectionItem;
use App\Models\InspectionRun;
use App\Models\RoutineTaskOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

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

        return $query->where(function (Builder $scope) use ($personnel): void {
            $scope->whereHas('inspectionRun.items.assignments', fn (Builder $assignment) => $assignment
                ->where('fmo_personnel_id', $personnel->id)->whereNull('unassigned_at'))
                ->orWhere(function (Builder $general) use ($personnel): void {
                    $general->whereDoesntHave('inspectionRun')->whereHas('template.assignments', fn (Builder $assignment) => $assignment
                        ->where('fmo_personnel_id', $personnel->id)->whereNull('unassigned_at'));
                });
        });
    }

    public function assignedTo(RoutineTaskOccurrence $occurrence, User $user): bool
    {
        $personnel = $this->activePersonnel($user);
        if (! $personnel) {
            return false;
        }

        if ($occurrence->inspectionRun()->exists()) {
            return $occurrence->inspectionRun()->whereHas('items.assignments', fn (Builder $assignment) => $assignment
                ->where('fmo_personnel_id', $personnel->id)->whereNull('unassigned_at'))->exists();
        }

        return $occurrence->template()->whereHas('assignments', fn (Builder $assignment) => $assignment
            ->where('fmo_personnel_id', $personnel->id)->whereNull('unassigned_at'))->exists();
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

        return $query->whereHas('items.assignments', fn (Builder $assignment) => $assignment
            ->where('fmo_personnel_id', $personnel->id)->whereNull('unassigned_at'));
    }

    public function assignedToInspection(InspectionRun $run, User $user): bool
    {
        $personnel = $this->activePersonnel($user);
        if (! $personnel) {
            return false;
        }

        return $run->items()->whereHas('assignments', fn (Builder $assignment) => $assignment
            ->where('fmo_personnel_id', $personnel->id)->whereNull('unassigned_at'))->exists();
    }

    /** @return Builder<InspectionItem>|\Illuminate\Database\Eloquent\Relations\Relation<InspectionItem, *, *> */
    public function scopeInspectionItems(Builder|Relation $query, User $user): Builder|Relation
    {
        $personnel = $this->activePersonnel($user);
        if (! $personnel) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('assignments', fn (Builder $assignment) => $assignment
            ->where('fmo_personnel_id', $personnel->id)->whereNull('unassigned_at'));
    }

    public function assignedToInspectionItem(InspectionItem $item, User $user): bool
    {
        $personnel = $this->activePersonnel($user);
        if (! $personnel) {
            return false;
        }

        if ($item->relationLoaded('activeAssignees')) {
            return $item->activeAssignees->contains('id', $personnel->id);
        }

        return $item->assignments()->where('fmo_personnel_id', $personnel->id)->whereNull('unassigned_at')->exists();
    }

    public function canPerformInspectionItem(InspectionItem $item, User $user): bool
    {
        return $user->can('assets.inspect')
            && $user->can('inspections.perform')
            && $this->assignedToInspectionItem($item, $user)
            && $item->status !== 'INSPECTED';
    }

    public function activePersonnel(User $user): ?FmoPersonnel
    {
        $personnel = $user->personnelProfile()->with('user')->first();

        return $personnel?->isAssignable() ? $personnel : null;
    }
}
