<?php

namespace App\Services;

use App\Models\FmoPersonnel;
use App\Models\PreventiveMaintenanceOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PreventiveMaintenanceAccessService
{
    /** @param Builder<PreventiveMaintenanceOccurrence> $query */
    public function scope(Builder $query, User $user): Builder
    {
        if ($user->can('preventive_maintenance.view_all')) {
            return $query;
        }
        $personnel = $this->personnel($user);

        return $personnel
            ? $query->whereHas('assignees', fn (Builder $assignees) => $assignees->whereKey($personnel->id))
            : $query->whereRaw('1 = 0');
    }

    public function assignedTo(PreventiveMaintenanceOccurrence $occurrence, User $user): bool
    {
        $personnel = $this->personnel($user);

        return $personnel && $occurrence->assignees()->whereKey($personnel->id)->exists();
    }

    public function canPerform(PreventiveMaintenanceOccurrence $occurrence, User $user): bool
    {
        return $user->can('preventive_maintenance.perform') && $this->assignedTo($occurrence, $user);
    }

    public function personnel(User $user): ?FmoPersonnel
    {
        return $user->personnelProfile()->where('personnel_status', 'ACTIVE')->whereNull('archived_at')->first();
    }
}
