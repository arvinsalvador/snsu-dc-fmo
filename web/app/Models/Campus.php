<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campus extends Model
{
    protected $fillable = ['code', 'name', 'short_name', 'description', 'address', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    public function isOperational(): bool
    {
        return $this->is_active;
    }
}
