<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Http\Controllers\Controller;
use App\Models\AssetActionRequest;
use App\Models\FmoPersonnel;
use App\Models\InspectableAsset;
use App\Services\AssetLifecycleService;
use App\Services\AssetMaintenanceAssignmentService;
use App\Services\OperationalEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AssetActionController extends Controller
{
    public function request(Request $request, InspectableAsset $asset, AssetLifecycleService $lifecycle, OperationalEvidenceService $evidence)
    {
        $data = $request->validate(['type' => ['required', Rule::in(['REPAIR', 'DISPOSAL'])], 'problem_description' => ['required', 'string', 'max:5000'],
            'evidence' => ['nullable', 'array', 'max:'.config('work_orders.attachments.max_count')], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')]]);
        abort_unless($request->user()->can($data['type'] === 'REPAIR' ? 'assets.request_repair' : 'assets.request_disposal'), 403);
        abort_if(in_array($asset->lifecycle_status->value, ['DISPOSED', 'RETIRED'], true), 422);
        $action = $lifecycle->request($asset, $request->user(), $data['type'], $data['problem_description']);
        if (! empty($data['evidence'])) {
            $evidence->attach($action, $data['evidence'], $request->user()->id, 'ASSET_ACTION_EVIDENCE');
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $action->fresh(['attachments'])], 201);
        }

        return back()->with('status', $data['type'] === 'REPAIR' ? 'Repair review submitted.' : 'Disposal review submitted.');
    }

    public function review(Request $request, AssetActionRequest $assetAction, AssetLifecycleService $lifecycle)
    {
        $assetAction->load('asset', 'requester');
        abort_unless($request->user()->can($assetAction->type === 'REPAIR' ? 'assets.review_repair' : 'assets.approve_disposal'), 403);
        abort_unless($assetAction->status === 'PENDING', 422);
        $allowed = $assetAction->type === 'REPAIR'
            ? ['APPROVE_REPAIR', 'NOT_REPAIRABLE', 'RETURN', 'REJECT']
            : ['APPROVE_DISPOSAL', 'SEND_FOR_REPAIR', 'RETURN', 'REJECT'];
        $data = $request->validate(['decision' => ['required', Rule::in($allowed)], 'reason' => ['nullable', 'required_if:decision,NOT_REPAIRABLE,RETURN,REJECT', 'string', 'max:5000']]);
        DB::transaction(function () use ($assetAction, $data, $request, $lifecycle): void {
            $assetAction->update(['status' => in_array($data['decision'], ['RETURN'], true) ? 'RETURNED' : 'REVIEWED', 'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(), 'decision' => $data['decision'], 'decision_reason' => $data['reason'] ?? null]);
            match ($data['decision']) {
                'APPROVE_REPAIR', 'SEND_FOR_REPAIR' => $lifecycle->transition($assetAction->asset, $request->user(), 'REPAIR_APPROVED', 'UNDER_REPAIR', 'NEEDS_REPAIR', $data['reason'] ?? null, $assetAction),
                'NOT_REPAIRABLE' => $lifecycle->transition($assetAction->asset, $request->user(), 'NOT_REPAIRABLE', 'FOR_DISPOSAL_REVIEW', 'UNUSABLE', $data['reason'], $assetAction),
                'APPROVE_DISPOSAL' => $lifecycle->transition($assetAction->asset, $request->user(), 'DISPOSAL_APPROVED', 'APPROVED_FOR_DISPOSAL', null, $data['reason'] ?? null, $assetAction),
                'REJECT' => $lifecycle->transition($assetAction->asset, $request->user(), 'REQUEST_REJECTED', 'ACTIVE', null, $data['reason'], $assetAction),
                default => $lifecycle->event($assetAction->asset, $request->user(), 'REQUEST_RETURNED', $data['reason'], $assetAction),
            };
        });
        if ($data['decision'] === 'NOT_REPAIRABLE') {
            $lifecycle->request($assetAction->asset->fresh(), $request->user(), 'DISPOSAL', $data['reason'], $assetAction->inspection);
        }
        $event = match ($data['decision']) {
            'APPROVE_REPAIR', 'SEND_FOR_REPAIR' => 'ASSET_REPAIR_APPROVED', 'APPROVE_DISPOSAL' => 'ASSET_DISPOSAL_APPROVED', 'RETURN' => 'ASSET_REQUEST_RETURNED', default => 'ASSET_REQUEST_UPDATED'
        };
        $lifecycle->notifyRequester($assetAction->fresh(), $event);

        if ($request->expectsJson()) {
            return response()->json(['data' => $assetAction->fresh(), 'asset' => $assetAction->asset->fresh()]);
        }

        return back()->with('status', 'Asset request decision recorded.');
    }

    public function maintain(Request $request, InspectableAsset $asset, AssetLifecycleService $lifecycle, OperationalEvidenceService $evidence)
    {
        abort_unless($request->user()->can('assets.maintain'), 403);
        abort_unless(app(AssetMaintenanceAssignmentService::class)->assignedToRepair($asset, $request->user()), 403);
        abort_unless($asset->lifecycle_status->value === 'UNDER_REPAIR', 422, 'This asset is not currently approved for repair.');
        $data = $request->validate(['work_performed' => ['required', 'string', 'max:5000'], 'parts_materials' => ['nullable', 'string', 'max:5000'],
            'result' => ['required', Rule::in(['REPAIRED_SERVICEABLE', 'PARTIALLY_REPAIRED', 'NOT_REPAIRABLE'])], 'remarks' => ['nullable', 'string', 'max:5000'],
            'evidence' => ['required', 'array', 'min:1', 'max:'.config('work_orders.attachments.max_count')], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')]]);
        $action = $asset->actionRequests()->whereIn('decision', ['APPROVE_REPAIR', 'SEND_FOR_REPAIR'])->latest('reviewed_at')->first();
        $record = null;
        DB::transaction(function () use ($asset, $action, $data, $request, $lifecycle, $evidence, &$record): void {
            $record = $asset->maintenanceRecords()->create(['asset_action_request_id' => $action?->id, 'recorded_by' => $request->user()->id, 'recorded_at' => now()] + $data);
            $evidence->attach($record, $data['evidence'], $request->user()->id, 'REPAIR_EVIDENCE');
            match ($data['result']) {
                'REPAIRED_SERVICEABLE' => $lifecycle->transition($asset, $request->user(), 'REPAIR_COMPLETED', 'ACTIVE', 'GOOD', $data['work_performed'], $action),
                'PARTIALLY_REPAIRED' => $lifecycle->transition($asset, $request->user(), 'REPAIR_PARTIAL', 'UNDER_REPAIR', 'NEEDS_REPAIR', $data['work_performed'], $action),
                default => $lifecycle->transition($asset, $request->user(), 'REPAIR_FAILED', 'FOR_DISPOSAL_REVIEW', 'UNUSABLE', $data['remarks'] ?? $data['work_performed'], $action),
            };
        });
        if ($data['result'] === 'NOT_REPAIRABLE') {
            $lifecycle->request($asset->fresh(), $request->user(), 'DISPOSAL', $data['remarks'] ?? $data['work_performed']);
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $record->fresh(['attachments']), 'asset' => $asset->fresh()], 201);
        }

        return back()->with('status', 'Repair update recorded.');
    }

    public function assignMaintenance(Request $request, AssetActionRequest $assetAction, AssetMaintenanceAssignmentService $assignments)
    {
        abort_unless($request->user()->can('assets.assign_maintenance'), 403);
        abort_unless(in_array($assetAction->decision, ['APPROVE_REPAIR', 'SEND_FOR_REPAIR'], true), 422);
        $data = $request->validate([
            'assignee_ids' => ['required', 'array', 'min:1'],
            'assignee_ids.*' => ['distinct', Rule::exists('fmo_personnel', 'id')->where('personnel_status', 'ACTIVE')->whereNull('archived_at')],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $personnel = FmoPersonnel::with('user')->whereIn('id', $data['assignee_ids'])->get();
        abort_unless($personnel->count() === count(array_unique($data['assignee_ids'])) && $personnel->every(fn (FmoPersonnel $person) => $person->isAssignable()), 422, 'Every repair assignee must be active and available.');
        $assignments->replaceTeam($assetAction, $data['assignee_ids'], $request->user(), $data['reason'] ?? null);

        return back()->with('status', 'Repair assignment updated.');
    }

    public function dispose(Request $request, InspectableAsset $asset, AssetLifecycleService $lifecycle, OperationalEvidenceService $evidence)
    {
        abort_unless($request->user()->can('assets.mark_disposed'), 403);
        abort_unless($asset->lifecycle_status->value === 'APPROVED_FOR_DISPOSAL', 422);
        $data = $request->validate(['disposed_at' => ['required', 'date', 'before_or_equal:today'], 'remarks' => ['required', 'string', 'max:5000'],
            'evidence' => ['nullable', 'array', 'max:'.config('work_orders.attachments.max_count')], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')]]);
        $lifecycle->transition($asset, $request->user(), 'ASSET_DISPOSED', 'DISPOSED', 'UNUSABLE', 'Disposed '.$data['disposed_at'].': '.$data['remarks']);
        if (! empty($data['evidence'])) {
            $evidence->attach($asset, $data['evidence'], $request->user()->id, 'DISPOSAL_EVIDENCE');
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $asset->fresh()]);
        }

        return back()->with('status', 'Asset marked as physically disposed.');
    }
}
