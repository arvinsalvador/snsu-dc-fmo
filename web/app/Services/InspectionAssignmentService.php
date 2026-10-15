<?php

namespace App\Services;

use App\Models\FmoPersonnel;
use App\Models\InspectionItem;
use App\Models\InspectionItemAssignment;
use App\Models\InspectionRun;
use App\Models\InspectionTemplateAssetAssignment;
use App\Models\RoutineTaskTemplate;
use App\Models\User;
use App\Notifications\InspectionAssignmentNotice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InspectionAssignmentService
{
    /** @param Collection<int, InspectionItem> $items */
    public function snapshot(RoutineTaskTemplate $template, InspectionRun $run, Collection $items): void
    {
        $template->loadMissing(['activeAssignees', 'assetAssignmentOverrides']);
        $defaultIds = $template->activeAssignees->modelKeys();
        $overrides = $template->assetAssignmentOverrides->whereNull('unassigned_at')->groupBy('inspectable_asset_id');

        foreach ($items as $item) {
            $personnelIds = $template->customize_asset_assignments && $overrides->has($item->inspectable_asset_id)
                ? $overrides->get($item->inspectable_asset_id)->pluck('fmo_personnel_id')->all()
                : $defaultIds;
            foreach (array_unique($personnelIds) as $personnelId) {
                InspectionItemAssignment::create([
                    'inspection_item_id' => $item->id,
                    'fmo_personnel_id' => $personnelId,
                    'assigned_by' => $template->created_by,
                    'assigned_at' => now(),
                    'active_marker' => 1,
                ]);
            }
        }
        $this->syncOccurrenceTeam($run);
    }

    /** @param array<int, string> $personnelIds */
    public function replaceItemTeam(InspectionItem $item, array $personnelIds, User $actor, ?string $reason = null, bool $notify = true, bool $syncRun = true): array
    {
        if ($item->status === 'INSPECTED') {
            throw ValidationException::withMessages(['assignee_ids' => 'Completed inspections cannot be reassigned. Create a follow-up inspection instead.']);
        }
        $personnelIds = array_values(array_unique($personnelIds));
        if ($personnelIds === []) {
            throw ValidationException::withMessages(['assignee_ids' => 'Select at least one assigned inspector.']);
        }

        return DB::transaction(function () use ($item, $personnelIds, $actor, $reason, $notify, $syncRun): array {
            $active = $item->assignments()->whereNull('unassigned_at')->get();
            $removed = $active->whereNotIn('fmo_personnel_id', $personnelIds);
            $addedIds = collect($personnelIds)->diff($active->pluck('fmo_personnel_id'));
            $item->assignments()->whereNull('unassigned_at')->whereIn('fmo_personnel_id', $removed->pluck('fmo_personnel_id'))
                ->update(['unassigned_at' => now(), 'unassigned_by' => $actor->id, 'change_reason' => $reason, 'active_marker' => null]);
            foreach ($addedIds as $personnelId) {
                InspectionItemAssignment::create([
                    'inspection_item_id' => $item->id, 'fmo_personnel_id' => $personnelId,
                    'assigned_by' => $actor->id, 'assigned_at' => now(), 'change_reason' => $reason, 'active_marker' => 1,
                ]);
            }
            $item->loadMissing('run.template');
            if ($syncRun) {
                $this->syncOccurrenceTeam($item->run);
            }
            if ($notify) {
                $this->notifyPersonnel($item->run, $addedIds, 1, false);
                $this->notifyPersonnel($item->run, $removed->pluck('fmo_personnel_id'), 1, true);
            }

            return ['added' => $addedIds->all(), 'removed' => $removed->pluck('fmo_personnel_id')->all()];
        });
    }

    /** @param array<int, string> $personnelIds */
    public function replaceRunTeam(InspectionRun $run, array $personnelIds, User $actor, ?string $reason = null): void
    {
        $changes = ['added' => [], 'removed' => []];
        DB::transaction(function () use ($run, $personnelIds, $actor, $reason, &$changes): void {
            $items = $run->items()->where('status', '!=', 'INSPECTED')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['assignee_ids' => 'This inspection has no unfinished assets to reassign.']);
            }
            foreach ($items as $item) {
                $itemChanges = $this->replaceItemTeam($item, $personnelIds, $actor, $reason, false, false);
                foreach (['added', 'removed'] as $type) {
                    foreach ($itemChanges[$type] as $id) {
                        $changes[$type][$id] = ($changes[$type][$id] ?? 0) + 1;
                    }
                }
            }
            $this->syncOccurrenceTeam($run);
        });
        foreach ($changes['added'] as $id => $count) {
            $this->notifyPersonnel($run, collect([$id]), $count, false);
        }
        foreach ($changes['removed'] as $id => $count) {
            $this->notifyPersonnel($run, collect([$id]), $count, true);
        }
    }

    /** @param array<string, array<int, string>> $overrides */
    public function syncTemplateOverrides(RoutineTaskTemplate $template, array $overrides, User $actor): void
    {
        $desired = collect($overrides)->map(fn ($ids) => array_values(array_unique((array) $ids)));
        $active = $template->assetAssignmentOverrides()->whereNull('unassigned_at')->get();
        foreach ($active as $assignment) {
            if (! in_array($assignment->fmo_personnel_id, $desired->get($assignment->inspectable_asset_id, []), true)) {
                $assignment->update(['unassigned_at' => now(), 'unassigned_by' => $actor->id, 'change_reason' => 'Template assignment updated.', 'active_marker' => null]);
            }
        }
        foreach ($desired as $assetId => $personnelIds) {
            $current = $active->where('inspectable_asset_id', $assetId)->pluck('fmo_personnel_id');
            foreach (collect($personnelIds)->diff($current) as $personnelId) {
                InspectionTemplateAssetAssignment::create([
                    'routine_task_template_id' => $template->id, 'inspectable_asset_id' => $assetId,
                    'fmo_personnel_id' => $personnelId, 'assigned_by' => $actor->id,
                    'assigned_at' => now(), 'change_reason' => 'Template assignment updated.', 'active_marker' => 1,
                ]);
            }
        }
    }

    public function syncOccurrenceTeam(InspectionRun $run): void
    {
        $personnelIds = InspectionItemAssignment::query()
            ->whereHas('item', fn ($query) => $query->where('inspection_run_id', $run->id))
            ->whereNull('unassigned_at')->distinct()->pluck('fmo_personnel_id');
        $run->occurrence->assignees()->sync($personnelIds);
    }

    private function notifyPersonnel(InspectionRun $run, Collection $personnelIds, int $count, bool $removed): void
    {
        FmoPersonnel::with('user')->whereIn('id', $personnelIds)->get()->each(function (FmoPersonnel $personnel) use ($run, $count, $removed): void {
            if ($personnel->user && $personnel->isAssignable()) {
                $personnel->user->notify(new InspectionAssignmentNotice($run->loadMissing('template'), 'INSPECTION_ASSIGNMENT_UPDATED', $count, $removed));
            }
        });
    }
}
