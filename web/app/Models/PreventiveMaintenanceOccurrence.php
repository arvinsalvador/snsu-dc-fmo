<?php

namespace App\Models;

use App\Enums\PreventiveMaintenanceStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PreventiveMaintenanceOccurrence extends Model
{
    use HasUlids;

    protected $appends = ['is_overdue', 'is_startable'];

    protected $fillable = ['preventive_maintenance_plan_id', 'inspectable_asset_id', 'period_key', 'scheduled_period_start', 'scheduled_period_end', 'available_at', 'due_at', 'status', 'plan_name_snapshot', 'maintenance_type_snapshot', 'checklist_snapshot', 'reading_snapshot', 'evidence_required_snapshot', 'verification_required_snapshot', 'started_at', 'completed_at', 'verified_at', 'verified_by', 'verification_note', 'due_notice_sent_at', 'overdue_notice_sent_at'];

    protected function casts(): array
    {
        return ['scheduled_period_start' => 'date', 'scheduled_period_end' => 'date', 'available_at' => 'datetime', 'due_at' => 'datetime', 'status' => PreventiveMaintenanceStatus::class, 'checklist_snapshot' => 'array', 'reading_snapshot' => 'array', 'evidence_required_snapshot' => 'boolean', 'verification_required_snapshot' => 'boolean', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'verified_at' => 'datetime', 'due_notice_sent_at' => 'datetime', 'overdue_notice_sent_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PreventiveMaintenancePlan::class, 'preventive_maintenance_plan_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InspectableAsset::class, 'inspectable_asset_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(FmoPersonnel::class, 'preventive_maintenance_occurrence_assignments')->withTimestamps();
    }

    public function execution(): HasOne
    {
        return $this->hasOne(PreventiveMaintenanceExecution::class);
    }

    public function correctiveWorkOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'preventive_maintenance_occurrence_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isOverdue(?CarbonInterface $at = null): bool
    {
        $at ??= now(config('app.timezone'));

        return $this->due_at->lt($at) && ! $this->status->isClosed();
    }

    public function isStartable(?CarbonInterface $at = null): bool
    {
        $at ??= now(config('app.timezone'));

        return $this->available_at->lte($at) && in_array($this->status, [PreventiveMaintenanceStatus::Available, PreventiveMaintenanceStatus::Overdue], true);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->isOverdue();
    }

    public function getIsStartableAttribute(): bool
    {
        return $this->isStartable();
    }

    /** @param Builder<PreventiveMaintenanceOccurrence> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [PreventiveMaintenanceStatus::Completed->value, PreventiveMaintenanceStatus::Cancelled->value]);
    }
}
