<?php

namespace App\Http\Controllers\WorkOrders;

use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Models\WorkOrderAssignment;
use App\Services\WorkOrderAssignmentService;
use Illuminate\Http\Request;

class WorkOrderAssignmentController extends Controller
{
    public function queue(Request $request)
    {
        abort_unless($request->user()->can('work_orders.assign') || $request->user()->can('work_orders.assign_direct'), 403);
        $orders = WorkOrder::with('requester', 'category', 'building', 'location', 'preferredPersonnel.user')
            ->where('status', 'APPROVED')->whereDoesntHave('activeAssignments')
            ->when(! $request->user()->can('work_orders.assign'), fn ($query) => $query->where('requester_id', $request->user()->id)->whereHas('workflowEvents', fn ($events) => $events->where('action', 'DIRECT_AUTHORIZATION')->where('actor_id', $request->user()->id)))
            ->when($request->filled('search'), fn ($query) => $query->where('work_order_number', 'like', '%'.$request->string('search')->trim()->value().'%'))
            ->latest('submitted_at')->paginate(20)->withQueryString();

        return view('work-orders.assignment-queue', compact('orders'));
    }

    public function mine(Request $request)
    {
        abort_unless($request->user()->can('work_orders.view_assigned'), 403);
        $orders = WorkOrder::with('category', 'building', 'location', 'activeAssignments.personnel.user')
            ->whereHas('activeAssignments.personnel', fn ($query) => $query->where('user_id', $request->user()->id))
            ->latest('submitted_at')->paginate(20);

        return view('work-orders.my-tasks', compact('orders'));
    }

    public function store(Request $request, WorkOrder $workOrder, WorkOrderAssignmentService $assignments)
    {
        abort_unless($assignments->mayManage($workOrder, $request->user(), $workOrder->status !== WorkOrderStatus::Approved), 403);
        $data = $request->validate(['personnel_ids' => ['required', 'array', 'min:1', 'max:10'], 'personnel_ids.*' => ['required', 'ulid', 'distinct'], 'note' => ['nullable', 'string', 'max:2000']]);
        $assignments->add($workOrder, $request->user(), $data['personnel_ids'], $data['note'] ?? null);

        return back()->with('success', 'FMO personnel assigned.');
    }

    public function destroy(Request $request, WorkOrder $workOrder, WorkOrderAssignment $assignment, WorkOrderAssignmentService $assignments)
    {
        abort_unless($request->user()->can('work_orders.remove_assignee') || $assignments->mayManage($workOrder, $request->user(), true), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $assignments->remove($workOrder, $assignment, $request->user(), $data['reason']);

        return back()->with('success', 'FMO personnel assignment removed.');
    }
}
