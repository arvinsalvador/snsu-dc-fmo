<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PreventiveMaintenance\ExecutionController;
use App\Models\PreventiveMaintenanceOccurrence;
use App\Models\PreventiveMaintenancePlan;
use App\Services\AssetLifecycleService;
use App\Services\GuidedDescriptionService;
use App\Services\OperationalEvidenceService;
use App\Services\PreventiveMaintenanceAccessService;
use App\Services\PreventiveMaintenanceOccurrenceService;
use Illuminate\Http\Request;

class PreventiveMaintenanceController extends Controller
{
    public function plans(Request $request)
    {
        abort_unless($request->user()->can('preventive_maintenance.view_all'), 403);

        return response()->json(['data' => PreventiveMaintenancePlan::with(['asset.assetType', 'activeAssignees.user'])->latest()->paginate(20)]);
    }

    public function tasks(Request $request, PreventiveMaintenanceAccessService $access, PreventiveMaintenanceOccurrenceService $scheduler)
    {
        abort_unless($request->user()->can('preventive_maintenance.view'), 403);
        $scheduler->refreshStatuses();
        $query = PreventiveMaintenanceOccurrence::with(['asset.assetType', 'asset.building', 'asset.location', 'assignees.user', 'execution'])->orderBy('due_at');
        $access->scope($query, $request->user());

        return response()->json(['data' => $query->paginate(20)]);
    }

    public function task(Request $request, PreventiveMaintenanceOccurrence $occurrence, PreventiveMaintenanceAccessService $access, PreventiveMaintenanceOccurrenceService $scheduler)
    {
        $scheduler->refreshStatuses();
        $occurrence->refresh();
        abort_unless($request->user()->can('preventive_maintenance.view_all') || $access->assignedTo($occurrence, $request->user()), 403);

        return response()->json(['data' => $occurrence->load(['asset.assetType', 'asset.building', 'asset.location', 'assignees.user', 'execution.checklistResults', 'execution.readings', 'correctiveWorkOrders'])]);
    }

    public function start(Request $request, PreventiveMaintenanceOccurrence $occurrence, PreventiveMaintenanceAccessService $access, PreventiveMaintenanceOccurrenceService $scheduler, ExecutionController $controller)
    {
        $controller->start($request, $occurrence, $access, $scheduler);

        return response()->json(['data' => $occurrence->fresh(['execution', 'assignees.user'])]);
    }

    public function complete(Request $request, PreventiveMaintenanceOccurrence $occurrence, PreventiveMaintenanceAccessService $access, OperationalEvidenceService $evidence, AssetLifecycleService $lifecycle, GuidedDescriptionService $guided, ExecutionController $controller)
    {
        $controller->complete($request, $occurrence, $access, $evidence, $lifecycle, $guided);

        return response()->json(['data' => $occurrence->fresh(['execution.checklistResults', 'execution.readings'])]);
    }
}
