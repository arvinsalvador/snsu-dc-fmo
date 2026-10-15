<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class InspectionItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'inspection_run_id', 'inspectable_asset_id', 'status', 'inspector_personnel_id', 'inspector_user_id',
        'inspected_at', 'result', 'condition', 'recommended_action', 'checklist_snapshot', 'asset_context',
        'checklist_results', 'structured_actions', 'generated_summary', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'inspected_at' => 'datetime', 'checklist_snapshot' => 'array', 'asset_context' => 'array',
            'checklist_results' => 'array', 'structured_actions' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(InspectionRun::class, 'inspection_run_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InspectableAsset::class, 'inspectable_asset_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(FmoPersonnel::class, 'inspector_personnel_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(OperationalAttachment::class, 'attachable');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(InspectionItemAssignment::class);
    }

    public function activeAssignees(): BelongsToMany
    {
        return $this->belongsToMany(FmoPersonnel::class, 'inspection_item_assignments')
            ->wherePivotNull('unassigned_at')
            ->withPivot(['id', 'assigned_at', 'assigned_by', 'change_reason'])
            ->withTimestamps();
    }
}
