<?php

namespace App\Services;

use App\Enums\PreventiveMaintenanceFrequency;
use App\Enums\PreventiveMaintenanceStatus;
use App\Models\PreventiveMaintenanceOccurrence;
use App\Models\PreventiveMaintenancePlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PreventiveMaintenanceOccurrenceService
{
    public function currentPeriodStart(PreventiveMaintenancePlan $plan, CarbonImmutable $at): CarbonImmutable
    {
        $at = $at->setTimezone(config('app.timezone'))->startOfDay();
        $candidate = match ($plan->frequency_type) {
            PreventiveMaintenanceFrequency::Daily => $at,
            PreventiveMaintenanceFrequency::Weekly => $at->startOfWeek(),
            PreventiveMaintenanceFrequency::Monthly => $at->startOfMonth(),
            PreventiveMaintenanceFrequency::Quarterly => $at->startOfQuarter(),
            PreventiveMaintenanceFrequency::Semiannual => $at->month <= 6 ? $at->startOfYear() : $at->setMonth(7)->startOfMonth(),
            PreventiveMaintenanceFrequency::Annual => $at->startOfYear(),
            PreventiveMaintenanceFrequency::Custom => match ($plan->recurrence_config['unit'] ?? 'MONTH') {
                'DAY' => $at->subDays(max(0, $plan->interval_value - 1)),
                'WEEK' => $at->startOfWeek()->subWeeks(max(0, $plan->interval_value - 1)),
                default => $at->startOfMonth()->subMonths(max(0, $plan->interval_value - 1)),
            },
        };

        return $candidate->max(CarbonImmutable::parse($plan->start_date, config('app.timezone'))->startOfDay());
    }

    public function generate(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $from = $from->setTimezone($timezone)->startOfDay();
        $to = $to->setTimezone($timezone)->startOfDay();
        $created = 0;

        PreventiveMaintenancePlan::query()->with('activeAssignees')->where('is_active', true)
            ->whereDate('start_date', '<=', $to)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $from))
            ->chunkById(100, function ($plans) use ($from, $to, &$created): void {
                foreach ($plans as $plan) {
                    $start = $from->max(CarbonImmutable::parse($plan->start_date, config('app.timezone'))->startOfDay());
                    for ($date = $start; $date->lte($to); $date = $date->addDay()) {
                        if (! $this->isExpected($plan, $date)) {
                            continue;
                        }
                        DB::transaction(function () use ($plan, $date, &$created): void {
                            [$periodStart, $periodEnd] = $this->periodBounds($plan, $date);
                            $availableDate = $this->nextWorkingDay($date);
                            $availableAt = $availableDate->setTimeFromTimeString((string) $plan->available_time);
                            $dueAt = $availableDate->setTimeFromTimeString((string) $plan->due_time);
                            $now = now(config('app.timezone'));
                            $status = $availableAt->isFuture()
                                ? PreventiveMaintenanceStatus::Upcoming
                                : ($dueAt->isPast() ? PreventiveMaintenanceStatus::Overdue : PreventiveMaintenanceStatus::Available);
                            $occurrence = $plan->occurrences()->firstOrCreate(
                                ['period_key' => $this->periodKey($plan, $date)],
                                [
                                    'inspectable_asset_id' => $plan->inspectable_asset_id,
                                    'scheduled_period_start' => $periodStart,
                                    'scheduled_period_end' => $periodEnd,
                                    'available_at' => $availableAt,
                                    'due_at' => $dueAt,
                                    'status' => $status,
                                    'plan_name_snapshot' => $plan->name,
                                    'maintenance_type_snapshot' => $plan->maintenance_type,
                                    'checklist_snapshot' => $plan->checklist_template,
                                    'reading_snapshot' => $plan->reading_template,
                                    'evidence_required_snapshot' => $plan->evidence_required,
                                    'verification_required_snapshot' => $plan->verification_required,
                                ]
                            );
                            if ($occurrence->wasRecentlyCreated) {
                                $created++;
                            }
                            $occurrence->assignees()->syncWithoutDetaching($plan->activeAssignees->modelKeys());
                        });
                    }
                }
            });

        $this->refreshStatuses();

        return $created;
    }

    public function refreshStatuses(): void
    {
        $now = now(config('app.timezone'));
        PreventiveMaintenanceOccurrence::where('status', PreventiveMaintenanceStatus::Upcoming->value)
            ->where('available_at', '<=', $now)->where('due_at', '>=', $now)
            ->update(['status' => PreventiveMaintenanceStatus::Available->value]);
        PreventiveMaintenanceOccurrence::whereIn('status', [PreventiveMaintenanceStatus::Upcoming->value, PreventiveMaintenanceStatus::Available->value])
            ->where('due_at', '<', $now)->update(['status' => PreventiveMaintenanceStatus::Overdue->value]);
    }

    private function isExpected(PreventiveMaintenancePlan $plan, CarbonImmutable $date): bool
    {
        $start = CarbonImmutable::parse($plan->start_date, config('app.timezone'))->startOfDay();
        if ($date->lt($start) || ($plan->end_date && $date->gt($plan->end_date))) {
            return false;
        }
        $config = $plan->recurrence_config ?? [];
        $interval = max(1, (int) $plan->interval_value);
        $frequency = $plan->frequency_type;
        if ($frequency === PreventiveMaintenanceFrequency::Daily) {
            return $start->diffInDays($date) % $interval === 0;
        }
        if ($frequency === PreventiveMaintenanceFrequency::Weekly) {
            return $date->dayOfWeekIso === (int) ($config['weekday'] ?? $start->dayOfWeekIso)
                && intdiv($start->startOfWeek()->diffInDays($date->startOfWeek()), 7) % $interval === 0;
        }
        $monthInterval = match ($frequency) {
            PreventiveMaintenanceFrequency::Monthly => $interval,
            PreventiveMaintenanceFrequency::Quarterly => 3 * $interval,
            PreventiveMaintenanceFrequency::Semiannual => 6 * $interval,
            PreventiveMaintenanceFrequency::Annual => 12 * $interval,
            PreventiveMaintenanceFrequency::Custom => match ($config['unit'] ?? 'MONTH') {
                'DAY' => 0,
                'WEEK' => 0,
                default => $interval,
            },
            default => $interval,
        };
        if ($frequency === PreventiveMaintenanceFrequency::Custom && ($config['unit'] ?? null) === 'DAY') {
            return $start->diffInDays($date) % $interval === 0;
        }
        if ($frequency === PreventiveMaintenanceFrequency::Custom && ($config['unit'] ?? null) === 'WEEK') {
            return $date->dayOfWeekIso === (int) ($config['weekday'] ?? $start->dayOfWeekIso)
                && intdiv($start->startOfWeek()->diffInDays($date->startOfWeek()), 7) % $interval === 0;
        }
        $months = ($date->year - $start->year) * 12 + $date->month - $start->month;
        $day = min((int) ($config['day'] ?? $start->day), $date->daysInMonth);

        return $months >= 0 && $months % max(1, $monthInterval) === 0
            && ($date->day === $day || ($date->isSameDay($start) && $day < $start->day));
    }

    private function periodKey(PreventiveMaintenancePlan $plan, CarbonImmutable $date): string
    {
        return match ($plan->frequency_type) {
            PreventiveMaintenanceFrequency::Daily => $date->format('Y-m-d'),
            PreventiveMaintenanceFrequency::Weekly => $date->format('o-\WW'),
            PreventiveMaintenanceFrequency::Monthly => $date->format('Y-m'),
            PreventiveMaintenanceFrequency::Quarterly => $date->format('Y').'-Q'.(int) ceil($date->month / 3),
            PreventiveMaintenanceFrequency::Semiannual => $date->format('Y').'-H'.($date->month <= 6 ? '1' : '2'),
            PreventiveMaintenanceFrequency::Annual => $date->format('Y'),
            PreventiveMaintenanceFrequency::Custom => 'C-'.$date->format('Y-m-d'),
        };
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function periodBounds(PreventiveMaintenancePlan $plan, CarbonImmutable $date): array
    {
        return match ($plan->frequency_type) {
            PreventiveMaintenanceFrequency::Daily => [$date, $date],
            PreventiveMaintenanceFrequency::Weekly => [$date->startOfWeek(), $date->endOfWeek()],
            PreventiveMaintenanceFrequency::Monthly => [$date->startOfMonth(), $date->endOfMonth()],
            PreventiveMaintenanceFrequency::Quarterly => [$date->startOfQuarter(), $date->endOfQuarter()],
            PreventiveMaintenanceFrequency::Semiannual => [$date->month <= 6 ? $date->startOfYear() : $date->setMonth(7)->startOfMonth(), $date->month <= 6 ? $date->setMonth(6)->endOfMonth() : $date->endOfYear()],
            PreventiveMaintenanceFrequency::Annual => [$date->startOfYear(), $date->endOfYear()],
            PreventiveMaintenanceFrequency::Custom => [$date, $date],
        };
    }

    private function nextWorkingDay(CarbonImmutable $date): CarbonImmutable
    {
        while ($date->isWeekend()) {
            $date = $date->addDay();
        }

        return $date;
    }
}
