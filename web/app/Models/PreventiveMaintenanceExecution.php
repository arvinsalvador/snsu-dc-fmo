<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PreventiveMaintenanceExecution extends Model
{
    use HasUlids;

    protected $fillable = ['preventive_maintenance_occurrence_id', 'performed_by_personnel_id', 'performed_by_user_id', 'started_at', 'completed_at', 'structured_actions', 'condition_before', 'condition_after', 'findings', 'action_performed', 'generated_summary', 'remarks', 'materials_used', 'recommended_corrective_action', 'component_replacements'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime', 'structured_actions' => 'array', 'component_replacements' => 'array'];
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(PreventiveMaintenanceOccurrence::class, 'preventive_maintenance_occurrence_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(FmoPersonnel::class, 'performed_by_personnel_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    public function checklistResults(): HasMany
    {
        return $this->hasMany(PreventiveMaintenanceChecklistResult::class)->orderBy('display_order');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(PreventiveMaintenanceReading::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(OperationalAttachment::class, 'attachable');
    }
}
