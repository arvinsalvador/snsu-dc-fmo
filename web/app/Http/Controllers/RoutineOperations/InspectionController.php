<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Enums\RoutineOccurrenceStatus;
use App\Http\Controllers\Controller;
use App\Models\AssetType;
use App\Models\Building;
use App\Models\FmoPersonnel;
use App\Models\InspectionItem;
use App\Models\InspectionRun;
use App\Models\RoutineTaskOccurrence;
use App\Models\RoutineTaskTemplate;
use App\Models\User;
use App\Notifications\InspectionAttentionNotice;
use App\Services\AssetLifecycleService;
use App\Services\OperationalEvidenceService;
use App\Services\RoutineTaskAccessService;
use App\Services\RoutineTaskAvailabilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InspectionController extends Controller
{
    public function index(Request $request, RoutineTaskAccessService $access)
    {
        abort_unless($request->user()->can('inspections.view'), 403);
        $personnel = $access->activePersonnel($request->user());
        $assignedScope = fn ($query) => $request->user()->can('inspections.view_all') ? $query : $query->whereHas('assignments', fn ($assignment) => $assignment
            ->where('fmo_personnel_id', $personnel?->id)->whereNull('unassigned_at'));
        $status = strtolower(trim((string) $request->query('status', 'all')));
        $frequency = strtoupper(trim((string) $request->query('frequency', '')));
        $sort = strtolower(trim((string) $request->query('sort', 'operational')));
        $perPage = $request->integer('per_page', 20);
        $filters = [
            'search' => mb_substr(trim((string) $request->query('search', '')), 0, 100),
            'status' => in_array($status, ['due', 'in_progress', 'overdue', 'upcoming', 'completed', 'all'], true) ? $status : 'all',
            'asset_type' => (string) $request->query('asset_type', ''),
            'building' => (string) $request->query('building', ''),
            'frequency' => in_array($frequency, ['DAILY', 'WEEKLY', 'MONTHLY'], true) ? $frequency : '',
            'sort' => in_array($sort, ['operational', 'due_asc', 'newest', 'title'], true) ? $sort : 'operational',
            'per_page' => in_array($perPage, [20, 50, 100], true) ? $perPage : 20,
        ];
        $base = InspectionRun::query();
        $access->scopeInspectionRuns($base, $request->user());
        $applyState = function (Builder $query, string $state): Builder {
            $closed = [RoutineOccurrenceStatus::Completed->value, RoutineOccurrenceStatus::CompletedLate->value, RoutineOccurrenceStatus::UnableToComplete->value];

            return match ($state) {
                'overdue' => $query->where('status', '!=', 'COMPLETED')->whereHas('occurrence', fn (Builder $occurrence) => $occurrence->where('due_at', '<', now(config('app.timezone')))->whereNotIn('status', $closed)),
                'in_progress' => $query->where('status', 'IN_PROGRESS'),
                'upcoming' => $query->where('status', '!=', 'COMPLETED')->whereHas('occurrence', fn (Builder $occurrence) => $occurrence->whereDate('expected_date', '>', now(config('app.timezone'))->toDateString())),
                'completed' => $query->where('status', 'COMPLETED'),
                'due' => $query->where('status', 'OPEN')->whereHas('occurrence', fn (Builder $occurrence) => $occurrence->whereDate('expected_date', '<=', now(config('app.timezone'))->toDateString())->where('due_at', '>=', now(config('app.timezone')))),
                default => $query,
            };
        };
        $summary = collect(['due', 'in_progress', 'overdue', 'upcoming', 'completed'])
            ->mapWithKeys(fn (string $state): array => [$state => $applyState(clone $base, $state)->count()]);
        $summary['all'] = (clone $base)->count();
        $query = (clone $base)->with([
            'template.assetType', 'template.building', 'template.floor', 'template.location',
            'occurrence.assignees.user',
        ])->withCount([
            'items as items_count' => fn ($q) => $assignedScope($q),
            'items as inspected_count' => fn ($q) => $assignedScope($q)->where('status', 'INSPECTED'),
            'items as attention_count' => fn ($q) => $assignedScope($q)->whereIn('result', ['NEEDS_ATTENTION', 'DEFECTIVE', 'MISSING', 'OUT_OF_SERVICE', 'NEEDS_REFILL', 'EXPIRED']),
            'items as overdue_count' => fn ($q) => $assignedScope($q)->where('status', '!=', 'INSPECTED')->whereHas('run.occurrence', fn ($occurrence) => $occurrence->where('due_at', '<', now(config('app.timezone')))),
        ])->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
            $term = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(function (Builder $query) use ($term): void {
                $query->where('period_key', 'like', $term)
                    ->orWhereHas('template', fn (Builder $template) => $template->where('title', 'like', $term))
                    ->orWhereHas('items.asset', fn (Builder $asset) => $asset->where('asset_code', 'like', $term)->orWhere('name', 'like', $term));
            });
        })->when($filters['asset_type'], fn (Builder $query, string $value) => $query->whereHas('template', fn (Builder $template) => $template->where('asset_type_id', $value)))
            ->when($filters['building'], function (Builder $query, string $value): void {
                $query->where(function (Builder $query) use ($value): void {
                    $query->whereHas('template', fn (Builder $template) => $template->where('building_id', $value))
                        ->orWhereHas('items.asset', fn (Builder $asset) => $asset->where('building_id', $value));
                });
            })->when($filters['frequency'], fn (Builder $query, string $value) => $query->whereHas('template', fn (Builder $template) => $template->where('frequency', $value)));
        $applyState($query, $filters['status']);
        $dueAt = RoutineTaskOccurrence::query()->select('due_at')->whereColumn('routine_task_occurrences.id', 'inspection_runs.routine_task_occurrence_id');
        match ($filters['sort']) {
            'due_asc' => $query->orderBy($dueAt)->orderBy('id'),
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'title' => $query->orderBy(RoutineTaskTemplate::query()->select('title')->whereColumn('routine_task_templates.id', 'inspection_runs.routine_task_template_id'))->orderBy('id'),
            default => $query->orderByRaw("CASE WHEN inspection_runs.status = 'COMPLETED' THEN 1 ELSE 0 END")->orderBy($dueAt)->orderBy('id'),
        };
        $runs = $query->paginate($filters['per_page'])->appends(array_filter($filters, fn (mixed $value): bool => $value !== '' && $value !== null));

        return view('routine-operations.inspections.index', [
            'runs' => $runs,
            'filters' => $filters,
            'summary' => $summary,
            'assetTypes' => AssetType::query()->where('is_active', true)->orderBy('name')->get(),
            'buildings' => Building::query()->where('is_active', true)->orderBy('name')->get(),
            'hasFilters' => $filters['search'] !== '' || $filters['asset_type'] !== '' || $filters['building'] !== '' || $filters['frequency'] !== '' || $filters['sort'] !== 'operational',
        ]);
    }

    public function show(Request $request, InspectionRun $inspection, RoutineTaskAccessService $access)
    {
        $this->authorizeRun($request, $inspection, false);
        $itemScope = fn ($query) => $request->user()->can('inspections.view_all') ? $query : $access->scopeInspectionItems($query, $request->user());
        $inspection->load(['template.assetType', 'occurrence.assignees.user', 'items' => fn ($query) => $itemScope($query)
            ->with(['asset.assetType', 'asset.building', 'asset.floor', 'asset.location', 'inspector.user', 'attachments', 'activeAssignees.user', 'assignments.personnel.user', 'assignments.assigner', 'assignments.unassigner'])]);
        $performableItemIds = $inspection->items->filter(fn (InspectionItem $item) => $access->canPerformInspectionItem($item, $request->user()))->modelKeys();
        $personnel = $request->user()->can('inspections.assign')
            ? FmoPersonnel::with(['user', 'skills'])->withCount(['inspectionItemAssignments as active_inspection_items_count' => fn ($query) => $query->whereNull('unassigned_at')->whereHas('item', fn ($items) => $items->where('status', '!=', 'INSPECTED'))])
                ->orderBy('personnel_identifier')->get()
            : collect();

        return view('routine-operations.inspections.show', compact('inspection', 'performableItemIds', 'personnel'));
    }

    public function record(Request $request, InspectionRun $inspection, InspectionItem $item, OperationalEvidenceService $evidence, AssetLifecycleService $lifecycle, RoutineTaskAvailabilityService $availability)
    {
        abort_unless($item->inspection_run_id === $inspection->id, 404);
        $access = app(RoutineTaskAccessService::class);
        if ($access->isInspectionPerformer($item, $request->user()) && $item->status !== 'INSPECTED') {
            $availability->ensureActionable($inspection->occurrence);
        }
        abort_unless($access->canPerformInspectionItem($item, $request->user()), 403);
        abort_if($item->status === 'INSPECTED', 422, 'This inspection item is already complete.');
        $item->load('asset.assetType', 'attachments');
        abort_if(in_array($item->asset->lifecycle_status->value, ['DISPOSED', 'RETIRED'], true), 422, 'Disposed or retired assets cannot receive new inspections.');
        $options = $item->asset->assetType->result_options;
        $data = $request->validate([
            'result' => ['required', Rule::in($options)], 'checklist' => ['required', 'array'], 'checklist.*' => ['required', Rule::in(['YES', 'NO', 'NOT_APPLICABLE'])],
            'recommended_action' => ['required', Rule::in(['NONE', 'PREVENTIVE_MAINTENANCE_REQUIRED', 'REPAIR_REVIEW', 'DISPOSAL_REVIEW'])],
            'remarks' => ['nullable', 'string', 'max:5000'], 'evidence' => ['nullable', 'array', 'max:'.config('work_orders.attachments.max_count')], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')],
        ]);
        $checklist = $item->checklist_snapshot ?: $item->asset->assetType->checklist;
        $expected = collect($checklist)->pluck('code');
        if ($expected->diff(array_keys($data['checklist']))->isNotEmpty()) {
            throw ValidationException::withMessages(['checklist' => 'Complete every checklist item.']);
        }
        if ($inspection->template->evidence_required && $item->attachments->isEmpty() && empty($data['evidence'])) {
            throw ValidationException::withMessages(['evidence' => 'Photo evidence is required for this inspection.']);
        }
        if ((in_array($data['result'], ['NEEDS_REPAIR', 'DAMAGED', 'BROKEN', 'UNUSABLE', 'DEFECTIVE', 'OUT_OF_SERVICE', 'MISSING'], true) || $data['recommended_action'] !== 'NONE') && $item->attachments->isEmpty() && empty($data['evidence'])) {
            throw ValidationException::withMessages(['evidence' => 'Photo evidence is required for a damaged or non-serviceable asset.']);
        }
        if (in_array($data['recommended_action'], ['REPAIR_REVIEW', 'DISPOSAL_REVIEW'], true)) {
            $permission = $data['recommended_action'] === 'REPAIR_REVIEW' ? 'assets.request_repair' : 'assets.request_disposal';
            abort_unless($request->user()->can($permission), 403);
        }
        $personnel = $request->user()->personnelProfile;
        DB::transaction(function () use ($item, $inspection, $data, $request, $personnel, $evidence, $lifecycle): void {
            $lockedItem = InspectionItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedItem->status === 'INSPECTED', 422, 'This inspection item is already complete.');
            $failedLabels = collect($item->checklist_snapshot ?: $item->asset->assetType->checklist)->filter(fn ($check) => ($data['checklist'][$check['code']] ?? null) === 'NO')->pluck('label');
            $summary = 'Inspected '.$item->asset->asset_code.'. Result: '.str_replace('_', ' ', strtolower($data['result'])).'.';
            if ($failedLabels->isNotEmpty()) {
                $summary .= ' Needs attention: '.$failedLabels->join(', ', ', and ').'.';
            }
            $condition = match ($data['result']) {
                'GOOD' => 'GOOD', 'NEEDS_ATTENTION' => 'FAIR', 'NEEDS_REPAIR' => 'NEEDS_REPAIR', 'DAMAGED' => 'DAMAGED',
                'BROKEN' => 'BROKEN', 'MISSING', 'OUT_OF_SERVICE', 'RECOMMEND_DISPOSAL', 'UNUSABLE' => 'UNUSABLE', default => 'NEEDS_REPAIR'
            };
            $lockedItem->update(['status' => 'INSPECTED', 'inspector_personnel_id' => $personnel?->id, 'inspector_user_id' => $request->user()->id,
                'inspected_at' => now(), 'result' => $data['result'], 'condition' => $condition, 'recommended_action' => $data['recommended_action'],
                'checklist_results' => $data['checklist'], 'generated_summary' => $summary, 'remarks' => $data['remarks'] ?? null]);
            $item->asset->update(['condition' => $condition, 'last_inspected_at' => now()]);
            $lifecycle->event($item->asset, $request->user(), 'ASSET_INSPECTED', $summary);
            if (! empty($data['evidence'])) {
                $evidence->attach($lockedItem, $data['evidence'], $request->user()->id, 'INSPECTION_EVIDENCE');
            }
            if (in_array($data['recommended_action'], ['REPAIR_REVIEW', 'DISPOSAL_REVIEW'], true)) {
                $type = $data['recommended_action'] === 'REPAIR_REVIEW' ? 'REPAIR' : 'DISPOSAL';
                $lifecycle->request($item->asset->fresh(), $request->user(), $type, $summary, $lockedItem);
            }
            if ($inspection->items()->where('status', '!=', 'INSPECTED')->doesntExist()) {
                $inspection->update(['status' => 'COMPLETED', 'started_at' => $inspection->started_at ?? now(), 'completed_at' => now()]);
                $inspection->occurrence->update(['status' => $inspection->occurrence->due_at->isPast() ? RoutineOccurrenceStatus::CompletedLate : RoutineOccurrenceStatus::Completed,
                    'started_at' => $inspection->occurrence->started_at ?? now(), 'completed_at' => now(), 'performed_by_personnel_id' => $personnel?->id,
                    'performed_by_user_id' => $request->user()->id, 'generated_summary' => 'Completed item-by-item inspection of '.$inspection->items()->count().' assets.']);
            } else {
                $inspection->update(['status' => 'IN_PROGRESS', 'started_at' => $inspection->started_at ?? now()]);
                $inspection->occurrence->update(['status' => RoutineOccurrenceStatus::InProgress, 'started_at' => $inspection->occurrence->started_at ?? now()]);
            }
        });
        if ($data['result'] !== 'GOOD') {
            $item->refresh()->load(['asset', 'run']);
            User::permission('inspections.view_all')->get()->each(function (User $user) use ($item): void {
                $exists = $user->notifications()->where('data->event_key', 'INSPECTION_ATTENTION')->where('data->inspection_item_id', $item->id)->exists();
                if (! $exists) {
                    $user->notify(new InspectionAttentionNotice($item));
                }
            });
        }

        return redirect()->route('inspections.show', $inspection)->with('status', 'Asset inspection recorded.');
    }

    private function authorizeRun(Request $request, InspectionRun $run, bool $perform): void
    {
        abort_unless($request->user()->can($perform ? 'inspections.perform' : 'inspections.view'), 403);
        if ($request->user()->can('inspections.view_all')) {
            return;
        }
        abort_unless(app(RoutineTaskAccessService::class)->assignedToInspection($run, $request->user()), 403);
    }
}
