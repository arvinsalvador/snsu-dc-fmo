<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    use HasUlids;

    protected $fillable = ['work_order_number', 'requester_id', 'work_order_category_id', 'campus_id', 'building_id', 'floor_id', 'building_location_id', 'subject', 'description', 'urgency', 'preferred_fmo_personnel_id', 'status', 'submitted_at', 'recommendation', 'decided_by', 'decided_at', 'information_context', 'completion_summary', 'work_performed_summary', 'non_photo_reason', 'completion_submitted_by', 'completion_submitted_at', 'verified_by', 'verified_at', 'verification_note', 'execution_cycle'];

    protected function casts(): array
    {
        return ['status' => WorkOrderStatus::class, 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'completion_submitted_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WorkOrderCategory::class, 'work_order_category_id');
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

    public function preferredPersonnel(): BelongsTo
    {
        return $this->belongsTo(FmoPersonnel::class, 'preferred_fmo_personnel_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkOrderAttachment::class);
    }

    public function workflowEvents(): HasMany
    {
        return $this->hasMany(WorkOrderWorkflowEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function completionSubmitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completion_submitted_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkOrderAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull('unassigned_at');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(WorkOrderAssessment::class)->orderBy('assessed_at');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WorkSession::class)->orderBy('started_at');
    }

    public function activeSessions(): HasMany
    {
        return $this->hasMany(WorkSession::class)->whereNull('ended_at');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(WorkUpdate::class);
    }
}
