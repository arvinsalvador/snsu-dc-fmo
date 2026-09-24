<?php

namespace App\Models;

use App\Enums\AssessmentOutcome;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderAssessment extends Model
{
    use HasUlids;

    protected $fillable = ['work_order_id', 'work_order_assignment_id', 'fmo_personnel_id', 'outcome', 'findings', 'resource_notes', 'assessed_at'];

    protected function casts(): array
    {
        return ['outcome' => AssessmentOutcome::class, 'assessed_at' => 'datetime'];
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(FmoPersonnel::class, 'fmo_personnel_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkOrderAttachment::class);
    }
}
