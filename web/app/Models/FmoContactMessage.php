<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FmoContactMessage extends Model
{
    use HasUlids;

    protected $fillable = ['fmo_contact_thread_id', 'sender_user_id', 'body'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(FmoContactThread::class, 'fmo_contact_thread_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(CommunicationAttachment::class, 'attachable');
    }
}
