<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AssetMaintenanceRecord extends Model
{
    use HasUlids;

    protected $fillable = ['inspectable_asset_id', 'asset_action_request_id', 'recorded_by', 'recorded_at', 'work_performed', 'parts_materials', 'result', 'remarks'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InspectableAsset::class, 'inspectable_asset_id');
    }

    public function actionRequest(): BelongsTo
    {
        return $this->belongsTo(AssetActionRequest::class, 'asset_action_request_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(OperationalAttachment::class, 'attachable');
    }
}
