<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionTemplateAssetAssignment extends Model
{
    use HasUlids;

    protected $fillable = [
        'routine_task_template_id', 'inspectable_asset_id', 'fmo_personnel_id', 'assigned_by',
        'assigned_at', 'unassigned_at', 'unassigned_by', 'change_reason', 'active_marker',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'unassigned_at' => 'datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RoutineTaskTemplate::class, 'routine_task_template_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InspectableAsset::class, 'inspectable_asset_id');
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(FmoPersonnel::class, 'fmo_personnel_id');
    }
}
