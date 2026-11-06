<?php

namespace App\Services;

use App\Enums\PreventiveMaintenanceStatus;
use App\Models\InspectableAsset;
use App\Models\PreventiveMaintenanceOccurrence;
use App\Models\PreventiveMaintenancePlan;
use App\Notifications\PreventiveMaintenanceNotice;
use Illuminate\Support\Facades\DB;

/** Keeps mileage-based preventive maintenance in step with a vehicle's current odometer. */
class OdometerMaintenanceService
{
    public function recalculate(InspectableAsset $asset): void
    {
        $odometer = $asset->current_odometer_km;
        if ($odometer === null) {
            return;
        }

        $asset->preventiveMaintenancePlans()->with(['activeAssignees.user'])
            ->where('trigger_type', 'ODOMETER')->where('is_active', true)->whereNotNull('next_due_odometer_km')
            ->get()->each(fn (PreventiveMaintenancePlan $plan) => $this->recalculatePlan($plan, (int) $odometer));
    }

    private function recalculatePlan(PreventiveMaintenancePlan $plan, int $odometer): void
    {
        $due = (int) $plan->next_due_odometer_km;
        $remaining = $due - $odometer;
        $open = $plan->occurrences()->open()->whereNotNull('due_odometer_km')->first();

        if (! $open) {
            if ($remaining > $plan->dueSoonThreshold()) {
                return;
            }
            $open = DB::transaction(function () use ($plan, $due, $remaining): PreventiveMaintenanceOccurrence {
                $today = now(config('app.timezone'));
                $occurrence = $plan->occurrences()->firstOrCreate(['period_key' => 'ODO-'.$due], [
                    'inspectable_asset_id' => $plan->inspectable_asset_id,
                    'scheduled_period_start' => $today->toDateString(), 'scheduled_period_end' => $today->toDateString(),
                    'available_at' => $today, 'due_at' => null, 'due_odometer_km' => $due,
                    'status' => $remaining < 0 ? PreventiveMaintenanceStatus::Overdue : PreventiveMaintenanceStatus::Available,
                    'plan_name_snapshot' => $plan->name, 'maintenance_type_snapshot' => $plan->maintenance_type,
                    'checklist_snapshot' => $plan->checklist_template, 'reading_snapshot' => $plan->reading_template,
                    'evidence_required_snapshot' => $plan->evidence_required, 'verification_required_snapshot' => $plan->verification_required,
                ]);
                if ($occurrence->wasRecentlyCreated) {
                    $occurrence->assignees()->attach($plan->activeAssignees->modelKeys());
                }

                return $occurrence;
            });
            $this->notify($open, $remaining < 0 ? 'PM_OVERDUE' : 'PM_DUE');

            return;
        }

        if ($remaining < 0 && in_array($open->status, [PreventiveMaintenanceStatus::Upcoming, PreventiveMaintenanceStatus::Available], true)) {
            $open->update(['status' => PreventiveMaintenanceStatus::Overdue]);
            $this->notify($open, 'PM_OVERDUE');
        }
    }

    private function notify(PreventiveMaintenanceOccurrence $occurrence, string $event): void
    {
        $column = $event === 'PM_OVERDUE' ? 'overdue_notice_sent_at' : 'due_notice_sent_at';
        if ($occurrence->{$column}) {
            return;
        }
        $occurrence->loadMissing(['asset', 'assignees.user']);
        $occurrence->assignees->each(fn ($personnel) => $personnel->user?->notify(new PreventiveMaintenanceNotice($occurrence, $event)));
        $occurrence->update([$column => now(config('app.timezone'))]);
    }
}
