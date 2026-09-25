<?php

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\BuildingLocation;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportingService
{
    public function filters(Request $request): array
    {
        $data = $request->validate([
            'number' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::enum(WorkOrderStatus::class)],
            'requester' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', Rule::exists('work_order_categories', 'id')],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')],
            'building_id' => ['nullable', 'integer', Rule::exists('buildings', 'id')],
            'location_id' => ['nullable', 'integer', Rule::exists('building_locations', 'id')],
            'personnel_id' => ['nullable', 'ulid', Rule::exists('fmo_personnel', 'id')],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'completed_from' => ['nullable', 'date_format:Y-m-d'],
            'completed_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:completed_from'],
        ]);
        if (isset($data['campus_id'], $data['building_id']) &&
            ! Building::whereKey($data['building_id'])->where('campus_id', $data['campus_id'])->exists()) {
            throw ValidationException::withMessages(['building_id' => 'The building does not belong to the selected campus.']);
        }
        if (isset($data['location_id'])) {
            $location = BuildingLocation::find($data['location_id']);
            if (isset($data['building_id']) && $location->building_id !== (int) $data['building_id']) {
                throw ValidationException::withMessages(['location_id' => 'The location does not belong to the selected building.']);
            }
            if (isset($data['campus_id']) && ! Building::whereKey($location->building_id)->where('campus_id', $data['campus_id'])->exists()) {
                throw ValidationException::withMessages(['location_id' => 'The location does not belong to the selected campus.']);
            }
        }
        foreach ([['from', 'to'], ['completed_from', 'completed_to']] as [$start, $end]) {
            if (isset($data[$start], $data[$end]) &&
                CarbonImmutable::parse($data[$start])->diffInDays(CarbonImmutable::parse($data[$end])) > config('reporting.maximum_range_days')) {
                throw ValidationException::withMessages([$end => 'Select a date range of at most one year.']);
            }
        }

        return $data;
    }

    public function canManage(User $user): bool
    {
        return $user->can('reports.view_work_orders') && $user->can('work_orders.view_all');
    }

    public function scope(User $user): Builder
    {
        if ($this->canManage($user)) {
            return WorkOrder::query();
        }
        abort_unless($user->can('work_orders.view_own') || $user->can('work_orders.view_assigned'), 403);
        $personnelId = $user->personnelProfile?->id;
        if (! $user->can('work_orders.view_own') && ! $personnelId) {
            return WorkOrder::whereRaw('1 = 0');
        }

        return WorkOrder::where(function (Builder $query) use ($user, $personnelId): void {
            if ($user->can('work_orders.view_own')) {
                $query->where('requester_id', $user->id);
            }
            if ($user->can('work_orders.view_assigned') && $personnelId) {
                $method = $user->can('work_orders.view_own') ? 'orWhereHas' : 'whereHas';
                $query->{$method}('assignments', fn (Builder $assignment) => $assignment->where('fmo_personnel_id', $personnelId));
            }
        });
    }

    public function dashboardScope(User $user): Builder
    {
        if ($this->canManage($user)) {
            return WorkOrder::query();
        }

        abort_unless($user->can('work_orders.view_own') || $user->can('work_orders.view_assigned'), 403);
        $own = $user->can('work_orders.view_own');
        $personnelId = $user->can('work_orders.view_assigned') ? $user->personnelProfile?->id : null;
        if (! $own && ! $personnelId) {
            return WorkOrder::whereRaw('1 = 0');
        }

        return WorkOrder::where(function (Builder $query) use ($user, $own, $personnelId): void {
            if ($own) {
                $query->where('requester_id', $user->id);
            }
            if ($personnelId) {
                $method = $own ? 'orWhereHas' : 'whereHas';
                $query->{$method}('activeAssignments', fn (Builder $assignment) => $assignment->where('fmo_personnel_id', $personnelId));
            }
        });
    }

    public function query(User $user, array $filters): Builder
    {
        $query = $this->scope($user);
        if ($this->canManage($user)) {
            if (! empty($filters['personnel_id'])) {
                abort_unless($user->can('reports.view_personnel_history'), 403);
            }
            if (! empty($filters['campus_id']) || ! empty($filters['building_id']) || ! empty($filters['location_id'])) {
                abort_unless($user->can('reports.view_location_history'), 403);
            }
        }
        if (! $this->canManage($user)) {
            abort_if(! empty($filters['requester']), 403);
            if (! empty($filters['personnel_id'])) {
                abort_unless($user->personnelProfile?->id === $filters['personnel_id'], 403);
            }
        }
        if (! empty($filters['number'])) {
            $term = addcslashes($filters['number'], '%_\\');
            $query->where('work_order_number', 'like', "%{$term}%");
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['requester'])) {
            $term = addcslashes($filters['requester'], '%_\\');
            $query->whereHas('requester', fn (Builder $person) => $person->where('name', 'like', "%{$term}%"));
        }
        if (! empty($filters['category_id'])) {
            $query->where('work_order_category_id', $filters['category_id']);
        }
        if (! empty($filters['campus_id'])) {
            $query->where('campus_id', $filters['campus_id']);
        }
        if (! empty($filters['building_id'])) {
            $query->where('building_id', $filters['building_id']);
        }
        if (! empty($filters['location_id'])) {
            $query->where('building_location_id', $filters['location_id']);
        }
        if (! empty($filters['personnel_id'])) {
            $query->whereHas('assignments', fn (Builder $assignment) => $assignment->where('fmo_personnel_id', $filters['personnel_id']));
        }
        $this->date($query, 'submitted_at', $filters['from'] ?? null, $filters['to'] ?? null);
        $this->date($query, 'verified_at', $filters['completed_from'] ?? null, $filters['completed_to'] ?? null);

        return $query;
    }

    public function date(Builder $query, string $column, ?string $from, ?string $to): void
    {
        // The existing Laravel/MySQL application writes naive timestamps in Asia/Manila.
        if ($from) {
            $query->where($column, '>=', CarbonImmutable::createFromFormat('Y-m-d', $from, 'Asia/Manila')->startOfDay()->toDateTimeString());
        }
        if ($to) {
            $query->where($column, '<=', CarbonImmutable::createFromFormat('Y-m-d', $to, 'Asia/Manila')->endOfDay()->toDateTimeString());
        }
    }
}
