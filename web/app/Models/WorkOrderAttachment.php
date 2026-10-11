<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderAttachment extends Model
{
    use HasUlids;

    protected $fillable = ['work_order_id', 'work_order_assessment_id', 'work_session_id', 'work_update_id', 'evidence_type', 'requester_visible', 'uploaded_by', 'purpose', 'original_filename', 'stored_path', 'thumbnail_path', 'mime_type', 'file_size', 'width', 'height', 'thumbnail_width', 'thumbnail_height', 'caption'];

    protected function casts(): array
    {
        return ['requester_visible' => 'boolean'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function workUpdate(): BelongsTo
    {
        return $this->belongsTo(WorkUpdate::class, 'work_update_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function safeDownloadName(): string
    {
        $basename = basename(str_replace('\\', '/', $this->original_filename));
        $safe = preg_replace('/[^\pL\pN._ -]/u', '_', $basename);
        $safe = trim($safe ?? '', ' .');

        return $safe === '' ? 'attachment' : $safe;
    }
}
