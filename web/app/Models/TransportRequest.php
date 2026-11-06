<?php

namespace App\Models;

use App\Enums\FuelLevel;
use App\Enums\TransportApprovalAuthority;
use App\Enums\TransportStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TransportRequest extends Model
{
    use HasUlids;

    public const DOCUMENT_PURPOSE = 'TRANSPORT_REQUEST_DOC';

    public const APPROVAL_EVIDENCE_PURPOSE = 'TRANSPORT_APPROVAL_EVIDENCE';

    public const TRIP_DOCUMENT_PURPOSE = 'TRANSPORT_TRIP_DOC';

    public const ISSUE_PHOTO_PURPOSE = 'TRANSPORT_ISSUE_PHOTO';

    protected $fillable = [
        'request_number', 'requester_id', 'purpose', 'origin', 'destination', 'travel_date', 'expected_departure_at',
        'expected_return_at', 'passenger_count', 'passenger_details', 'remarks', 'status',
        'approved_by', 'approved_at', 'approval_authority', 'approval_instructed_by', 'approval_note',
        'rejected_by', 'rejected_at', 'rejection_reason', 'cancelled_by', 'cancelled_at',
        'driver_personnel_id', 'inspectable_asset_id', 'assigned_by', 'assigned_at',
        'actual_departed_at', 'actual_arrived_at', 'odometer_before', 'odometer_after', 'distance_km',
        'fuel_before', 'fuel_after', 'trip_remarks', 'vehicle_issue_observed', 'vehicle_issue_remarks',
        'completed_at', 'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransportStatus::class, 'approval_authority' => TransportApprovalAuthority::class,
            'fuel_before' => FuelLevel::class, 'fuel_after' => FuelLevel::class,
            'travel_date' => 'date', 'expected_departure_at' => 'datetime', 'expected_return_at' => 'datetime',
            'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime', 'assigned_at' => 'datetime',
            'actual_departed_at' => 'datetime', 'actual_arrived_at' => 'datetime', 'completed_at' => 'datetime',
            'vehicle_issue_observed' => 'boolean',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(FmoPersonnel::class, 'driver_personnel_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(InspectableAsset::class, 'inspectable_asset_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransportRequestEvent::class)->oldest('created_at');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(OperationalAttachment::class, 'attachable');
    }

    public function correctiveWorkOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'transport_request_id');
    }

    public function route(): string
    {
        return $this->origin.' → '.$this->destination;
    }

    /** @return array{start: CarbonInterface, end: CarbonInterface} */
    public function window(): array
    {
        $start = $this->expected_departure_at ?? $this->travel_date->copy()->startOfDay();
        $end = $this->expected_return_at ?? $this->travel_date->copy()->endOfDay();

        return ['start' => $start, 'end' => $end->lt($start) ? $start->copy()->endOfDay() : $end];
    }
}
