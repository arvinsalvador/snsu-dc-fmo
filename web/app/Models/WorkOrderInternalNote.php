<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class WorkOrderInternalNote extends Model
{
    use HasUlids;

    protected $fillable = ['work_order_id', 'author_user_id', 'body'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(CommunicationAttachment::class, 'attachable');
    }
}
