<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionItemAssignment extends Model
{
    use HasUlids;

    protected $fillable = [
        'inspection_item_id', 'fmo_personnel_id', 'assigned_by', 'assigned_at',
        'unassigned_at', 'unassigned_by', 'change_reason', 'active_marker',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'unassigned_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InspectionItem::class, 'inspection_item_id');
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(FmoPersonnel::class, 'fmo_personnel_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function unassigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unassigned_by');
    }
}
