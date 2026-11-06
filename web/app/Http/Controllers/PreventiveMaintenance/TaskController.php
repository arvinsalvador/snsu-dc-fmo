<?php

namespace App\Http\Controllers\PreventiveMaintenance;

use App\Enums\PreventiveMaintenanceStatus;
use App\Http\Controllers\Controller;
use App\Models\PreventiveMaintenanceOccurrence;
use App\Services\GuidedDescriptionService;
use App\Services\PreventiveMaintenanceAccessService;
use App\Services\PreventiveMaintenanceOccurrenceService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index(Request $request, PreventiveMaintenanceAccessService $access, PreventiveMaintenanceOccurrenceService $scheduler)
    {
        abort_unless($request->user()->can('preventive_maintenance.view'), 403);
        $scheduler->refreshStatuses();
        $query = PreventiveMaintenanceOccurrence::with(['asset.assetType', 'asset.building', 'asset.floor', 'asset.location', 'assignees.user', 'execution'])
            ->orderByRaw("CASE status WHEN 'OVERDUE' THEN 0 WHEN 'AVAILABLE' THEN 1 WHEN 'IN_PROGRESS' THEN 2 WHEN 'UPCOMING' THEN 3 ELSE 4 END")
            ->orderBy('due_at');
        $access->scope($query, $request->user());
        $summary = [
            'overdue' => (clone $query)->open()->where(fn ($overdue) => $overdue->where('due_at', '<', now(config('app.timezone')))->orWhere('status', PreventiveMaintenanceStatus::Overdue->value))->count(),
            'available' => (clone $query)->where('status', PreventiveMaintenanceStatus::Available->value)->count(),
            'in_progress' => (clone $query)->where('status', PreventiveMaintenanceStatus::InProgress->value)->count(),
            'upcoming' => (clone $query)->where('status', PreventiveMaintenanceStatus::Upcoming->value)->count(),
        ];
        if ($request->filled('status')) {
            $status = $request->string('status')->value();
            if ($status === PreventiveMaintenanceStatus::Overdue->value) {
                $query->open()->where(fn ($overdue) => $overdue->where('due_at', '<', now(config('app.timezone')))->orWhere('status', PreventiveMaintenanceStatus::Overdue->value));
            } else {
                $query->where('status', $status);
            }
        } elseif (! $request->boolean('history')) {
            $query->where('status', '!=', PreventiveMaintenanceStatus::Completed->value);
        }
        $tasks = $query->paginate(in_array($request->integer('per_page'), [20, 50, 100], true) ? $request->integer('per_page') : 20)->withQueryString();

        return view('preventive-maintenance.tasks.index', compact('tasks', 'summary'));
    }

    public function show(Request $request, PreventiveMaintenanceOccurrence $occurrence, PreventiveMaintenanceAccessService $access, PreventiveMaintenanceOccurrenceService $scheduler, GuidedDescriptionService $guided)
    {
        abort_unless($request->user()->can('preventive_maintenance.view'), 403);
        $scheduler->refreshStatuses();
        $occurrence->refresh();
        abort_unless($request->user()->can('preventive_maintenance.view_all') || $access->assignedTo($occurrence, $request->user()), 403);
        $occurrence->load(['plan', 'asset.assetType', 'asset.building', 'asset.floor', 'asset.location', 'assignees.user', 'execution.checklistResults', 'execution.readings', 'execution.attachments', 'correctiveWorkOrders']);

        $guidedProfile = $guided->preventiveMaintenanceProfile();

        return view('preventive-maintenance.tasks.show', compact('occurrence', 'guidedProfile'));
    }
}
