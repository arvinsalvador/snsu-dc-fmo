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

    protected $fillable = ['inspectable_asset_id', 'template_id', 'name', 'description', 'maintenance_type', 'frequency_type', 'interval_value', 'recurrence_config', 'start_date', 'end_date', 'available_time', 'due_time', 'checklist_template', 'reading_template', 'evidence_required', 'verification_required', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['frequency_type' => PreventiveMaintenanceFrequency::class, 'recurrence_config' => 'array', 'start_date' => 'date', 'end_date' => 'date', 'checklist_template' => 'array', 'reading_template' => 'array', 'evidence_required' => 'boolean', 'verification_required' => 'boolean', 'is_active' => 'boolean'];
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
