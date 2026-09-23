<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    use HasUlids;

    protected $fillable = ['work_order_number', 'requester_id', 'work_order_category_id', 'campus_id', 'building_id', 'floor_id', 'building_location_id', 'subject', 'description', 'urgency', 'preferred_fmo_personnel_id', 'status', 'submitted_at'];

    protected function casts(): array
    {
        return ['status' => WorkOrderStatus::class, 'submitted_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WorkOrderCategory::class, 'work_order_category_id');
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

    public function preferredPersonnel(): BelongsTo
    {
        return $this->belongsTo(FmoPersonnel::class, 'preferred_fmo_personnel_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkOrderAttachment::class);
    }
}
