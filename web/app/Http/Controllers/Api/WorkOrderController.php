<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Services\WorkOrderAssignmentService;
use App\Services\WorkOrderWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WorkOrderController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('work_orders.view_own') || $request->user()->can('work_orders.view_all'), 403);
        $orders = WorkOrder::with('category', 'building', 'location', 'preferredPersonnel.user', 'activeAssignments.personnel.user', 'workflowEvents', 'attachments', 'updates')
            ->when(! $request->user()->can('work_orders.view_all'), fn ($q) => $q->where('requester_id', $request->user()->id))
            ->latest('submitted_at')->paginate(50);

        return response()->json(['data' => $orders->getCollection()->map(fn ($order) => $this->data($order)), 'current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage()]);
    }

    public function show(Request $request, WorkOrder $workOrder)
    {
        app(\App\Http\Controllers\WorkOrders\WorkOrderController::class)->authorizeWorkOrder($request, $workOrder);

        return response()->json(['data' => $this->data($workOrder->load('category', 'building', 'location', 'preferredPersonnel.user', 'activeAssignments.personnel.user', 'workflowEvents', 'attachments', 'updates'))]);
    }

    public function assigned(Request $request)
    {
        abort_unless($request->user()->can('work_orders.view_assigned'), 403);
        $orders = WorkOrder::with('category', 'building', 'location', 'preferredPersonnel.user', 'activeAssignments.personnel.user', 'workflowEvents', 'attachments', 'updates')
            ->whereHas('activeAssignments.personnel', fn ($query) => $query->where('user_id', $request->user()->id))
            ->latest('submitted_at')->paginate(50);

        return response()->json(['data' => $orders->getCollection()->map(fn ($order) => $this->data($order)), 'current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage()]);
    }

    public function store(Request $request, \App\Http\Controllers\WorkOrders\WorkOrderController $requests, WorkOrderWorkflowService $workflow)
    {
        abort_unless($request->user()->can('work_orders.create'), 403);

        return $this->createOrder($request, $requests, $workflow, false);
    }

    public function storeDirect(Request $request, \App\Http\Controllers\WorkOrders\WorkOrderController $requests, WorkOrderWorkflowService $workflow)
    {
        abort_unless($request->user()->can('work_orders.create_direct'), 403);

        return $this->createOrder($request, $requests, $workflow, true);
    }

    private function createOrder(Request $request, \App\Http\Controllers\WorkOrders\WorkOrderController $requests, WorkOrderWorkflowService $workflow, bool $direct)
    {
        $data = $requests->validated($request);
        $order = DB::transaction(function () use ($request, $requests, $data, $workflow, $direct): WorkOrder {
            $order = WorkOrder::create([...$data, 'requester_id' => $request->user()->id, 'status' => $direct ? WorkOrderStatus::Approved : WorkOrderStatus::Submitted, 'submitted_at' => now(), 'work_order_number' => $requests->number(), 'decided_by' => $direct ? $request->user()->id : null, 'decided_at' => $direct ? now() : null]);
            $requests->uploads($request, $order);
            $workflow->recordCreation($order, $request->user(), $direct);

            return $order;
        });

        return response()->json(['data' => $this->data($order->load('category', 'building', 'location', 'preferredPersonnel.user'))], 201);
    }

    public function update(Request $request, WorkOrder $workOrder, \App\Http\Controllers\WorkOrders\WorkOrderController $requests)
    {
        DB::transaction(function () use ($request, $workOrder, $requests): void {
            $locked = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->requester_id === $request->user()->id && $locked->status === WorkOrderStatus::Submitted && $request->user()->can('work_orders.update_own_submitted'), 403);
            $locked->update($requests->validated($request));
            $requests->uploads($request, $locked);
        });

        return response()->json(['data' => $this->data($workOrder->fresh()->load('category', 'building', 'location', 'preferredPersonnel.user'))]);
    }

    public function attachment(Request $request, WorkOrder $workOrder, WorkOrderAttachment $attachment, \App\Http\Controllers\WorkOrders\WorkOrderController $requests)
    {
        $requests->authorizeWorkOrder($request, $workOrder);
        abort_unless($attachment->work_order_id === $workOrder->id, 404);
        abort_if($attachment->purpose === 'ASSESSMENT' && ! ($request->user()->can('work_orders.view_assessments') || app(WorkOrderAssignmentService::class)->assignedTo($workOrder, $request->user())), 403);
        abort_if($attachment->purpose === 'EXECUTION' && ! ($request->user()->can('work_orders.view_execution') || app(WorkOrderAssignmentService::class)->assignedTo($workOrder, $request->user()) || ($workOrder->requester_id === $request->user()->id && $attachment->requester_visible)), 403);

        return Storage::disk('local')->download($attachment->stored_path, $attachment->original_filename);
    }

    private function data(WorkOrder $order): array
    {
        $management = auth()->user()->can('work_orders.view_all') || auth()->user()->can('work_orders.screen') || auth()->user()->can('work_orders.approve') || auth()->user()->can('work_orders.view_assessments');
        $privateActions = ['RECOMMEND_APPROVAL', 'RECOMMEND_DISAPPROVAL', 'RETURN_TO_SCREENING', 'ASSIGNEE_ADDED', 'ASSIGNEE_REMOVED', 'ASSESSMENT_EXCEPTION', 'ASSESSMENT_HOLD_MATERIALS', 'ASSESSMENT_REFER_EXTERNAL', 'ASSESSMENT_BEYOND_SCOPE'];
        $events = $order->workflowEvents->when(! $management, fn ($events) => $events->filter(fn ($event) => ! in_array($event->action, $privateActions, true)));

        return ['id' => $order->id, 'number' => $order->work_order_number, 'category' => $order->category->name, 'building' => $order->building->name, 'location' => $order->location?->name, 'subject' => $order->subject, 'description' => $order->description, 'status' => $order->status->value, 'urgency' => $order->urgency, 'preferred_personnel' => $order->preferredPersonnel?->user?->name, 'assignees' => $order->activeAssignments->map(fn ($assignment) => ['id' => $assignment->id, 'personnel_id' => $assignment->fmo_personnel_id, 'name' => $assignment->personnel->user->name]), 'submitted_at' => $order->submitted_at, 'completed_at' => $order->verified_at, 'work_performed_summary' => $order->status === WorkOrderStatus::Completed ? $order->work_performed_summary : null, 'progress' => $order->updates->whereNotNull('requester_summary')->sortBy('recorded_at')->map(fn ($update) => ['type' => $update->type->value, 'summary' => $update->requester_summary, 'at' => $update->recorded_at])->values(), 'attachments' => $order->attachments->where('purpose', 'REQUEST_INITIAL')->map(fn ($attachment) => ['id' => $attachment->id, 'filename' => $attachment->original_filename, 'mime_type' => $attachment->mime_type, 'size' => $attachment->file_size])->values(), 'history' => $events->values()->map(fn ($event) => ['action' => $event->action, 'from_status' => $event->from_status, 'to_status' => $event->to_status, 'requester_message' => $event->requester_message, 'internal_note' => $management ? $event->internal_note : null, 'created_at' => $event->created_at])];
    }
}
