<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\RoutineFrequency;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InspectableAsset extends Model
{
    use HasUlids;

    protected $fillable = [
        'asset_code', 'asset_type_id', 'name', 'description', 'campus_id', 'building_id', 'floor_id',
        'building_location_id', 'exact_placement', 'brand', 'model', 'serial_number', 'installed_at',
        'inspection_frequency', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return ['installed_at' => 'date', 'inspection_frequency' => RoutineFrequency::class, 'status' => AssetStatus::class];
    }

    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BuildingLocation::class, 'building_location_id');
    }

    public function inspectionItems(): HasMany
    {
        return $this->hasMany(InspectionItem::class);
    }
}
