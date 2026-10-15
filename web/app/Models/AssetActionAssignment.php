<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetActionAssignment extends Model
{
    use HasUlids;

    protected $fillable = [
        'asset_action_request_id', 'fmo_personnel_id', 'assigned_by', 'assigned_at',
        'unassigned_at', 'unassigned_by', 'change_reason', 'active_marker',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'unassigned_at' => 'datetime'];
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(AssetActionRequest::class, 'asset_action_request_id');
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(FmoPersonnel::class, 'fmo_personnel_id');
    }
}
