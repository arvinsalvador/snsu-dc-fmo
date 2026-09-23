<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationReview extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'reviewer_id', 'action', 'previous_status', 'new_status', 'reason'];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
