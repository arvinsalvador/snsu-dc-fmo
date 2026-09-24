<?php

namespace App\Models;

use App\Enums\WorkSessionOutcome;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkSession extends Model
{
    use HasUlids;

    protected $fillable = ['work_order_id', 'work_order_assignment_id', 'fmo_personnel_id', 'started_by_user_id', 'started_at', 'ended_at', 'end_outcome', 'session_summary', 'active_marker'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'end_outcome' => WorkSessionOutcome::class];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(FmoPersonnel::class, 'fmo_personnel_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(WorkUpdate::class)->orderBy('recorded_at');
    }
}
