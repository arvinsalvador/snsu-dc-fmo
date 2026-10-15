<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Http\Controllers\Controller;
use App\Models\InspectableAsset;
use App\Models\InspectionRun;
use App\Services\AssetLifecycleService;
use App\Services\AssetQrCodeService;
use App\Services\RoutineTaskAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AssetQrController extends Controller
{
    public function scan(Request $request, string $token, RoutineTaskAccessService $access)
    {
        abort_unless($request->user()->can('assets.view'), 403);
        $asset = InspectableAsset::with(['assetType', 'campus', 'building', 'floor', 'location'])->where('qr_token', $token)->firstOrFail();
        $run = $request->filled('inspection') ? InspectionRun::findOrFail($request->string('inspection')->value()) : null;
        if (! $run && $request->user()->can('inspections.view')) {
            $runs = InspectionRun::whereIn('status', ['OPEN', 'IN_PROGRESS'])->whereHas('items', fn ($items) => $items->where('inspectable_asset_id', $asset->id));
            $access->scopeInspectionRuns($runs, $request->user());
            $run = $runs->latest()->first();
        }
        $inspectionItem = null;
        $scopeWarning = null;
        if ($run) {
            abort_unless($request->user()->can('inspections.view_all') || $access->assignedToInspection($run, $request->user()), 403);
            $inspectionItem = $run->items()->where('inspectable_asset_id', $asset->id)->first();
            if (! $inspectionItem) {
                $scopeWarning = 'This asset is not part of the selected inspection run. Nothing has been recorded.';
            }
        }

        return view('routine-operations.assets.scan', compact('asset', 'run', 'inspectionItem', 'scopeWarning'));
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
