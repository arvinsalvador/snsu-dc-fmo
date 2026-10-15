<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetLifecycleEvent extends Model
{
    use HasUlids;

    protected $fillable = ['inspectable_asset_id', 'asset_action_request_id', 'actor_id', 'event', 'from_lifecycle', 'to_lifecycle', 'from_condition', 'to_condition', 'remarks'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InspectableAsset::class, 'inspectable_asset_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
