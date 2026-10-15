<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Enums\RoutineOccurrenceStatus;
use App\Http\Controllers\Controller;
use App\Models\FmoPersonnel;
use App\Models\InspectionItem;
use App\Models\InspectionRun;
use App\Models\User;
use App\Notifications\InspectionAttentionNotice;
use App\Services\OperationalEvidenceService;
use App\Services\RoutineTaskAccessService;
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
        $query = InspectionRun::with(['template.assetType', 'occurrence.assignees.user'])->withCount([
            'items as items_count' => fn ($q) => $assignedScope($q),
            'items as inspected_count' => fn ($q) => $assignedScope($q)->where('status', 'INSPECTED'),
            'items as attention_count' => fn ($q) => $assignedScope($q)->whereIn('result', ['NEEDS_ATTENTION', 'DEFECTIVE', 'MISSING', 'OUT_OF_SERVICE', 'NEEDS_REFILL', 'EXPIRED']),
            'items as overdue_count' => fn ($q) => $assignedScope($q)->where('status', '!=', 'INSPECTED')->whereHas('run.occurrence', fn ($occurrence) => $occurrence->where('due_at', '<', now(config('app.timezone')))),
        ])->latest('created_at');
        $access->scopeInspectionRuns($query, $request->user());
        $runs = $query->paginate(20)->withQueryString();

        return view('routine-operations.inspections.index', compact('runs'));
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

    public function record(Request $request, InspectionRun $inspection, InspectionItem $item, OperationalEvidenceService $evidence)
    {
        abort_unless($item->inspection_run_id === $inspection->id, 404);
        abort_unless(app(RoutineTaskAccessService::class)->canPerformInspectionItem($item, $request->user()), 403);
        abort_if($item->status === 'INSPECTED', 422, 'This inspection item is already complete.');
        $item->load('asset.assetType', 'attachments');
        abort_if(in_array($item->asset->lifecycle_status->value, ['DISPOSED', 'RETIRED'], true), 422, 'Disposed or retired assets cannot receive new inspections.');
        $options = $item->asset->assetType->result_options;
        $data = $request->validate([
            'result' => ['required', Rule::in($options)], 'checklist' => ['required', 'array'], 'checklist.*' => ['required', Rule::in(['YES', 'NO', 'NOT_APPLICABLE'])],
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
        if (in_array($data['result'], ['DEFECTIVE', 'OUT_OF_SERVICE', 'MISSING'], true) && $item->attachments->isEmpty() && empty($data['evidence'])) {
            throw ValidationException::withMessages(['evidence' => 'Photo evidence is required for a damaged or non-serviceable asset.']);
        }
        $personnel = $request->user()->personnelProfile;
        DB::transaction(function () use ($item, $inspection, $data, $request, $personnel, $evidence): void {
            $lockedItem = InspectionItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedItem->status === 'INSPECTED', 422, 'This inspection item is already complete.');
            $failedLabels = collect($item->checklist_snapshot ?: $item->asset->assetType->checklist)->filter(fn ($check) => ($data['checklist'][$check['code']] ?? null) === 'NO')->pluck('label');
            $summary = 'Inspected '.$item->asset->asset_code.'. Result: '.str_replace('_', ' ', strtolower($data['result'])).'.';
            if ($failedLabels->isNotEmpty()) {
                $summary .= ' Needs attention: '.$failedLabels->join(', ', ', and ').'.';
            }
            $condition = match ($data['result']) {
                'GOOD' => 'GOOD', 'NEEDS_ATTENTION' => 'FAIR', 'MISSING' => 'UNUSABLE', default => 'NEEDS_REPAIR'
            };
            $lockedItem->update(['status' => 'INSPECTED', 'inspector_personnel_id' => $personnel?->id, 'inspector_user_id' => $request->user()->id,
                'inspected_at' => now(), 'result' => $data['result'], 'condition' => $condition, 'checklist_results' => $data['checklist'], 'generated_summary' => $summary, 'remarks' => $data['remarks'] ?? null]);
            $item->asset->update(['condition' => $condition, 'last_inspected_at' => now()]);
            if (! empty($data['evidence'])) {
                $evidence->attach($lockedItem, $data['evidence'], $request->user()->id, 'INSPECTION_EVIDENCE');
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
