<?php

namespace App\Http\Controllers\PreventiveMaintenance;

use App\Enums\AssetCondition;
use App\Enums\PreventiveMaintenanceStatus;
use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WorkOrders\WorkOrderController;
use App\Models\PreventiveMaintenanceOccurrence;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Services\AssetLifecycleService;
use App\Services\GuidedDescriptionService;
use App\Services\OperationalEvidenceService;
use App\Services\PreventiveMaintenanceAccessService;
use App\Services\PreventiveMaintenanceOccurrenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExecutionController extends Controller
{
    public function start(Request $request, PreventiveMaintenanceOccurrence $occurrence, PreventiveMaintenanceAccessService $access, PreventiveMaintenanceOccurrenceService $scheduler)
    {
        abort_unless($access->canPerform($occurrence, $request->user()), 403);
        $scheduler->refreshStatuses();
        $occurrence->refresh();
        if (! $occurrence->isStartable() && $occurrence->status !== PreventiveMaintenanceStatus::InProgress) {
            throw ValidationException::withMessages(['task' => $occurrence->available_at->isFuture()
                ? 'This maintenance is upcoming and cannot be started until '.$occurrence->available_at->timezone(config('app.timezone'))->format('F j, Y').'.'
                : 'This maintenance task is no longer startable.']);
        }
        DB::transaction(function () use ($occurrence, $request, $access): void {
            $locked = PreventiveMaintenanceOccurrence::lockForUpdate()->findOrFail($occurrence->id);
            if ($locked->status !== PreventiveMaintenanceStatus::InProgress) {
                $personnel = $access->personnel($request->user());
                $locked->execution()->create([
                    'performed_by_personnel_id' => $personnel->id,
                    'performed_by_user_id' => $request->user()->id,
                    'started_at' => now(),
                    'condition_before' => $locked->asset->condition->value,
                ]);
                $locked->update(['status' => PreventiveMaintenanceStatus::InProgress, 'started_at' => now()]);
            }
        });

        return redirect()->route('preventive-maintenance.tasks.show', $occurrence)->with('status', 'Preventive maintenance started.');
    }

    public function complete(Request $request, PreventiveMaintenanceOccurrence $occurrence, PreventiveMaintenanceAccessService $access, OperationalEvidenceService $evidence, AssetLifecycleService $lifecycle, GuidedDescriptionService $guided)
    {
        abort_unless($access->canPerform($occurrence, $request->user()), 403);
        abort_unless($occurrence->status === PreventiveMaintenanceStatus::InProgress, 422);
        $data = $request->validate([
            'checklist' => ['required', 'array'], 'checklist.*' => [Rule::in(['OK', 'NEEDS_ATTENTION', 'NEEDS_REPAIR', 'NOT_APPLICABLE'])],
            'checklist_notes' => ['nullable', 'array'], 'checklist_notes.*' => ['nullable', 'string', 'max:1000'],
            'readings' => ['nullable', 'array'], 'readings.*' => ['nullable', 'string', 'max:120'],
            'actions' => ['nullable', 'array'], 'actions.*' => ['string', 'max:255'],
            'guided_actions' => ['nullable', 'array'], 'guided_actions.*' => [Rule::in(['CLEAN_FILTER', 'CLEAR_DRAIN', 'CHECK_ELECTRICAL', 'INSPECT_COMPONENTS', 'LUBRICATE_PARTS', 'TEST_OPERATION', 'OTHER_ACTION'])],
            'guided_outcome' => ['nullable', 'required_with:guided_actions', Rule::in(['RESOLVED', 'PARTIAL', 'WAITING_SUPPLIES', 'PROBLEM_PERSISTS', 'INVESTIGATION'])],
            'other_action' => ['nullable', 'string', 'max:500'],
            'condition_after' => ['required', Rule::enum(AssetCondition::class)],
            'findings' => ['nullable', 'string', 'max:5000'], 'action_performed' => ['nullable', 'string', 'max:5000'],
            'generated_summary' => ['nullable', 'string', 'max:5000'], 'remarks' => ['nullable', 'string', 'max:5000'], 'additional_remarks' => ['nullable', 'string', 'max:5000'],
            'materials_used' => ['nullable', 'string', 'max:5000'], 'recommended_corrective_action' => ['nullable', 'string', 'max:5000'],
            'replacement_component' => ['nullable', 'array'], 'replacement_component.*' => ['nullable', 'string', 'max:255'],
            'replacement_old_condition' => ['nullable', 'array'], 'replacement_remarks' => ['nullable', 'array'],
            'photos' => ['nullable', 'array', 'max:'.config('work_orders.attachments.max_count')],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')],
            'before_photos' => ['nullable', 'array'], 'before_photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')],
            'during_photos' => ['nullable', 'array'], 'during_photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')],
            'after_photos' => ['nullable', 'array'], 'after_photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')],
            'finding_photos' => ['nullable', 'array'], 'finding_photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')],
        ]);
        $requiredKeys = collect($occurrence->checklist_snapshot)->pluck('key');
        if ($requiredKeys->diff(array_keys($data['checklist']))->isNotEmpty()) {
            throw ValidationException::withMessages(['checklist' => 'Complete every preventive-maintenance checklist item.']);
        }
        if (in_array('OTHER_ACTION', $data['guided_actions'] ?? [], true) && blank($data['other_action'] ?? null)) {
            throw ValidationException::withMessages(['other_action' => 'Describe the other maintenance performed.']);
        }
        if (empty($data['guided_actions']) && blank($data['generated_summary'] ?? null) && blank($data['action_performed'] ?? null)) {
            throw ValidationException::withMessages(['generated_summary' => 'Select the guided maintenance actions or provide a brief work summary.']);
        }
        $photoFields = ['photos', 'before_photos', 'during_photos', 'after_photos', 'finding_photos'];
        $photoCount = collect($photoFields)->sum(fn (string $field): int => count($data[$field] ?? []));
        if ($photoCount > config('work_orders.attachments.max_count')) {
            throw ValidationException::withMessages(['photos' => 'Choose no more than '.config('work_orders.attachments.max_count').' photos across all evidence categories.']);
        }
        if ($occurrence->evidence_required_snapshot && $photoCount === 0 && ! $occurrence->execution?->attachments()->exists()) {
            throw ValidationException::withMessages(['photos' => 'Photo evidence is required for this maintenance task.']);
        }
        DB::transaction(function () use ($occurrence, $request, $data, $evidence, $lifecycle, $guided): void {
            $execution = $occurrence->execution()->lockForUpdate()->firstOrFail();
            $actions = array_values(array_filter($data['guided_actions'] ?? $data['actions'] ?? []));
            $replacements = collect($data['replacement_component'] ?? [])->filter()->values()->map(fn ($component, $index) => [
                'component' => $component, 'old_condition' => $data['replacement_old_condition'][$index] ?? null,
                'replacement_date' => now(config('app.timezone'))->toDateString(), 'remarks' => $data['replacement_remarks'][$index] ?? null,
            ])->all();
            $summary = $data['generated_summary'] ?? null;
            if (! $summary && ! empty($data['guided_actions']) && ! empty($data['guided_outcome'])) {
                $summary = $guided->generate($guided->preventiveMaintenanceProfile(), $data['guided_actions'], $data['guided_outcome'], $data['other_action'] ?? null);
            } elseif (! $summary && $actions) {
                $summary = trim(implode(', ', $actions).'. '.($data['action_performed'] ?? ''));
            }
            $execution->update([
                'completed_at' => now(), 'structured_actions' => $actions, 'condition_after' => $data['condition_after'],
                'findings' => $data['findings'] ?? null, 'action_performed' => $data['action_performed'] ?? null, 'generated_summary' => $summary,
                'remarks' => $data['additional_remarks'] ?? $data['remarks'] ?? null, 'materials_used' => $data['materials_used'] ?? null,
                'recommended_corrective_action' => $data['recommended_corrective_action'] ?? null, 'component_replacements' => $replacements,
            ]);
            foreach ($occurrence->checklist_snapshot as $index => $item) {
                $execution->checklistResults()->updateOrCreate(['item_key' => $item['key']], [
                    'label' => $item['label'], 'result' => $data['checklist'][$item['key']],
                    'notes' => $data['checklist_notes'][$item['key']] ?? null, 'display_order' => $index,
                ]);
            }
            foreach ($occurrence->reading_snapshot ?? [] as $item) {
                $value = $data['readings'][$item['key']] ?? null;
                if ($value !== null && $value !== '') {
                    $execution->readings()->updateOrCreate(['reading_key' => $item['key']], ['label' => $item['label'], 'value' => $value, 'unit' => $item['unit'] ?? null]);
                }
            }
            if (! empty($data['photos'])) {
                $evidence->attach($execution, $data['photos'], $request->user()->id, 'PM_EVIDENCE');
            }
            foreach (['before_photos' => 'PM_BEFORE', 'during_photos' => 'PM_DURING', 'after_photos' => 'PM_AFTER', 'finding_photos' => 'PM_FINDING'] as $field => $purpose) {
                if (! empty($data[$field])) {
                    $evidence->attach($execution, $data[$field], $request->user()->id, $purpose);
                }
            }
            $occurrence->update(['status' => PreventiveMaintenanceStatus::Completed, 'completed_at' => now()]);
            $asset = $occurrence->asset()->lockForUpdate()->firstOrFail();
            if ($asset->condition->value !== $data['condition_after']) {
                $lifecycle->transition($asset, $request->user(), 'PREVENTIVE_MAINTENANCE_CONDITION_UPDATED', null, $data['condition_after'], 'Condition updated from preventive maintenance '.$occurrence->plan_name_snapshot.'.');
            } else {
                $lifecycle->event($asset, $request->user(), 'PREVENTIVE_MAINTENANCE_COMPLETED', $summary ?: ($data['action_performed'] ?? null));
            }
            foreach ($replacements as $replacement) {
                $lifecycle->event($asset, $request->user(), 'COMPONENT_REPLACED', collect([
                    'Replaced '.$replacement['component'].'.',
                    $replacement['old_condition'] ? 'Previous condition: '.$replacement['old_condition'].'.' : null,
                    $replacement['remarks'],
                ])->filter()->join(' '));
            }
        });

        return redirect()->route('preventive-maintenance.tasks.show', $occurrence)->with('status', 'Preventive maintenance completed and added to asset history.');
    }

    public function verify(Request $request, PreventiveMaintenanceOccurrence $occurrence)
    {
        abort_unless($request->user()->can('preventive_maintenance.verify'), 403);
        abort_unless($occurrence->status === PreventiveMaintenanceStatus::Completed && $occurrence->verification_required_snapshot && ! $occurrence->verified_at, 422);
        $data = $request->validate(['verification_note' => ['nullable', 'string', 'max:5000']]);
        $occurrence->update(['verified_at' => now(), 'verified_by' => $request->user()->id, 'verification_note' => $data['verification_note'] ?? null]);

        return back()->with('status', 'Preventive maintenance record verified.');
    }

    public function correctiveWorkOrder(Request $request, PreventiveMaintenanceOccurrence $occurrence, WorkOrderController $numbers)
    {
        abort_unless($request->user()->can('preventive_maintenance.perform') || $request->user()->can('preventive_maintenance.manage_plans'), 403);
        $occurrence->loadMissing(['asset', 'execution']);
        abort_unless($occurrence->execution && ($occurrence->execution->findings || $occurrence->execution->recommended_corrective_action), 422);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'work_order_category_id' => ['nullable', 'exists:work_order_categories,id'],
        ]);
        $workOrder = DB::transaction(function () use ($occurrence, $request, $data, $numbers): WorkOrder {
            $asset = $occurrence->asset;
            $category = isset($data['work_order_category_id'])
                ? WorkOrderCategory::findOrFail($data['work_order_category_id'])
                : WorkOrderCategory::where('name', 'General Repair / Maintenance')->firstOrFail();
            $workOrder = WorkOrder::create([
                'work_order_number' => $numbers->number(), 'requester_id' => $request->user()->id,
                'work_order_category_id' => $category->id, 'campus_id' => $asset->campus_id, 'building_id' => $asset->building_id,
                'floor_id' => $asset->floor_id, 'building_location_id' => $asset->building_location_id,
                'inspectable_asset_id' => $asset->id, 'preventive_maintenance_occurrence_id' => $occurrence->id,
                'subject' => $data['subject'],
                'description' => collect([
                    'Corrective work identified during '.$occurrence->plan_name_snapshot.' for '.$asset->name.' ('.$asset->asset_code.').',
                    $occurrence->execution->findings ? 'Finding: '.$occurrence->execution->findings : null,
                    $occurrence->execution->recommended_corrective_action ? 'Recommended action: '.$occurrence->execution->recommended_corrective_action : null,
                    'Discovered by: '.$request->user()->name.'.',
                ])->filter()->join("\n\n"),
                'urgency' => 'NORMAL', 'status' => WorkOrderStatus::Submitted, 'submitted_at' => now(), 'execution_cycle' => 0,
            ]);
            $workOrder->workflowEvents()->create([
                'actor_id' => $request->user()->id, 'action' => 'PM_CORRECTIVE_CREATED', 'to_status' => WorkOrderStatus::Submitted->value,
                'internal_note' => 'Created from preventive maintenance occurrence '.$occurrence->id.'. PM evidence remains available through the originating record.', 'created_at' => now(),
            ]);

            return $workOrder;
        });

        return redirect()->route('work-orders.show', $workOrder)->with('success', 'Corrective Work Order '.$workOrder->work_order_number.' created through the normal workflow.');
    }
}
