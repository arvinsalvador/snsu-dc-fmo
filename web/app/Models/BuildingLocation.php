<?php

namespace App\Models;

use App\Enums\LocationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuildingLocation extends Model
{
    protected $table = 'building_locations';

    protected $fillable = ['building_id', 'floor_id', 'type', 'code', 'name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['type' => LocationType::class, 'is_active' => 'boolean'];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function isOperational(): bool
    {
        return $this->is_active && $this->building?->isOperational() && (! $this->floor || $this->floor->isOperational());
    }
}
