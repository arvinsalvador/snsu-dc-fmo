<?php

namespace App\Services;

use App\Models\InspectableAsset;

class VehicleOdometerService
{
    public function __construct(private readonly OdometerMaintenanceService $maintenance) {}

    /**
     * Record a reading for a vehicle. The current odometer only ever moves forward, so a stale or
     * backdated trip can never lower it. Call inside the caller's transaction.
     */
    public function record(InspectableAsset $vehicle, int $reading): bool
    {
        $locked = InspectableAsset::query()->lockForUpdate()->findOrFail($vehicle->id);
        if ($locked->current_odometer_km !== null && $reading <= (int) $locked->current_odometer_km) {
            return false;
        }
        $locked->update(['current_odometer_km' => $reading, 'odometer_updated_at' => now(config('app.timezone'))]);
        $this->maintenance->recalculate($locked->fresh());

        return true;
    }
}
