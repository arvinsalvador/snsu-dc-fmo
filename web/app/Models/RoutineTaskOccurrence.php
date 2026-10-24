<?php

namespace App\Models;

use App\Enums\RoutineOccurrenceStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class RoutineTaskOccurrence extends Model
{
    use HasUlids;

    protected $appends = ['available_at', 'is_startable', 'is_actionable'];

    protected $fillable = [
        'routine_task_template_id', 'period_key', 'expected_date', 'due_at', 'status', 'started_at', 'completed_at',
        'performed_by_personnel_id', 'performed_by_user_id', 'referenced_work_order_id', 'guided_description_profile_id',
        'structured_actions', 'guided_outcome', 'generated_summary', 'additional_remarks', 'remaining_work', 'resolution_code',
    ];

    protected function casts(): array
    {
        return [
            'status' => RoutineOccurrenceStatus::class, 'expected_date' => 'date', 'due_at' => 'datetime',
            'started_at' => 'datetime', 'completed_at' => 'datetime', 'structured_actions' => 'array',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RoutineTaskTemplate::class, 'routine_task_template_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(FmoPersonnel::class, 'routine_task_occurrence_assignees')->withTimestamps();
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(FmoPersonnel::class, 'performed_by_personnel_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    public function referencedWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'referenced_work_order_id');
    }

    public function guidedProfile(): BelongsTo
    {
        return $this->belongsTo(GuidedDescriptionProfile::class, 'guided_description_profile_id');
    }

    public function inspectionRun(): HasOne
    {
        return $this->hasOne(InspectionRun::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(OperationalAttachment::class, 'attachable');
    }

    /** @param Builder<RoutineTaskOccurrence> $query */
    public function scopeUnresolvedOverdue(Builder $query): Builder
    {
        return $query->where('due_at', '<', now(config('app.timezone')))
            ->whereNotIn('status', [
                RoutineOccurrenceStatus::Completed->value,
                RoutineOccurrenceStatus::CompletedLate->value,
                RoutineOccurrenceStatus::UnableToComplete->value,
            ]);
    }

    public function requiresOverdueAttention(): bool
    {
        return $this->due_at->isPast() && ! $this->status->isClosed();
    }

    public function availableAt(): CarbonImmutable
    {
        $timezone = config('app.timezone', 'Asia/Manila');

        return CarbonImmutable::parse($this->expected_date->format('Y-m-d').' 00:00:00', $timezone);
    }

    public function isAvailable(?CarbonInterface $at = null): bool
    {
        $at ??= now(config('app.timezone', 'Asia/Manila'));

        return $at->copy()->setTimezone(config('app.timezone', 'Asia/Manila'))->greaterThanOrEqualTo($this->availableAt());
    }

    public function isStartable(?CarbonInterface $at = null): bool
    {
        return $this->isAvailable($at)
            && in_array($this->status, [RoutineOccurrenceStatus::Upcoming, RoutineOccurrenceStatus::Due, RoutineOccurrenceStatus::Overdue], true);
    }

    public function isActionable(?CarbonInterface $at = null): bool
    {
        return $this->isAvailable($at) && ! $this->status->isClosed();
    }

    public function getAvailableAtAttribute(): string
    {
        return $this->availableAt()->toIso8601String();
    }

    public function getIsStartableAttribute(): bool
    {
        return $this->isStartable();
    }

    public function getIsActionableAttribute(): bool
    {
        return $this->isActionable();
    }
}
