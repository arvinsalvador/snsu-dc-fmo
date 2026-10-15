<?php

namespace App\Http\Controllers\Api;

use App\Enums\RoutineOccurrenceStatus;
use App\Enums\RoutineTaskType;
use App\Http\Controllers\Controller;
use App\Models\AssetType;
use App\Models\GuidedDescriptionProfile;
use App\Models\InspectableAsset;
use App\Models\InspectionItem;
use App\Models\InspectionRun;
use App\Models\RoutineTaskOccurrence;
use App\Services\GuidedDescriptionService;
use App\Services\OperationalEvidenceService;
use App\Services\RoutineTaskAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoutineOperationsController extends Controller
{
    public function tasks(Request $request, RoutineTaskAccessService $access)
    {
        abort_unless($request->user()->can('routine_tasks.view'), 403);
        $query = RoutineTaskOccurrence::with(['template.campus', 'template.building', 'template.guidedProfile', 'assignees.user', 'inspectionRun'])->orderBy('due_at');
        $access->scopeOccurrences($query, $request->user());

        return response()->json($query->paginate(min(100, max(1, $request->integer('per_page', 20)))));
    }

    public function task(Request $request, RoutineTaskOccurrence $routineTask)
    {
        $this->authorizeTask($request, $routineTask);

        return response()->json(['data' => $routineTask->load(['template.guidedProfile', 'template.campus', 'template.building', 'assignees.user', 'attachments', 'inspectionRun.items.asset'])]);
    }

    public function start(Request $request, RoutineTaskOccurrence $routineTask)
    {
        $this->authorizeTask($request, $routineTask, true);
        abort_unless(in_array($routineTask->status, [RoutineOccurrenceStatus::Due, RoutineOccurrenceStatus::Upcoming, RoutineOccurrenceStatus::Overdue], true), 422);
        $routineTask->update(['status' => RoutineOccurrenceStatus::InProgress, 'started_at' => $routineTask->started_at ?? now()]);

        return response()->json(['data' => $routineTask->fresh()]);
    }

    public function resolve(Request $request, RoutineTaskOccurrence $routineTask, GuidedDescriptionService $guided, OperationalEvidenceService $evidence)
    {
        $this->authorizeTask($request, $routineTask, true);
        $routineTask->load(['template.guidedProfile', 'attachments']);
        abort_if($routineTask->template->task_type === RoutineTaskType::Inspection, 422, 'Inspection occurrences must be completed item by item.');
        $data = $request->validate([
            'resolution' => ['required', Rule::in(['COMPLETED', 'COMPLETED_LATE', 'PARTIALLY_COMPLETED', 'UNABLE_TO_COMPLETE', 'WAITING_FOR_SUPPLIES'])],
            'guided_actions' => ['nullable', 'array'], 'guided_actions.*' => ['string', 'max:60'], 'guided_outcome' => ['nullable', 'string', 'max:60'],
            'generated_summary' => ['nullable', 'string', 'max:5000'], 'additional_remarks' => ['nullable', 'required_if:resolution_code,OTHER', 'string', 'max:5000'],
            'remaining_work' => ['nullable', 'required_if:resolution,PARTIALLY_COMPLETED', 'string', 'max:5000'],
            'resolution_code' => ['nullable', 'required_if:resolution,UNABLE_TO_COMPLETE,WAITING_FOR_SUPPLIES', Rule::in(['MISSING_SUPPLIES', 'AREA_INACCESSIBLE', 'EQUIPMENT_UNAVAILABLE', 'WEATHER', 'URGENT_WORK', 'SAFETY_ISSUE', 'OTHER'])],
            'referenced_work_order_id' => ['nullable', 'exists:work_orders,id'], 'actual_completion_at' => ['nullable', 'required_if:resolution,COMPLETED_LATE', 'date', 'before_or_equal:now'],
            'evidence' => ['nullable', 'array', 'max:'.config('work_orders.attachments.max_count')], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')],
        ]);
        $profile = $routineTask->template->guidedProfile;
        if ($profile) {
            if (empty($data['guided_actions']) || empty($data['guided_outcome'])) {
                throw ValidationException::withMessages(['guided_actions' => 'Select the work performed and result.']);
            }
            $data['generated_summary'] = trim($data['generated_summary'] ?? '') ?: $guided->generate($profile, $data['guided_actions'], $data['guided_outcome']);
        }
        if (blank($data['generated_summary'] ?? null)) {
            throw ValidationException::withMessages(['generated_summary' => 'Describe the work performed or what happened.']);
        }
        if (($routineTask->template->evidence_required || $data['resolution'] === 'PARTIALLY_COMPLETED') && $routineTask->attachments->isEmpty() && empty($data['evidence'])) {
            throw ValidationException::withMessages(['evidence' => 'Photo evidence is required for this task.']);
        }
        $status = $data['resolution'] === 'COMPLETED' && $routineTask->due_at->isPast() ? 'COMPLETED_LATE' : $data['resolution'];
        DB::transaction(function () use ($routineTask, $data, $status, $request, $evidence): void {
            $routineTask->update(['status' => $status, 'started_at' => $routineTask->started_at ?? now(),
                'completed_at' => in_array($status, ['COMPLETED', 'COMPLETED_LATE', 'UNABLE_TO_COMPLETE'], true) ? ($data['actual_completion_at'] ?? now()) : null,
                'performed_by_personnel_id' => $request->user()->personnelProfile?->id, 'performed_by_user_id' => $request->user()->id,
                'structured_actions' => $data['guided_actions'] ?? [], 'guided_outcome' => $data['guided_outcome'] ?? null,
                'generated_summary' => $data['generated_summary'], 'additional_remarks' => $data['additional_remarks'] ?? null,
                'remaining_work' => $data['remaining_work'] ?? null, 'resolution_code' => $data['resolution_code'] ?? null,
                'referenced_work_order_id' => $data['referenced_work_order_id'] ?? null]);
            if (! empty($data['evidence'])) {
                $evidence->attach($routineTask, $data['evidence'], $request->user()->id, 'ROUTINE_EVIDENCE');
            }
        });

        return response()->json(['data' => $routineTask->fresh(['attachments'])]);
    }

    public function assets(Request $request)
    {
        abort_unless($request->user()->can('assets.view'), 403);

        return response()->json(InspectableAsset::with(['assetType', 'campus', 'building', 'floor', 'location'])->orderBy('asset_code')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function asset(Request $request, InspectableAsset $asset)
    {
        abort_unless($request->user()->can('assets.view'), 403);

        return response()->json(['data' => $asset->load(['assetType', 'campus', 'building', 'floor', 'location', 'inspectionItems.run'])]);
    }

    public function assetByQr(Request $request, string $token)
    {
        abort_unless($request->user()->can('assets.view'), 403);
        $asset = InspectableAsset::where('qr_token', $token)->firstOrFail();

        return response()->json(['data' => $asset->load(['assetType', 'campus', 'building', 'floor', 'location'])]);
    }

    public function inspections(Request $request, RoutineTaskAccessService $access)
    {
        abort_unless($request->user()->can('inspections.view'), 403);
        $query = InspectionRun::with(['template.assetType', 'occurrence'])->withCount(['items', 'items as inspected_count' => fn ($q) => $q->where('status', 'INSPECTED')])->latest();
        $access->scopeInspectionRuns($query, $request->user());

        return response()->json($query->paginate(min(100, max(1, $request->integer('per_page', 20)))));
    }

    public function inspection(Request $request, InspectionRun $inspection)
    {
        $this->authorizeInspection($request, $inspection);

        return response()->json(['data' => $inspection->load(['template.assetType', 'occurrence', 'items.asset.assetType', 'items.attachments'])]);
    }

    public function recordInspection(Request $request, InspectionRun $inspection, InspectionItem $item, OperationalEvidenceService $evidence)
    {
        $this->authorizeInspection($request, $inspection, true);
        abort_unless($item->inspection_run_id === $inspection->id, 404);
        $item->load(['asset.assetType', 'attachments']);
        abort_if(in_array($item->asset->lifecycle_status->value, ['DISPOSED', 'RETIRED'], true), 422, 'Disposed or retired assets cannot receive new inspections.');
        $data = $request->validate(['result' => ['required', Rule::in($item->asset->assetType->result_options)], 'checklist' => ['required', 'array'],
            'checklist.*' => ['required', Rule::in(['YES', 'NO', 'NOT_APPLICABLE'])], 'remarks' => ['nullable', 'string', 'max:5000'],
            'evidence' => ['nullable', 'array', 'max:'.config('work_orders.attachments.max_count')], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')]]);
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
        DB::transaction(function () use ($item, $inspection, $data, $request, $evidence): void {
            $condition = match ($data['result']) {
                'GOOD' => 'GOOD', 'NEEDS_ATTENTION' => 'FAIR', 'MISSING' => 'UNUSABLE', default => 'NEEDS_REPAIR'
            };
            $item->update(['status' => 'INSPECTED', 'inspector_personnel_id' => $request->user()->personnelProfile?->id, 'inspector_user_id' => $request->user()->id,
                'inspected_at' => now(), 'result' => $data['result'], 'condition' => $condition, 'checklist_results' => $data['checklist'],
                'generated_summary' => 'Inspected '.$item->asset->asset_code.'. Result: '.str_replace('_', ' ', strtolower($data['result'])).'.', 'remarks' => $data['remarks'] ?? null]);
            $item->asset->update(['condition' => $condition, 'last_inspected_at' => now()]);
            if (! empty($data['evidence'])) {
                $evidence->attach($item, $data['evidence'], $request->user()->id, 'INSPECTION_EVIDENCE');
            }
            if ($inspection->items()->where('status', '!=', 'INSPECTED')->doesntExist()) {
                $inspection->update(['status' => 'COMPLETED', 'started_at' => $inspection->started_at ?? now(), 'completed_at' => now()]);
                $inspection->occurrence->update(['status' => $inspection->occurrence->due_at->isPast() ? 'COMPLETED_LATE' : 'COMPLETED', 'started_at' => $inspection->occurrence->started_at ?? now(),
                    'completed_at' => now(), 'performed_by_personnel_id' => $request->user()->personnelProfile?->id, 'performed_by_user_id' => $request->user()->id,
                    'generated_summary' => 'Completed item-by-item inspection of '.$inspection->items()->count().' assets.']);
            } else {
                $inspection->update(['status' => 'IN_PROGRESS', 'started_at' => $inspection->started_at ?? now()]);
                $inspection->occurrence->update(['status' => 'IN_PROGRESS', 'started_at' => $inspection->occurrence->started_at ?? now()]);
            }
        });

        return response()->json(['data' => $item->fresh(['attachments']), 'progress' => ['inspected' => $inspection->items()->where('status', 'INSPECTED')->count(), 'total' => $inspection->items()->count()]]);
    }

    public function profiles(Request $request)
    {
        abort_unless($request->user()->can('routine_tasks.view') || $request->user()->can('work_orders.update_assigned'), 403);

        return response()->json(['data' => GuidedDescriptionProfile::where('is_active', true)->orderBy('name')->get()]);
    }

    public function assetTypes(Request $request)
    {
        abort_unless($request->user()->can('assets.view'), 403);

        return response()->json(['data' => AssetType::where('is_active', true)->orderBy('name')->get()]);
    }

    private function authorizeTask(Request $request, RoutineTaskOccurrence $task, bool $perform = false): void
    {
        abort_unless($request->user()->can($perform ? 'routine_tasks.perform' : 'routine_tasks.view'), 403);
        if (! $request->user()->can('routine_tasks.view_all')) {
            abort_unless(app(RoutineTaskAccessService::class)->assignedTo($task, $request->user()), 403);
        }
    }

    private function authorizeInspection(Request $request, InspectionRun $run, bool $perform = false): void
    {
        abort_unless($request->user()->can($perform ? 'inspections.perform' : 'inspections.view'), 403);
        if (! $request->user()->can('inspections.view_all')) {
            abort_unless(app(RoutineTaskAccessService::class)->assignedToInspection($run, $request->user()), 403);
        }
    }
}
