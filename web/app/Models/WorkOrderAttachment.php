<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderAttachment extends Model
{
    use HasUlids;

    protected $fillable = ['work_order_id', 'work_order_assessment_id', 'work_session_id', 'work_update_id', 'evidence_type', 'requester_visible', 'uploaded_by', 'purpose', 'original_filename', 'stored_path', 'mime_type', 'file_size', 'caption'];

    protected function casts(): array
    {
        return ['requester_visible' => 'boolean'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
