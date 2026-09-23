<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WorkOrderController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('work_orders.view_own') || $request->user()->can('work_orders.view_all'), 403);
        $orders = WorkOrder::with('category', 'building', 'location', 'preferredPersonnel.user')
            ->when(! $request->user()->can('work_orders.view_all'), fn ($q) => $q->where('requester_id', $request->user()->id))
            ->latest('submitted_at')->get();

        return response()->json(['data' => $orders->map(fn ($order) => $this->data($order))]);
    }

    public function show(Request $request, WorkOrder $workOrder)
    {
        abort_unless($workOrder->requester_id === $request->user()->id || $request->user()->can('work_orders.view_all'), 403);

        return response()->json(['data' => $this->data($workOrder->load('category', 'building', 'location', 'preferredPersonnel.user'))]);
    }

    public function store(Request $request, \App\Http\Controllers\WorkOrders\WorkOrderController $requests)
    {
        abort_unless($request->user()->can('work_orders.create'), 403);
        $data = $requests->validated($request);
        $order = DB::transaction(function () use ($request, $requests, $data): WorkOrder {
            $order = WorkOrder::create([...$data, 'requester_id' => $request->user()->id, 'status' => WorkOrderStatus::Submitted, 'submitted_at' => now(), 'work_order_number' => $requests->number()]);
            $requests->uploads($request, $order);

            return $order;
        });

        return response()->json(['data' => $this->data($order->load('category', 'building', 'location', 'preferredPersonnel.user'))], 201);
    }

    public function update(Request $request, WorkOrder $workOrder, \App\Http\Controllers\WorkOrders\WorkOrderController $requests)
    {
        abort_unless($workOrder->requester_id === $request->user()->id && $workOrder->status === WorkOrderStatus::Submitted && $request->user()->can('work_orders.update_own_submitted'), 403);
        $workOrder->update($requests->validated($request));
        $requests->uploads($request, $workOrder);

        return response()->json(['data' => $this->data($workOrder->fresh()->load('category', 'building', 'location', 'preferredPersonnel.user'))]);
    }

    public function attachment(Request $request, WorkOrder $workOrder, WorkOrderAttachment $attachment, \App\Http\Controllers\WorkOrders\WorkOrderController $requests)
    {
        $requests->authorizeWorkOrder($request, $workOrder);
        abort_unless($attachment->work_order_id === $workOrder->id, 404);

        return Storage::disk('local')->download($attachment->stored_path, $attachment->original_filename);
    }

    private function data(WorkOrder $order): array
    {
        return ['id' => $order->id, 'number' => $order->work_order_number, 'category' => $order->category->name, 'building' => $order->building->name, 'location' => $order->location?->name, 'subject' => $order->subject, 'description' => $order->description, 'status' => $order->status->value, 'urgency' => $order->urgency, 'preferred_personnel' => $order->preferredPersonnel?->user?->name, 'submitted_at' => $order->submitted_at, 'attachments' => $order->attachments->map(fn ($attachment) => ['id' => $attachment->id, 'filename' => $attachment->original_filename, 'mime_type' => $attachment->mime_type, 'size' => $attachment->file_size])];
    }
}
