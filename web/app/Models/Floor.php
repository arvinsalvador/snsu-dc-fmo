<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Floor extends Model
{
    protected $fillable = ['building_id', 'code', 'name', 'display_order', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(BuildingLocation::class);
    }

    public function isOperational(): bool
    {
        return $this->is_active && $this->building?->isOperational();
    }
}
