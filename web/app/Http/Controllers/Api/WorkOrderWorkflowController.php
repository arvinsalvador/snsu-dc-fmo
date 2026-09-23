<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\WorkOrders\WorkOrderController;
use App\Models\WorkOrder;
use App\Services\WorkOrderWorkflowService;
use Illuminate\Http\Request;

class WorkOrderWorkflowController extends Controller
{
    public function action(Request $request, WorkOrder $workOrder, string $action, WorkOrderWorkflowService $workflow, WorkOrderController $requests, \App\Http\Controllers\WorkOrders\WorkOrderWorkflowController $actions)
    {
        $updated = $actions->perform($request, $workOrder, $action, $workflow, $requests);

        return response()->json(['data' => ['id' => $updated->id, 'number' => $updated->work_order_number, 'status' => $updated->status->value, 'recommendation' => $request->user()->can('work_orders.view_all') ? $updated->recommendation : null]]);
    }
}
