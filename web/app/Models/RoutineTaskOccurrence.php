<?php

namespace App\Models;

use App\Enums\RoutineOccurrenceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class RoutineTaskOccurrence extends Model
{
    use HasUlids;

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
}
