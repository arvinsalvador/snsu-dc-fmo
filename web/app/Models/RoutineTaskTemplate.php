<?php

namespace App\Models;

use App\Enums\RoutineFrequency;
use App\Enums\RoutineTaskType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutineTaskTemplate extends Model
{
    use HasUlids;

    protected $fillable = [
        'title', 'description', 'task_type', 'frequency', 'recurrence_config', 'campus_id', 'building_id',
        'floor_id', 'building_location_id', 'exact_placement', 'asset_type_id', 'guided_description_profile_id',
        'start_date', 'end_date', 'due_time', 'evidence_required', 'requires_review', 'assignment_note', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'task_type' => RoutineTaskType::class, 'frequency' => RoutineFrequency::class,
            'recurrence_config' => 'array', 'start_date' => 'date', 'end_date' => 'date',
            'evidence_required' => 'boolean', 'requires_review' => 'boolean', 'is_active' => 'boolean',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BuildingLocation::class, 'building_location_id');
    }

    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class);
    }

    public function guidedProfile(): BelongsTo
    {
        return $this->belongsTo(GuidedDescriptionProfile::class, 'guided_description_profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RoutineTaskTemplateAssignment::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(RoutineTaskOccurrence::class);
    }

    public function activeAssignees(): BelongsToMany
    {
        return $this->belongsToMany(FmoPersonnel::class, 'routine_task_template_assignments')
            ->wherePivotNull('unassigned_at')->withPivot(['id', 'assigned_at', 'assigned_by'])->withTimestamps();
    }
}
