<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AssetActionRequest extends Model
{
    use HasUlids;

    protected $fillable = ['inspectable_asset_id', 'inspection_item_id', 'type', 'status', 'requested_by', 'requested_at',
        'problem_description', 'recommended_action', 'reviewed_by', 'reviewed_at', 'decision', 'decision_reason'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InspectableAsset::class, 'inspectable_asset_id');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(InspectionItem::class, 'inspection_item_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(AssetMaintenanceRecord::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(OperationalAttachment::class, 'attachable');
    }
}
