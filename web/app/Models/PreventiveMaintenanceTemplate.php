<?php

namespace App\Models;

use App\Enums\PreventiveMaintenanceFrequency;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreventiveMaintenanceTemplate extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'description', 'maintenance_type', 'frequency_type', 'interval_value', 'recurrence_config', 'checklist_template', 'reading_template', 'recommended_skill_ids', 'evidence_required', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['frequency_type' => PreventiveMaintenanceFrequency::class, 'recurrence_config' => 'array', 'checklist_template' => 'array', 'reading_template' => 'array', 'recommended_skill_ids' => 'array', 'evidence_required' => 'boolean', 'is_active' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(PreventiveMaintenancePlan::class, 'template_id');
    }
}
