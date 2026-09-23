<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequesterProfile extends Model
{
    protected $fillable = [
        'institutional_id', 'department', 'college', 'program', 'year_level',
        'organizational_office', 'contact_number',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
