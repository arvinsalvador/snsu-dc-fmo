<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Http\Controllers\Controller;
use App\Models\InspectableAsset;
use App\Models\InspectionItem;
use App\Services\AssetLifecycleService;
use App\Services\OperationalEvidenceService;
use App\Services\RoutineTaskAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssetInspectionController extends Controller
{
    public function create(Request $request, InspectableAsset $asset, RoutineTaskAccessService $access)
    {
        abort_unless($request->user()->can('assets.inspect'), 403);
        abort_if(in_array($asset->lifecycle_status->value, ['DISPOSED', 'RETIRED'], true), 422, 'Disposed or retired assets are read-only.');
        $asset->load(['assetType', 'building', 'floor', 'location']);
        abort_unless($request->filled('item'), 403, 'Choose an assigned inspection before inspecting this asset.');
        $item = InspectionItem::with('run')->whereKey($request->string('item'))->where('inspectable_asset_id', $asset->id)->firstOrFail();
        abort_unless($access->canPerformInspectionItem($item, $request->user()), 403);

        return view('routine-operations.assets.inspect', compact('asset', 'item'));
    }

    public function store(Request $request, InspectableAsset $asset, OperationalEvidenceService $evidence, AssetLifecycleService $lifecycle, RoutineTaskAccessService $access)
    {
        abort_unless($request->user()->can('assets.inspect'), 403);
        abort_if(in_array($asset->lifecycle_status->value, ['DISPOSED', 'RETIRED'], true), 422, 'Disposed or retired assets are read-only.');
        $asset->load('assetType');
        $data = $request->validate([
            'inspection_item_id' => ['required', 'exists:inspection_items,id'],
            'condition' => ['required', Rule::in(['PRISTINE', 'GOOD', 'FAIR', 'NEEDS_REPAIR', 'DAMAGED', 'BROKEN', 'UNUSABLE'])],
            'checklist' => ['required', 'array'], 'checklist.*' => ['required', Rule::in(['YES', 'NO', 'NOT_APPLICABLE'])],
            'summary' => ['required', 'string', 'max:5000'], 'remarks' => ['nullable', 'string', 'max:5000'],
            'recommended_action' => ['required', Rule::in(['NONE', 'REPAIR_REVIEW', 'DISPOSAL_REVIEW'])],
            'evidence' => ['nullable', 'array', 'max:'.config('work_orders.attachments.max_count')], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')],
            'location_evidence' => ['nullable', 'array', 'max:'.config('work_orders.attachments.max_count')], 'location_evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')],
        ]);
        $item = InspectionItem::with('run')->whereKey($data['inspection_item_id'])->where('inspectable_asset_id', $asset->id)->firstOrFail();
        abort_unless($access->canPerformInspectionItem($item, $request->user()), 403);
        $expected = collect($item->checklist_snapshot ?: $asset->assetType->checklist)->pluck('code');
        if ($expected->diff(array_keys($data['checklist']))->isNotEmpty()) {
            throw ValidationException::withMessages(['checklist' => 'Complete every checklist item.']);
        }
        if ((in_array($data['condition'], ['NEEDS_REPAIR', 'DAMAGED', 'BROKEN', 'UNUSABLE'], true) || $data['recommended_action'] !== 'NONE') && empty($data['evidence'])) {
            throw ValidationException::withMessages(['evidence' => 'A current asset photo is required for this condition or recommendation.']);
        }
        if ($data['recommended_action'] !== 'NONE') {
            $permission = $data['recommended_action'] === 'REPAIR_REVIEW' ? 'assets.request_repair' : 'assets.request_disposal';
            abort_unless($request->user()->can($permission), 403);
        }
        abort_if($item->status === 'INSPECTED', 422, 'This inspection item is already complete.');
        DB::transaction(function () use (&$item, $asset, $data, $request, $evidence, $lifecycle): void {
            $item = InspectionItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_if($item->status === 'INSPECTED', 422, 'This inspection item is already complete.');
            $attributes = ['status' => 'INSPECTED', 'inspector_personnel_id' => $request->user()->personnelProfile?->id,
                'inspector_user_id' => $request->user()->id, 'inspected_at' => now(), 'result' => $data['condition'],
                'condition' => $data['condition'], 'recommended_action' => $data['recommended_action'], 'checklist_results' => $data['checklist'],
                'generated_summary' => $data['summary'], 'remarks' => $data['remarks'] ?? null];
            $item->update($attributes);
            if (! empty($data['evidence'])) {
                $evidence->attach($item, $data['evidence'], $request->user()->id, 'INSPECTION_EVIDENCE');
            }
            if (! empty($data['location_evidence'])) {
                $evidence->attach($item, $data['location_evidence'], $request->user()->id, 'INSPECTION_LOCATION');
            }
            $asset->update(['condition' => $data['condition'], 'last_inspected_at' => now()]);
            $lifecycle->event($asset, $request->user(), 'ASSET_INSPECTED', $data['summary']);
            if ($item->run) {
                $this->updateRunProgress($item);
            }
        });
        if ($data['recommended_action'] !== 'NONE') {
            $type = $data['recommended_action'] === 'REPAIR_REVIEW' ? 'REPAIR' : 'DISPOSAL';
            $lifecycle->request($asset->fresh(), $request->user(), $type, $data['summary'], $item);
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $item->fresh(['attachments']), 'asset' => $asset->fresh()], 201);
        }

        return redirect()->route('assets.show', $asset)->with('status', 'Asset inspection recorded.');
    }

    private function updateRunProgress(InspectionItem $item): void
    {
        $run = $item->run;
        if ($run->items()->where('status', '!=', 'INSPECTED')->doesntExist()) {
            $run->update(['status' => 'COMPLETED', 'started_at' => $run->started_at ?? now(), 'completed_at' => now()]);
            $run->occurrence->update(['status' => $run->occurrence->due_at->isPast() ? 'COMPLETED_LATE' : 'COMPLETED', 'started_at' => $run->occurrence->started_at ?? now(),
                'completed_at' => now(), 'performed_by_personnel_id' => $item->inspector_personnel_id, 'performed_by_user_id' => $item->inspector_user_id,
                'generated_summary' => 'Completed item-by-item inspection of '.$run->items()->count().' assets.']);
        } else {
            $run->update(['status' => 'IN_PROGRESS', 'started_at' => $run->started_at ?? now()]);
            $run->occurrence->update(['status' => 'IN_PROGRESS', 'started_at' => $run->occurrence->started_at ?? now()]);
        }
    }
}
