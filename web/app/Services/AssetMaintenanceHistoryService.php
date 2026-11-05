<?php

namespace App\Services;

use App\Enums\PreventiveMaintenanceStatus;
use App\Models\AssetLifecycleEvent;
use App\Models\AssetMaintenanceRecord;
use App\Models\InspectableAsset;
use App\Models\InspectionItem;
use App\Models\PreventiveMaintenanceOccurrence;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AssetMaintenanceHistoryService
{
    /**
     * Lifecycle events written alongside a richer source record (the inspection item or PM occurrence)
     * without changing condition or lifecycle. Listing them would duplicate that record in the timeline.
     */
    private const DUPLICATE_LIFECYCLE_EVENTS = ['ASSET_INSPECTED', 'PREVENTIVE_MAINTENANCE_COMPLETED'];

    /** @var array<string, class-string<Model>> */
    private const SOURCES = [
        'PM' => PreventiveMaintenanceOccurrence::class,
        'INSPECTION' => InspectionItem::class,
        'REPAIR_RECORD' => AssetMaintenanceRecord::class,
        'LIFECYCLE' => AssetLifecycleEvent::class,
        'WORK_ORDER' => WorkOrder::class,
    ];

    /** @var array<string, array<int, string>> */
    private const RELATIONS = [
        'PM' => ['asset', 'execution', 'correctiveWorkOrders'],
        'INSPECTION' => ['asset'],
        'REPAIR_RECORD' => ['asset'],
        'LIFECYCLE' => ['asset'],
        'WORK_ORDER' => ['asset'],
    ];

    /** Chronological history for an asset and its immediate child components, paginated by the database. */
    public function paginate(InspectableAsset $asset, int $perPage = 20): LengthAwarePaginator
    {
        $assetIds = collect([$asset->id])->merge($asset->childAssets()->pluck('id'))->all();
        $page = DB::query()->fromSub($this->timeline($assetIds), 'history')
            ->whereNotNull('event_at')
            ->orderByDesc('event_at')->orderByDesc('source')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'history_page');

        $rows = collect($page->items());
        $models = $rows->groupBy('source')->map(fn (Collection $group, string $source): Collection => self::SOURCES[$source]::query()
            ->with(self::RELATIONS[$source])->whereKey($group->pluck('id')->all())->get()->keyBy('id'));

        $page->setCollection($rows
            ->map(fn (object $row): ?array => ($model = $models->get($row->source)?->get($row->id)) ? $this->present($row->source, $model) : null)
            ->filter()->values());

        return $page;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function all(InspectableAsset $asset): Collection
    {
        return collect($this->paginate($asset, 10000)->items());
    }

    /** @param array<int, string> $assetIds */
    private function timeline(array $assetIds): Builder
    {
        $now = now(config('app.timezone'));

        return DB::table('preventive_maintenance_occurrences')
            ->selectRaw("'PM' as source, id, COALESCE(completed_at, due_at) as event_at")
            ->whereIn('inspectable_asset_id', $assetIds)
            ->where('status', '!=', PreventiveMaintenanceStatus::Cancelled->value)
            ->where(fn (Builder $query) => $query->whereNotNull('completed_at')->orWhere('due_at', '<=', $now))
            ->unionAll(DB::table('inspection_items')
                ->selectRaw("'INSPECTION' as source, id, inspected_at as event_at")
                ->whereIn('inspectable_asset_id', $assetIds)->where('status', 'INSPECTED'))
            ->unionAll(DB::table('asset_maintenance_records')
                ->selectRaw("'REPAIR_RECORD' as source, id, recorded_at as event_at")
                ->whereIn('inspectable_asset_id', $assetIds))
            ->unionAll(DB::table('asset_lifecycle_events')
                ->selectRaw("'LIFECYCLE' as source, id, created_at as event_at")
                ->whereIn('inspectable_asset_id', $assetIds)->whereNotIn('event', self::DUPLICATE_LIFECYCLE_EVENTS))
            ->unionAll(DB::table('work_orders')
                ->selectRaw("'WORK_ORDER' as source, id, COALESCE(verified_at, submitted_at) as event_at")
                ->whereIn('inspectable_asset_id', $assetIds));
    }

    /** @return array{date: mixed, type: string, title: string, asset: ?string, summary: ?string, reference: ?string} */
    private function present(string $source, Model $model): array
    {
        return match ($source) {
            'PM' => [
                'date' => $model->completed_at ?: $model->due_at,
                'type' => $model->completed_at ? 'PM_COMPLETED' : 'PM_OVERDUE',
                'title' => $model->completed_at ? 'Preventive Maintenance Completed' : 'Preventive Maintenance Overdue',
                'asset' => $model->asset?->name, 'summary' => $model->execution?->generated_summary ?: $model->plan_name_snapshot,
                'reference' => $model->correctiveWorkOrders->pluck('work_order_number')->join(', ') ?: null,
            ],
            'INSPECTION' => [
                'date' => $model->inspected_at, 'type' => 'INSPECTION_COMPLETED', 'title' => 'Inspection Completed', 'asset' => $model->asset?->name,
                'summary' => $model->generated_summary ?: str($model->result)->replace('_', ' ')->title()->value(), 'reference' => null,
            ],
            'REPAIR_RECORD' => [
                'date' => $model->recorded_at, 'type' => 'REPAIR_RECORDED', 'title' => 'Repair / Maintenance Recorded', 'asset' => $model->asset?->name,
                'summary' => $model->work_performed, 'reference' => null,
            ],
            'LIFECYCLE' => [
                'date' => $model->created_at, 'type' => $model->event, 'title' => str($model->event)->replace('_', ' ')->title()->value(), 'asset' => $model->asset?->name,
                'summary' => $model->remarks, 'reference' => null,
            ],
            'WORK_ORDER' => [
                'date' => $model->verified_at ?: $model->submitted_at, 'type' => $model->verified_at ? 'REPAIR_COMPLETED' : 'CORRECTIVE_WORK_ORDER',
                'title' => $model->verified_at ? 'Repair Completed' : 'Corrective Work Order Created', 'asset' => $model->asset?->name,
                'summary' => $model->verified_at ? ($model->work_performed_summary ?: $model->subject) : $model->subject, 'reference' => $model->work_order_number,
            ],
        };
    }
}
