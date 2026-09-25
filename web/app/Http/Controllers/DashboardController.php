<?php

namespace App\Http\Controllers;

use App\Enums\WorkOrderStatus;
use App\Models\FmoPersonnel;
use App\Models\WorkOrder;
use App\Models\WorkOrderWorkflowEvent;
use App\Models\WorkSession;
use App\Models\WorkUpdate;
use App\Services\ReportingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, ReportingService $reports)
    {
        $range = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = $range['from'] ?? now('Asia/Manila')->startOfMonth()->toDateString();
        $to = $range['to'] ?? now('Asia/Manila')->toDateString();
        abort_if(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > config('reporting.maximum_range_days'), 422);
        $user = $request->user();
        $management = $user->can('dashboard.view_management') || $user->can('dashboard.view_oversight');
        $counts = $reports->dashboardScope($user)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $submitted = $reports->dashboardScope($user);
        $reports->date($submitted, 'submitted_at', $from, $to);
        $completed = $reports->dashboardScope($user)->where('status', WorkOrderStatus::Completed->value);
        $reports->date($completed, 'verified_at', $from, $to);
        $periodSubmitted = $submitted->count();
        $periodCompleted = $completed->count();
        $averageHours = $completed->selectRaw('AVG(TIMESTAMPDIFF(SECOND, submitted_at, verified_at)) / 3600 AS hours')->first()?->hours;

        $attention = collect();
        $activeSessions = collect();
        $recentCompleted = collect();
        $personnelWorkload = collect();
        if ($management) {
            $attention = WorkOrder::with('building', 'category')->select('work_orders.*')
                ->addSelect(['state_entered_at' => WorkOrderWorkflowEvent::select('created_at')
                    ->whereColumn('work_order_id', 'work_orders.id')
                    ->whereColumn('to_status', 'work_orders.status')
                    ->whereRaw('(from_status <> to_status OR from_status IS NULL)')
                    ->latest('created_at')->latest('id')->limit(1)])
                ->addSelect(['material_note' => WorkUpdate::select('material_description')
                    ->whereColumn('work_order_id', 'work_orders.id')->whereNotNull('material_description')
                    ->latest('recorded_at')->limit(1)])
                ->addSelect(['remaining_note' => WorkUpdate::select('remaining_work')
                    ->whereColumn('work_order_id', 'work_orders.id')->whereNotNull('remaining_work')
                    ->latest('recorded_at')->limit(1)])
                ->whereIn('status', [WorkOrderStatus::Submitted->value, WorkOrderStatus::Approved->value,
                    WorkOrderStatus::AssessmentReview->value, WorkOrderStatus::ForVerification->value,
                    WorkOrderStatus::WaitingForMaterials->value, WorkOrderStatus::ForContinuation->value])
                ->oldest('updated_at')->limit(12)->get();
            $activeSessions = WorkSession::with('workOrder.building', 'personnel.user')
                ->whereNull('ended_at')->oldest('started_at')->limit(10)->get();
            $recentCompleted = WorkOrder::with('building')->where('status', WorkOrderStatus::Completed->value)
                ->latest('verified_at')->limit(8)->get();
            $personnelWorkload = FmoPersonnel::with('user')->where('personnel_status', 'ACTIVE')->whereNull('archived_at')
                ->withCount(['workOrderAssignments as open_workload' => fn ($query) => $query->whereNull('unassigned_at')
                    ->whereHas('workOrder', fn ($orders) => $orders->whereNotIn('status', [
                        WorkOrderStatus::Completed->value, WorkOrderStatus::Cancelled->value, WorkOrderStatus::Disapproved->value,
                    ]))])
                ->orderBy('personnel_identifier')->limit(30)->get();
        }

        return view('dashboard', compact('counts', 'periodSubmitted', 'periodCompleted', 'averageHours',
            'attention', 'activeSessions', 'recentCompleted', 'personnelWorkload', 'management', 'from', 'to'));
    }
}
