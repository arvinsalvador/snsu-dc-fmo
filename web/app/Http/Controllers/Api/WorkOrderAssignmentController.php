<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Models\WorkOrderAssignment;
use App\Services\WorkOrderAssignmentService;
use Illuminate\Http\Request;

class WorkOrderAssignmentController extends Controller
{
    public function store(Request $request, WorkOrder $workOrder, WorkOrderAssignmentService $assignments)
    {
        abort_unless($assignments->mayManage($workOrder, $request->user(), $workOrder->status !== WorkOrderStatus::Approved), 403);
        $data = $request->validate(['personnel_ids' => ['required', 'array', 'min:1', 'max:10'], 'personnel_ids.*' => ['required', 'ulid', 'distinct'], 'note' => ['nullable', 'string', 'max:2000']]);
        $order = $assignments->add($workOrder, $request->user(), $data['personnel_ids'], $data['note'] ?? null);

        return response()->json(['data' => ['id' => $order->id, 'status' => $order->status->value, 'assignments' => $order->activeAssignments->map(fn ($assignment) => ['id' => $assignment->id, 'personnel_id' => $assignment->fmo_personnel_id, 'name' => $assignment->personnel->user->name])]], 201);
    }

    public function destroy(Request $request, WorkOrder $workOrder, WorkOrderAssignment $assignment, WorkOrderAssignmentService $assignments)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $order = $assignments->remove($workOrder, $assignment, $request->user(), $data['reason']);

        return response()->json(['data' => ['id' => $order->id, 'status' => $order->status->value]]);
    }
}
