<?php

namespace App\Services;

use App\Enums\AssetLifecycleStatus;
use App\Enums\RoutineFrequency;
use App\Enums\RoutineOccurrenceStatus;
use App\Enums\RoutineTaskType;
use App\Models\InspectableAsset;
use App\Models\InspectionRun;
use App\Models\RoutineTaskOccurrence;
use App\Models\RoutineTaskTemplate;
use App\Models\User;
use App\Notifications\InspectionGenerationIssueNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RoutineOccurrenceService
{
    public function __construct(private readonly InspectionAssignmentService $inspectionAssignments) {}

    public function generate(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $from = $from->setTimezone($timezone)->startOfDay();
        $to = $to->setTimezone($timezone)->startOfDay();
        $created = 0;

        RoutineTaskTemplate::query()->with('activeAssignees')->where('is_active', true)
            ->whereDate('start_date', '<=', $to)->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $from))
            ->chunkById(100, function ($templates) use ($from, $to, &$created, $timezone): void {
                foreach ($templates as $template) {
                    for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                        if (! $this->isExpected($template, $date)) {
                            continue;
                        }
                        $periodKey = $this->periodKey($template, $date);
                        $assets = $template->task_type === RoutineTaskType::Inspection ? $this->eligibleInspectionAssets($template) : null;
                        if ($assets !== null && $assets->isEmpty()) {
                            $this->notifyNoActiveAssets($template, $periodKey);

                            continue;
                        }
                        DB::transaction(function () use ($template, $date, $periodKey, $assets, &$created, $timezone): void {
                            $dueAt = CarbonImmutable::parse($date->toDateString().' '.$template->due_time, $timezone);
                            $occurrence = RoutineTaskOccurrence::firstOrCreate(
                                ['routine_task_template_id' => $template->id, 'period_key' => $periodKey],
                                ['expected_date' => $date, 'due_at' => $dueAt, 'status' => $dueAt->isPast() ? RoutineOccurrenceStatus::Overdue : ($date->isToday() ? RoutineOccurrenceStatus::Due : RoutineOccurrenceStatus::Upcoming), 'guided_description_profile_id' => $template->guided_description_profile_id]
                            );
                            if ($occurrence->wasRecentlyCreated) {
                                $created++;
                            }
                            $occurrence->assignees()->syncWithoutDetaching($template->activeAssignees->modelKeys());
                            if ($template->task_type === RoutineTaskType::Inspection) {
                                $this->ensureInspectionRun($occurrence, $template, $assets);
                            }
                        });
                    }
                }
            });

        $this->refreshStatuses();

        return $created;
    }

    public function refreshStatuses(): int
    {
        $now = now(config('app.timezone', 'Asia/Manila'));
        RoutineTaskOccurrence::where('status', RoutineOccurrenceStatus::Upcoming->value)->whereDate('expected_date', '<=', $now->toDateString())
            ->where('due_at', '>', $now)->update(['status' => RoutineOccurrenceStatus::Due->value]);

        return RoutineTaskOccurrence::whereIn('status', [RoutineOccurrenceStatus::Upcoming->value, RoutineOccurrenceStatus::Due->value, RoutineOccurrenceStatus::InProgress->value])
            ->where('due_at', '<', $now)->update(['status' => RoutineOccurrenceStatus::Overdue->value]);
    }

    private function isExpected(RoutineTaskTemplate $template, CarbonImmutable $date): bool
    {
        if ($date->lt($template->start_date) || ($template->end_date && $date->gt($template->end_date))) {
            return false;
        }
        $config = $template->recurrence_config ?? [];

        return match ($template->frequency) {
            RoutineFrequency::Daily => true,
            RoutineFrequency::Weekly => $date->dayOfWeekIso === (int) ($config['weekday'] ?? $template->start_date->dayOfWeekIso)
                || ($date->isSameDay($template->start_date) && (int) ($config['weekday'] ?? $template->start_date->dayOfWeekIso) < $template->start_date->dayOfWeekIso),
            RoutineFrequency::Monthly => $date->day === min((int) ($config['day'] ?? $template->start_date->day), $date->daysInMonth)
                || ($date->isSameDay($template->start_date) && (int) ($config['day'] ?? $template->start_date->day) < $template->start_date->day),
        };
    }

    private function periodKey(RoutineTaskTemplate $template, CarbonImmutable $date): string
    {
        return match ($template->frequency) {
            RoutineFrequency::Daily => $date->format('Y-m-d'),
            RoutineFrequency::Weekly => $date->format('o-\WW'),
            RoutineFrequency::Monthly => $date->format('Y-m'),
        };
    }

    /** @return Collection<int, InspectableAsset> */
    private function eligibleInspectionAssets(RoutineTaskTemplate $template): Collection
    {
        return InspectableAsset::with(['assetType', 'campus', 'building', 'floor', 'location'])
            ->where('asset_type_id', $template->asset_type_id)
            ->where('lifecycle_status', AssetLifecycleStatus::Active->value)
            ->when($template->inspection_asset_scope === 'SPECIFIC', fn ($query) => $query->whereIn('id', $template->selectedAssets()->select('inspectable_assets.id')))
            ->when($template->inspection_asset_scope === 'TYPE_LOCATION', fn ($query) => $query
                ->where('campus_id', $template->campus_id)
                ->when($template->building_id, fn ($q) => $q->where('building_id', $template->building_id))
                ->when($template->floor_id, fn ($q) => $q->where('floor_id', $template->floor_id))
                ->when($template->building_location_id, fn ($q) => $q->where('building_location_id', $template->building_location_id)))
            ->orderBy('asset_code')->get();
    }

    /** @param Collection<int, InspectableAsset> $assets */
    private function ensureInspectionRun(RoutineTaskOccurrence $occurrence, RoutineTaskTemplate $template, Collection $assets): void
    {
        $run = InspectionRun::firstOrCreate(
            ['routine_task_occurrence_id' => $occurrence->id],
            ['routine_task_template_id' => $template->id, 'period_key' => $occurrence->period_key, 'status' => 'OPEN']
        );
        $createdItems = collect();
        foreach ($assets as $asset) {
            $item = $run->items()->firstOrCreate(
                ['inspectable_asset_id' => $asset->id],
                [
                    'checklist_snapshot' => $asset->assetType->checklist,
                    'asset_context' => [
                        'asset_code' => $asset->asset_code,
                        'asset_type' => $asset->assetType->name,
                        'campus' => $asset->campus?->name,
                        'building' => $asset->building?->name,
                        'floor' => $asset->floor?->name,
                        'location' => $asset->location?->name,
                        'exact_placement' => $asset->exact_placement,
                    ],
                ]
            );
            if ($item->wasRecentlyCreated) {
                $createdItems->push($item);
            }
        }
        if ($createdItems->isNotEmpty()) {
            $this->inspectionAssignments->snapshot($template, $run, $createdItems);
        }
    }

    private function notifyNoActiveAssets(RoutineTaskTemplate $template, string $periodKey): void
    {
        User::permission('routine_tasks.view_all')->get()->each(function (User $user) use ($template, $periodKey): void {
            $exists = $user->notifications()
                ->where('data->event_key', 'INSPECTION_NO_ACTIVE_ASSETS')
                ->where('data->routine_task_template_id', $template->id)
                ->where('data->period_key', $periodKey)
                ->exists();
            if (! $exists) {
                $user->notify(new InspectionGenerationIssueNotice($template, $periodKey));
            }
        });
    }
}
