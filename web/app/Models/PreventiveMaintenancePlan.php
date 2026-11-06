<?php

namespace App\Models;

use App\Enums\PreventiveMaintenanceFrequency;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreventiveMaintenancePlan extends Model
{
    use HasUlids;

    protected $fillable = ['inspectable_asset_id', 'template_id', 'name', 'description', 'maintenance_type', 'trigger_type', 'frequency_type', 'interval_value', 'odometer_interval_km', 'last_service_odometer_km', 'next_due_odometer_km', 'due_soon_km', 'recurrence_config', 'start_date', 'end_date', 'available_time', 'due_time', 'checklist_template', 'reading_template', 'evidence_required', 'verification_required', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['frequency_type' => PreventiveMaintenanceFrequency::class, 'recurrence_config' => 'array', 'start_date' => 'date', 'end_date' => 'date', 'checklist_template' => 'array', 'reading_template' => 'array', 'evidence_required' => 'boolean', 'verification_required' => 'boolean', 'is_active' => 'boolean'];
    }

    public function isOdometerBased(): bool
    {
        return $this->trigger_type === 'ODOMETER';
    }

    public function dueSoonThreshold(): int
    {
        return (int) ($this->due_soon_km ?? config('maintenance.odometer_due_soon_km', 500));
    }

    /**
     * Odometer position of this plan against the asset's current reading.
     *
     * @return array{state: string, remaining: ?int}|null
     */
    public function odometerStatus(?int $current = null): ?array
    {
        if (! $this->isOdometerBased() || $this->next_due_odometer_km === null) {
            return null;
        }
        $current ??= $this->asset?->current_odometer_km;
        if ($current === null) {
            return ['state' => 'UPCOMING', 'remaining' => null];
        }
        $remaining = (int) $this->next_due_odometer_km - $current;
        $state = match (true) {
            $remaining < 0 => 'OVERDUE',
            $remaining === 0 => 'DUE',
            $remaining <= $this->dueSoonThreshold() => 'DUE_SOON',
            default => 'UPCOMING',
        };

        return ['state' => $state, 'remaining' => $remaining];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InspectableAsset::class, 'inspectable_asset_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PreventiveMaintenanceTemplate::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(PreventiveMaintenanceOccurrence::class);
    }

    public function activeAssignees(): BelongsToMany
    {
        return $this->belongsToMany(FmoPersonnel::class, 'preventive_maintenance_plan_assignments')
            ->wherePivotNull('unassigned_at')->withPivot(['assigned_at', 'assigned_by'])->withTimestamps();
    }
}
