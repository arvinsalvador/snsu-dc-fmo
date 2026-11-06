<?php

namespace App\Http\Controllers\PreventiveMaintenance;

use App\Enums\PreventiveMaintenanceStatus;
use App\Http\Controllers\Controller;
use App\Models\AssetType;
use App\Models\Building;
use App\Models\FmoPersonnel;
use App\Models\PreventiveMaintenanceOccurrence;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()->can('preventive_maintenance.view_all'), 403);
        $base = PreventiveMaintenanceOccurrence::query()
            ->when($request->filled('asset_class'), fn ($query) => $query->whereHas('asset', fn ($asset) => $asset->where('asset_class', $request->string('asset_class')->value())))
            ->when($request->filled('asset_type'), fn ($query) => $query->whereHas('asset', fn ($asset) => $asset->where('asset_type_id', $request->string('asset_type')->value())))
            ->when($request->filled('building'), fn ($query) => $query->whereHas('asset', fn ($asset) => $asset->where('building_id', $request->string('building')->value())))
            ->when($request->filled('personnel'), fn ($query) => $query->whereHas('assignees', fn ($assignees) => $assignees->whereKey($request->string('personnel')->value())))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('due_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('due_at', '<=', $request->date('to')));
        $now = now(config('app.timezone'));
        $due = (clone $base)->whereDate('due_at', $now->toDateString())->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->count();
        $week = (clone $base)->whereBetween('due_at', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()])->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->count();
        $overdue = (clone $base)->where(fn ($late) => $late->where('due_at', '<', $now)->orWhere('status', PreventiveMaintenanceStatus::Overdue->value))->whereNotIn('status', [PreventiveMaintenanceStatus::Completed->value, PreventiveMaintenanceStatus::Cancelled->value])->count();
        $inProgress = (clone $base)->where('status', PreventiveMaintenanceStatus::InProgress->value)->count();
        $completedMonth = (clone $base)->where('status', PreventiveMaintenanceStatus::Completed->value)->whereBetween('completed_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])->count();
        $duePeriods = (clone $base)->where('due_at', '<=', $now)->where('status', '!=', PreventiveMaintenanceStatus::Cancelled->value)->count();
        $onTime = (clone $base)->where('due_at', '<=', $now)->where('status', PreventiveMaintenanceStatus::Completed->value)->whereColumn('completed_at', '<=', 'due_at')->count();
        $compliance = $duePeriods ? round($onTime / $duePeriods * 100, 1) : null;
        $upcoming = (clone $base)->with(['asset.assetType', 'asset.building', 'assignees.user'])->whereBetween('due_at', [$now, $now->copy()->addDays(30)])->orderBy('due_at')->limit(20)->get();
        $recurringDefects = (clone $base)->with('asset')->whereHas('execution', fn ($execution) => $execution->whereNotNull('findings'))
            ->selectRaw('inspectable_asset_id, count(*) as defects')->groupBy('inspectable_asset_id')->having('defects', '>=', 2)->orderByDesc('defects')->limit(10)->get();

        $assetTypes = AssetType::where('is_active', true)->orderBy('name')->get();
        $buildings = Building::where('is_active', true)->orderBy('name')->get();
        $personnel = FmoPersonnel::with('user')->where('personnel_status', 'ACTIVE')->whereNull('archived_at')->orderBy('personnel_identifier')->get();

        return view('preventive-maintenance.dashboard', compact('due', 'week', 'overdue', 'inProgress', 'completedMonth', 'compliance', 'upcoming', 'recurringDefects', 'assetTypes', 'buildings', 'personnel'));
    }
}
