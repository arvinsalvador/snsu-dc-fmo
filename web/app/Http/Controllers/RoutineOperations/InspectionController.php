<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Enums\RoutineOccurrenceStatus;
use App\Http\Controllers\Controller;
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
        $query = InspectionRun::with(['template.assetType', 'occurrence.assignees.user'])->withCount([
            'items', 'items as inspected_count' => fn ($q) => $q->where('status', 'INSPECTED'),
            'items as attention_count' => fn ($q) => $q->whereIn('result', ['NEEDS_ATTENTION', 'DEFECTIVE', 'MISSING', 'OUT_OF_SERVICE', 'NEEDS_REFILL', 'EXPIRED']),
        ])->latest('created_at');
        $access->scopeInspectionRuns($query, $request->user());
        $runs = $query->paginate(20)->withQueryString();

        return view('routine-operations.inspections.index', compact('runs'));
    }

    public function show(Request $request, InspectionRun $inspection)
    {
        $this->authorizeRun($request, $inspection, false);
        $inspection->load(['template.assetType', 'occurrence.assignees.user', 'items.asset.assetType', 'items.asset.building', 'items.asset.location', 'items.inspector.user', 'items.attachments']);

        return view('routine-operations.inspections.show', compact('inspection'));
    }

    public function record(Request $request, InspectionRun $inspection, InspectionItem $item, OperationalEvidenceService $evidence)
    {
        $this->authorizeRun($request, $inspection, true);
        abort_unless($item->inspection_run_id === $inspection->id, 404);
        $item->load('asset.assetType', 'attachments');
        abort_if(in_array($item->asset->lifecycle_status->value, ['DISPOSED', 'RETIRED'], true), 422, 'Disposed or retired assets cannot receive new inspections.');
        $options = $item->asset->assetType->result_options;
        $data = $request->validate([
            'result' => ['required', Rule::in($options)], 'checklist' => ['required', 'array'], 'checklist.*' => ['required', Rule::in(['YES', 'NO', 'NOT_APPLICABLE'])],
            'remarks' => ['nullable', 'string', 'max:5000'], 'evidence' => ['nullable', 'array', 'max:'.config('work_orders.attachments.max_count')], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')],
        ]);
        $expected = collect($item->asset->assetType->checklist)->pluck('code');
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
            $failedLabels = collect($item->asset->assetType->checklist)->filter(fn ($check) => ($data['checklist'][$check['code']] ?? null) === 'NO')->pluck('label');
            $summary = 'Inspected '.$item->asset->asset_code.'. Result: '.str_replace('_', ' ', strtolower($data['result'])).'.';
            if ($failedLabels->isNotEmpty()) {
                $summary .= ' Needs attention: '.$failedLabels->join(', ', ', and ').'.';
            }
            $condition = match ($data['result']) {
                'GOOD' => 'GOOD', 'NEEDS_ATTENTION' => 'FAIR', 'MISSING' => 'UNUSABLE', default => 'NEEDS_REPAIR'
            };
            $item->update(['status' => 'INSPECTED', 'inspector_personnel_id' => $personnel?->id, 'inspector_user_id' => $request->user()->id,
                'inspected_at' => now(), 'result' => $data['result'], 'condition' => $condition, 'checklist_results' => $data['checklist'], 'generated_summary' => $summary, 'remarks' => $data['remarks'] ?? null]);
            $item->asset->update(['condition' => $condition, 'last_inspected_at' => now()]);
            if (! empty($data['evidence'])) {
                $evidence->attach($item, $data['evidence'], $request->user()->id, 'INSPECTION_EVIDENCE');
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
