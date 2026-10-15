<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Http\Controllers\Controller;
use App\Models\InspectableAsset;
use App\Models\InspectionRun;
use App\Services\AssetLifecycleService;
use App\Services\AssetMaintenanceAssignmentService;
use App\Services\AssetQrCodeService;
use App\Services\RoutineTaskAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AssetQrController extends Controller
{
    public function scan(Request $request, string $token, RoutineTaskAccessService $access, AssetMaintenanceAssignmentService $maintenanceAssignments)
    {
        abort_unless($request->user()->can('assets.view'), 403);
        $asset = InspectableAsset::with(['assetType', 'campus', 'building', 'floor', 'location', 'attachments' => fn ($query) => $query->where('purpose', 'ASSET_PHOTO')->latest()])->where('qr_token', $token)->firstOrFail();
        $items = $asset->inspectionItems()->where('status', '!=', 'INSPECTED')
            ->whereHas('run', fn ($runs) => $runs->whereIn('status', ['OPEN', 'IN_PROGRESS']))
            ->with(['run.template', 'run.occurrence', 'activeAssignees.user'])->get()
            ->sortBy(fn ($item) => $item->run->occurrence->due_at);
        $scopeWarning = null;
        if ($request->filled('inspection')) {
            $run = InspectionRun::findOrFail($request->string('inspection')->value());
            $items = $items->where('inspection_run_id', $run->id);
            if ($items->isEmpty()) {
                $scopeWarning = 'This asset is not part of the selected inspection run. Nothing has been recorded.';
            }
        }
        $inspectionOptions = $items->map(fn ($item) => [
            'item' => $item,
            'is_assigned' => $access->assignedToInspectionItem($item, $request->user()),
            'can_inspect' => $access->canPerformInspectionItem($item, $request->user()),
        ])->values();
        $repairAssigned = $request->user()->can('assets.maintain') && $maintenanceAssignments->assignedToRepair($asset, $request->user());
        $repairAction = $maintenanceAssignments->activeRepairAction($asset);

        return view('routine-operations.assets.scan', compact('asset', 'inspectionOptions', 'scopeWarning', 'repairAssigned', 'repairAction'));
    }

    public function print(Request $request, InspectableAsset $asset, AssetQrCodeService $qr)
    {
        abort_unless($request->user()->can('assets.generate_qr'), 403);
        $asset->load(['assetType', 'building', 'location']);
        $qrSvg = $qr->svg($asset);

        return view('routine-operations.assets.qr-print', compact('asset', 'qrSvg'));
    }

    public function regenerate(Request $request, InspectableAsset $asset, AssetLifecycleService $lifecycle)
    {
        abort_unless($request->user()->can('assets.generate_qr'), 403);
        $asset->update(['qr_token' => Str::random(48), 'qr_regenerated_at' => now(), 'qr_regenerated_by' => $request->user()->id]);
        $lifecycle->event($asset, $request->user(), 'QR_REGENERATED', 'Previous QR token invalidated.');

        return back()->with('status', 'Asset QR code regenerated. The previous QR label is now invalid.');
    }
}
