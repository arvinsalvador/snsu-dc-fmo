<?php

namespace App\Models;

use App\Enums\AssetCondition;
use App\Enums\AssetLifecycleStatus;
use App\Enums\AssetStatus;
use App\Enums\RoutineFrequency;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class InspectableAsset extends Model
{
    use HasUlids;

    protected $fillable = [
        'asset_code', 'asset_type_id', 'name', 'description', 'campus_id', 'building_id', 'floor_id',
        'building_location_id', 'exact_placement', 'brand', 'model', 'serial_number', 'installed_at',
        'inspection_frequency', 'status', 'condition', 'lifecycle_status', 'qr_token', 'qr_regenerated_at',
        'qr_regenerated_by', 'last_inspected_at', 'notes',
    ];

    protected function casts(): array
    {
        return ['installed_at' => 'date', 'last_inspected_at' => 'datetime', 'qr_regenerated_at' => 'datetime',
            'inspection_frequency' => RoutineFrequency::class, 'status' => AssetStatus::class,
            'condition' => AssetCondition::class, 'lifecycle_status' => AssetLifecycleStatus::class];
    }

    protected static function booted(): void
    {
        static::creating(function (self $asset): void {
            $asset->qr_token ??= Str::random(48);
        });
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

    public function attachments(): MorphMany
    {
        return $this->morphMany(OperationalAttachment::class, 'attachable');
    }

    public function actionRequests(): HasMany
    {
        return $this->hasMany(AssetActionRequest::class);
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(AssetMaintenanceRecord::class);
    }

    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(AssetLifecycleEvent::class);
    }
}
