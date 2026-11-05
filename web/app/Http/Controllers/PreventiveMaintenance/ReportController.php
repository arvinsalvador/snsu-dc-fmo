<?php

namespace App\Http\Controllers\PreventiveMaintenance;

use App\Http\Controllers\Controller;
use App\Models\AssetType;
use App\Models\Building;
use App\Models\FmoPersonnel;
use App\Models\PreventiveMaintenanceOccurrence;
use App\Models\PreventiveMaintenancePlan;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('preventive_maintenance.reports'), 403);
        $history = $this->query($request)->paginate(in_array($request->integer('per_page'), [20, 50, 100], true) ? $request->integer('per_page') : 20)->withQueryString();
        $assetTypes = AssetType::orderBy('name')->get();
        $buildings = Building::where('is_active', true)->orderBy('name')->get();
        $personnel = FmoPersonnel::with('user')->where('personnel_status', 'ACTIVE')->orderBy('personnel_identifier')->get();
        $plans = PreventiveMaintenancePlan::orderBy('name')->get(['id', 'name']);

        return view('preventive-maintenance.history', compact('history', 'assetTypes', 'buildings', 'personnel', 'plans'));
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('preventive_maintenance.reports'), 403);
        $query = $this->query($request);

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Date', 'Asset', 'Asset Type', 'Location', 'PM Plan', 'Personnel', 'Result', 'Status', 'Corrective Work Order']);
            $query->chunkById(200, function ($rows) use ($output): void {
                foreach ($rows as $row) {
                    fputcsv($output, [
                        ($row->completed_at ?: $row->due_at)?->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                        $row->asset->name, $row->asset->assetType->name,
                        collect([$row->asset->building?->name, $row->asset->location?->name])->filter()->join(' • '),
                        $row->plan_name_snapshot, $row->assignees->pluck('user.name')->join('; '),
                        $row->execution?->condition_after, $row->status->value, $row->correctiveWorkOrders->pluck('work_order_number')->join('; '),
                    ]);
                }
            });
            fclose($output);
        }, 'preventive-maintenance-history.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function query(Request $request)
    {
        return PreventiveMaintenanceOccurrence::with(['asset.assetType', 'asset.building', 'asset.location', 'assignees.user', 'execution', 'correctiveWorkOrders'])
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($inner) => $inner->where('plan_name_snapshot', 'like', '%'.$request->string('q').'%')->orWhereHas('asset', fn ($asset) => $asset->where('name', 'like', '%'.$request->string('q').'%')->orWhere('asset_code', 'like', '%'.$request->string('q').'%'))))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->value()))
            ->when($request->filled('plan'), fn ($query) => $query->where('preventive_maintenance_plan_id', $request->string('plan')->value()))
            ->when($request->filled('maintenance_type'), fn ($query) => $query->where('maintenance_type_snapshot', $request->string('maintenance_type')->value()))
            ->when($request->filled('asset_type'), fn ($query) => $query->whereHas('asset', fn ($asset) => $asset->where('asset_type_id', $request->string('asset_type')->value())))
            ->when($request->filled('building'), fn ($query) => $query->whereHas('asset', fn ($asset) => $asset->where('building_id', $request->string('building')->value())))
            ->when($request->filled('personnel'), fn ($query) => $query->whereHas('assignees', fn ($assignees) => $assignees->whereKey($request->string('personnel')->value())))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('due_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('due_at', '<=', $request->date('to')))
            ->latest('due_at');
    }
}
