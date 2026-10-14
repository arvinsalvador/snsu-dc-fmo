<?php

namespace App\Models;

use App\Enums\WorkUpdateType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkUpdate extends Model
{
    use HasUlids;

    protected $fillable = ['work_order_id', 'work_session_id', 'fmo_personnel_id', 'created_by_user_id', 'type', 'description', 'requester_summary', 'material_description', 'remaining_work', 'recorded_at', 'execution_cycle', 'guided_description_profile_id', 'structured_actions', 'guided_outcome', 'generated_summary', 'additional_remarks'];

    protected function casts(): array
    {
        return ['type' => WorkUpdateType::class, 'recorded_at' => 'datetime', 'structured_actions' => 'array'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkSession::class, 'work_session_id');
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
