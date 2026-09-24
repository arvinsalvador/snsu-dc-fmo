<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\WorkOrders\WorkOrderExecutionController as WebExecution;
use App\Models\WorkOrder;
use App\Models\WorkSession;
use App\Services\WorkOrderAssignmentService;
use App\Services\WorkOrderExecutionService;
use Illuminate\Http\Request;

class WorkOrderExecutionController extends Controller
{
    public function active(Request $request)
    {
        abort_unless($request->user()->can('work_orders.start_assigned'), 403);
        $session = WorkSession::with('workOrder')->whereHas('personnel', fn ($query) => $query->where('user_id', $request->user()->id))->whereNull('ended_at')->first();

        return response()->json(['data' => $session ? ['id' => $session->id, 'work_order_id' => $session->work_order_id, 'work_order_number' => $session->workOrder->work_order_number, 'started_at' => $session->started_at] : null]);
    }

    public function sessions(Request $request, WorkOrder $workOrder, WorkOrderAssignmentService $assignments)
    {
        abort_unless($request->user()->can('work_orders.view_execution') || ($request->user()->can('work_orders.view_assigned') && $assignments->assignedTo($workOrder, $request->user())), 403);
        $sessions = $workOrder->sessions()->with('personnel.user', 'updates.attachments')->get();

        return response()->json(['data' => $sessions->map(fn ($session) => ['id' => $session->id, 'personnel_id' => $session->fmo_personnel_id, 'personnel_name' => $session->personnel->user->name, 'started_at' => $session->started_at, 'ended_at' => $session->ended_at, 'end_outcome' => $session->end_outcome?->value, 'summary' => $session->session_summary, 'updates' => $session->updates->map(fn ($update) => ['id' => $update->id, 'type' => $update->type->value, 'description' => $update->description, 'requester_summary' => $update->requester_summary, 'recorded_at' => $update->recorded_at, 'evidence' => $update->attachments->map(fn ($file) => ['id' => $file->id, 'type' => $file->evidence_type, 'filename' => $file->original_filename])])])]);
    }

    public function start(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution)
    {
        $session = $execution->start($workOrder, $request->user());

        return response()->json(['data' => ['work_order_id' => $workOrder->id, 'session_id' => $session->id, 'started_at' => $session->started_at, 'status' => $workOrder->fresh()->status->value]], 201);
    }

    public function update(Request $request, WorkOrder $workOrder, WorkSession $session, WorkOrderExecutionService $execution, WebExecution $web)
    {
        $data = $request->validate($web->updateRules());
        $update = $execution->update($workOrder, $session, $request->user(), $data, $request->file('evidence', []));

        return response()->json(['data' => ['id' => $update->id, 'session_id' => $session->id, 'type' => $update->type->value, 'recorded_at' => $update->recorded_at, 'evidence' => $update->attachments->map(fn ($file) => ['id' => $file->id, 'type' => $file->evidence_type])]], 201);
    }

    public function end(Request $request, WorkOrder $workOrder, WorkSession $session, WorkOrderExecutionService $execution, WebExecution $web)
    {
        $data = $request->validate($web->endRules());
        $order = $execution->end($workOrder, $session, $request->user(), $data, $request->file('evidence', []));

        return response()->json(['data' => ['work_order_id' => $order->id, 'session_id' => $session->id, 'status' => $order->status->value]]);
    }

    public function submit(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution, WebExecution $web)
    {
        $data = $request->validate($web->completionRules());
        $order = $execution->submit($workOrder, $request->user(), $data);

        return response()->json(['data' => ['id' => $order->id, 'status' => $order->status->value]]);
    }

    public function verify(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution)
    {
        $data = $request->validate(['verification_note' => ['nullable', 'string', 'max:5000']]);
        $order = $execution->verify($workOrder, $request->user(), $data['verification_note'] ?? null);

        return response()->json(['data' => ['id' => $order->id, 'status' => $order->status->value, 'verified_at' => $order->verified_at]]);
    }

    public function returnForWork(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        $order = $execution->returnForWork($workOrder, $request->user(), $data['reason']);

        return response()->json(['data' => ['id' => $order->id, 'status' => $order->status->value]]);
    }

    public function releaseInvestigation(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        $order = $execution->releaseInvestigation($workOrder, $request->user(), $data['note']);

        return response()->json(['data' => ['id' => $order->id, 'status' => $order->status->value]]);
    }

    public function forceEnd(Request $request, WorkOrder $workOrder, WorkSession $session, WorkOrderExecutionService $execution)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        $order = $execution->forceEnd($workOrder, $session, $request->user(), $data['reason']);

        return response()->json(['data' => ['id' => $order->id, 'status' => $order->status->value]]);
    }
}
