<?php

namespace App\Http\Controllers;

use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\BuildingLocation;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Services\ReportingService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function history(Request $request, ReportingService $reports)
    {
        $filters = $reports->filters($request);
        $query = $reports->query($request->user(), $filters);
        $total = (clone $query)->count();
        $completed = (clone $query)->where('status', WorkOrderStatus::Completed->value)->count();
        $orders = $query->with('requester', 'category', 'campus', 'building', 'location', 'assignments.personnel.user', 'activeAssignments.personnel.user')
            ->latest('submitted_at')->paginate(20)->withQueryString();
        $management = $reports->canManage($request->user());

        return view('reports.history', [
            'orders' => $orders, 'filters' => $filters, 'total' => $total, 'completed' => $completed,
            'management' => $management,
            'statuses' => WorkOrderStatus::cases(),
            'categories' => WorkOrderCategory::orderBy('name')->get(['id', 'name']),
            'campuses' => Campus::orderBy('name')->get(['id', 'name']),
            'buildings' => Building::orderBy('name')->get(['id', 'name', 'campus_id']),
            'locations' => BuildingLocation::orderBy('name')->get(['id', 'name', 'building_id']),
            'personnel' => $management ? FmoPersonnel::with('user')->orderBy('personnel_identifier')->get() : collect(),
        ]);
    }

    public function export(Request $request, ReportingService $reports): StreamedResponse
    {
        $filters = $reports->filters($request);
        if ($reports->canManage($request->user())) {
            abort_unless($request->user()->can('reports.export_work_orders'), 403);
        }
        $query = $reports->query($request->user(), $filters);

        $management = $reports->canManage($request->user());

        return response()->streamDownload(function () use ($query, $management): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Work Order No.', 'Status', 'Requester', 'Category', 'Campus', 'Building', 'Location',
                'Assigned Personnel', 'Submitted (Asia/Manila)', 'Completed (Asia/Manila)', 'Completion Hours']);
            $query->with('requester', 'category', 'campus', 'building', 'location', 'assignments.personnel.user', 'activeAssignments.personnel.user')
                ->orderBy('id')->chunkById(200, function ($orders) use ($output, $management): void {
                    foreach ($orders as $order) {
                        fputcsv($output, [
                            $order->work_order_number, $order->status->value, $this->csvText($order->requester->name),
                            $this->csvText($order->category->name), $this->csvText($order->campus->name), $this->csvText($order->building->name),
                            $this->csvText($order->location?->name ?? ''),
                            $this->csvText(($management ? $order->assignments : $order->activeAssignments)->pluck('personnel.user.name')->filter()->unique()->join('; ')),
                            $order->submitted_at?->timezone('Asia/Manila')->format('Y-m-d H:i:s'),
                            $order->verified_at?->timezone('Asia/Manila')->format('Y-m-d H:i:s'),
                            $order->verified_at ? round($order->submitted_at->diffInMinutes($order->verified_at) / 60, 2) : '',
                        ]);
                    }
                });
            fclose($output);
        }, 'work-order-history.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function print(Request $request, WorkOrder $workOrder, ReportingService $reports)
    {
        abort_unless($reports->scope($request->user())->whereKey($workOrder->id)->exists(), 403);
        $management = $reports->canManage($request->user());
        $order = $workOrder->load('requester', 'category', 'campus', 'building', 'floor', 'location',
            'preferredPersonnel.user', 'decisionMaker', 'completionSubmitter', 'verifier', 'assignments.personnel.user', 'activeAssignments.personnel.user',
            'assessments.personnel.user', 'sessions.personnel.user', 'workflowEvents.actor', 'attachments');

        return view('reports.print', compact('order', 'management'));
    }

    private function csvText(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
