<?php

namespace App\Services;

use App\Models\AssetLifecycleEvent;
use App\Models\AssetMaintenanceRecord;
use App\Models\InspectableAsset;
use App\Models\InspectionItem;
use App\Models\PreventiveMaintenanceOccurrence;
use App\Models\WorkOrder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AssetMaintenanceHistoryService
{
    public function paginate(InspectableAsset $asset, int $perPage = 20): LengthAwarePaginator
    {
        $assetIds = collect([$asset->id])->merge($asset->childAssets()->pluck('id'));
        $events = collect();
        $pm = PreventiveMaintenanceOccurrence::with(['asset', 'execution.performer.user', 'correctiveWorkOrders'])
            ->whereIn('inspectable_asset_id', $assetIds)->get();
        $pm->each(function ($occurrence) use ($events): void {
            $events->push([
                'date' => $occurrence->completed_at ?: $occurrence->due_at,
                'type' => $occurrence->completed_at ? 'PM_COMPLETED' : 'PM_SCHEDULED',
                'title' => $occurrence->completed_at ? 'Preventive Maintenance Completed' : 'Preventive Maintenance Due',
                'asset' => $occurrence->asset->name, 'summary' => $occurrence->execution?->generated_summary ?: $occurrence->plan_name_snapshot,
                'reference' => $occurrence->correctiveWorkOrders->pluck('work_order_number')->join(', '),
            ]);
        });
        InspectionItem::with(['asset', 'inspector.user'])->whereIn('inspectable_asset_id', $assetIds)->where('status', 'INSPECTED')->get()->each(fn ($item) => $events->push([
            'date' => $item->inspected_at, 'type' => 'INSPECTION_COMPLETED', 'title' => 'Inspection Completed', 'asset' => $item->asset->name,
            'summary' => $item->generated_summary ?: str($item->result)->replace('_', ' ')->title(), 'reference' => null,
        ]));
        AssetMaintenanceRecord::with('asset')->whereIn('inspectable_asset_id', $assetIds)->get()->each(fn ($record) => $events->push([
            'date' => $record->recorded_at, 'type' => 'REPAIR_RECORDED', 'title' => 'Repair / Maintenance Recorded', 'asset' => $record->asset->name,
            'summary' => $record->work_performed, 'reference' => null,
        ]));
        AssetLifecycleEvent::with('asset')->whereIn('inspectable_asset_id', $assetIds)->get()->each(fn ($event) => $events->push([
            'date' => $event->created_at, 'type' => $event->event, 'title' => str($event->event)->replace('_', ' ')->title(), 'asset' => $event->asset->name,
            'summary' => $event->remarks, 'reference' => null,
        ]));
        WorkOrder::with('asset')->whereIn('inspectable_asset_id', $assetIds)->get()->each(fn ($order) => $events->push([
            'date' => $order->verified_at ?: $order->submitted_at, 'type' => $order->verified_at ? 'REPAIR_COMPLETED' : 'CORRECTIVE_WORK_ORDER',
            'title' => $order->verified_at ? 'Repair Completed' : 'Corrective Work Order Created', 'asset' => $order->asset?->name,
            'summary' => $order->verified_at ? ($order->work_performed_summary ?: $order->subject) : $order->subject, 'reference' => $order->work_order_number,
        ]));
        $sorted = $events->filter(fn ($event) => $event['date'])->sortByDesc('date')->values();
        $page = LengthAwarePaginator::resolveCurrentPage('history_page');

        return new LengthAwarePaginator($sorted->forPage($page, $perPage), $sorted->count(), $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'history_page',
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function all(InspectableAsset $asset): Collection
    {
        return collect($this->paginate($asset, 10000)->items());
    }
}
