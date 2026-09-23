<?php

namespace App\Http\Controllers\WorkOrders;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Services\WorkOrderWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkOrderWorkflowController extends Controller
{
    public function queue(Request $request, string $queue)
    {
        abort_unless(in_array($queue, ['screening', 'approval'], true), 404);
        abort_unless($request->user()->can($queue === 'screening' ? 'work_orders.screen' : 'work_orders.approve'), 403);
        $statuses = $queue === 'screening' ? ['SUBMITTED', 'FOR_SCREENING'] : ['FOR_APPROVAL'];
        $orders = WorkOrder::with('requester', 'category', 'campus', 'building', 'location')
            ->whereIn('status', $statuses)
            ->when($request->filled('category_id'), fn ($q) => $q->where('work_order_category_id', $request->integer('category_id')))
            ->when($request->filled('building_id'), fn ($q) => $q->where('building_id', $request->integer('building_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('work_order_number', 'like', '%'.$request->string('search')->trim()->value().'%'))
            ->latest('submitted_at')->paginate(20)->withQueryString();

        return view('work-orders.queue', compact('orders', 'queue'));
    }

    public function action(Request $request, WorkOrder $workOrder, string $action, WorkOrderWorkflowService $workflow, WorkOrderController $requests)
    {
        $this->perform($request, $workOrder, $action, $workflow, $requests);

        return redirect()->route('work-orders.show', $workOrder)->with('success', 'Work Order workflow updated.');
    }

    public function perform(Request $request, WorkOrder $workOrder, string $action, WorkOrderWorkflowService $workflow, WorkOrderController $requests): WorkOrder
    {
        abort_unless(in_array($action, ['begin-screening', 'request-information', 'recommend-approval', 'recommend-disapproval', 'approve', 'disapprove', 'return-to-screening', 'resubmit'], true), 404);
        $workflow->authorize($workOrder, $request->user(), $action);
        $rules = ['internal_note' => ['nullable', 'string', 'max:5000'], 'requester_message' => ['nullable', 'string', 'max:5000']];
        if (in_array($action, ['request-information', 'disapprove'], true)) {
            $rules['requester_message'][0] = 'required';
        }
        if ($action === 'return-to-screening') {
            $rules['internal_note'][0] = 'required';
        }
        if ($action === 'recommend-disapproval') {
            $rules['internal_note'][0] = 'required';
        }
        $notes = $request->validate($rules);
        if ($action === 'resubmit') {
            $notes['internal_note'] = null;
        }

        return DB::transaction(function () use ($request, $workOrder, $action, $workflow, $requests, $notes): WorkOrder {
            if ($action === 'resubmit') {
                abort_unless($workOrder->requester_id === $request->user()->id && $request->user()->can('work_orders.resubmit_own'), 403);
                $data = $requests->validated($request);
            }
            $updated = $workflow->transition($workOrder, $request->user(), $action, $notes['internal_note'] ?? null, $notes['requester_message'] ?? null);
            if ($action === 'resubmit') {
                $updated->update($data);
                $requests->uploads($request, $updated);
            }

            return $updated;
        });
    }
}
