<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InspectionRun extends Model
{
    use HasUlids;

    protected $fillable = ['routine_task_occurrence_id', 'routine_task_template_id', 'period_key', 'status', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(RoutineTaskOccurrence::class, 'routine_task_occurrence_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RoutineTaskTemplate::class, 'routine_task_template_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InspectionItem::class);
    }
}
