<?php

namespace App\Models;

use App\Enums\FmoContactCategory;
use App\Enums\FmoContactStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FmoContactThread extends Model
{
    use HasUlids;

    protected $fillable = [
        'requester_user_id', 'related_work_order_id', 'category', 'subject',
        'status', 'closed_at', 'closed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'category' => FmoContactCategory::class,
            'status' => FmoContactStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function relatedWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'related_work_order_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(FmoContactMessage::class)->orderBy('created_at')->orderBy('id');
    }
}
