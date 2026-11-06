<?php

namespace App\Http\Controllers\Transport;

use App\Enums\TransportStatus;
use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WorkOrders\WorkOrderController;
use App\Models\TransportRequest;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Services\TransportAccessService;
use App\Services\TransportRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransportWorkflowController extends Controller
{
    public function __construct(private readonly TransportRequestService $service, private readonly TransportAccessService $access) {}

    public function approve(Request $request, TransportRequest $transportRequest)
    {
        abort_unless($this->access->canApprove($transportRequest, $request->user()), 403);
        $this->service->approve($transportRequest, $request->user(), $request->validate($this->service->approvalRules()));

        return redirect()->route('transport.show', $transportRequest)->with('status', 'Transport request approved.');
    }

    public function reject(Request $request, TransportRequest $transportRequest)
    {
        abort_unless($this->access->canApprove($transportRequest, $request->user()), 403);
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $this->service->reject($transportRequest, $request->user(), $data['rejection_reason']);

        return redirect()->route('transport.show', $transportRequest)->with('status', 'Transport request rejected.');
    }

    public function assign(Request $request, TransportRequest $transportRequest)
    {
        abort_unless($this->access->canDispatch($transportRequest, $request->user()), 403);
        $data = $request->validate($this->service->assignmentRules());
        $this->service->assign($transportRequest, $request->user(), $data['driver_personnel_id'], $data['inspectable_asset_id']);

        return redirect()->route('transport.show', $transportRequest)->with('status', 'Driver and vehicle assigned.');
    }

    public function updateTrip(Request $request, TransportRequest $transportRequest)
    {
        abort_unless($this->access->canUpdateTrip($transportRequest, $request->user()), 403);
        $this->service->saveTrip($transportRequest, $request->user(), $request->validate($this->service->tripRules($request)));

        return redirect()->route('transport.show', $transportRequest)->with('status', 'Trip details saved. Complete the trip once all final details are in.');
    }

    public function complete(Request $request, TransportRequest $transportRequest)
    {
        abort_unless($this->access->canUpdateTrip($transportRequest, $request->user()) && $request->user()->can('transport.complete_assigned_trip'), 403);
        $this->service->saveTrip($transportRequest, $request->user(), $request->validate($this->service->tripRules($request)), complete: true);

        return redirect()->route('transport.show', $transportRequest)->with('status', 'Trip completed and added to the vehicle travel history.');
    }

    public function correctiveWorkOrder(Request $request, TransportRequest $transportRequest, WorkOrderController $numbers)
    {
        abort_unless($this->access->canCreateCorrectiveWorkOrder($transportRequest, $request->user()), 403);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'work_order_category_id' => ['nullable', Rule::exists('work_order_categories', 'id')->where('is_active', true)],
        ]);
        $created = false;
        $workOrder = DB::transaction(function () use ($transportRequest, $request, $data, $numbers, &$created): WorkOrder {
            $locked = TransportRequest::whereKey($transportRequest->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== TransportStatus::Completed || ! $locked->vehicle_issue_observed) {
                throw ValidationException::withMessages(['subject' => 'A corrective Work Order needs a completed trip with a reported vehicle issue.']);
            }
            if ($existing = $locked->correctiveWorkOrders()->oldest('submitted_at')->first()) {
                return $existing;
            }
            $created = true;
            $vehicle = $locked->vehicle;
            $category = isset($data['work_order_category_id'])
                ? WorkOrderCategory::findOrFail($data['work_order_category_id'])
                : WorkOrderCategory::where('name', 'General Repair / Maintenance')->firstOrFail();
            $workOrder = WorkOrder::create([
                'work_order_number' => $numbers->number(), 'requester_id' => $request->user()->id,
                'work_order_category_id' => $category->id, 'campus_id' => $vehicle->campus_id, 'building_id' => $vehicle->building_id,
                'floor_id' => $vehicle->floor_id, 'building_location_id' => $vehicle->building_location_id,
                'inspectable_asset_id' => $vehicle->id, 'transport_request_id' => $locked->id,
                'subject' => $data['subject'],
                'description' => collect([
                    'Vehicle issue reported by the driver on trip '.$locked->request_number.' ('.$locked->route().') for '.$vehicle->name.' ('.$vehicle->asset_code.').',
                    'Reported issue: '.$locked->vehicle_issue_remarks,
                    'Driver: '.$locked->driver?->user?->name.'.',
                ])->filter()->join("\n\n"),
                'urgency' => 'NORMAL', 'status' => WorkOrderStatus::Submitted, 'submitted_at' => now(), 'execution_cycle' => 0,
            ]);
            $workOrder->workflowEvents()->create([
                'actor_id' => $request->user()->id, 'action' => 'TRANSPORT_CORRECTIVE_CREATED', 'to_status' => WorkOrderStatus::Submitted->value,
                'internal_note' => 'Created from transport request '.$locked->request_number.'. Driver photos remain available on the trip record.', 'created_at' => now(),
            ]);
            $locked->events()->create(['actor_id' => $request->user()->id, 'event' => 'CORRECTIVE_WORK_ORDER', 'note' => $workOrder->work_order_number]);

            return $workOrder;
        });

        if (! $created) {
            return redirect()->route('transport.show', $transportRequest)->with('status', 'Corrective Work Order '.$workOrder->work_order_number.' already exists for this trip.');
        }

        return redirect()->route('work-orders.show', $workOrder)->with('success', 'Corrective Work Order '.$workOrder->work_order_number.' created through the normal workflow.');
    }
}
