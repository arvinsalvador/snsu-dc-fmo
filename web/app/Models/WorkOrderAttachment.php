<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderAttachment extends Model
{
    use HasUlids;

    protected $fillable = ['work_order_id', 'uploaded_by', 'purpose', 'original_filename', 'stored_path', 'mime_type', 'file_size', 'caption'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
