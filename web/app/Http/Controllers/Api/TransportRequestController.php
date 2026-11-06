<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TransportRequest;
use App\Services\TransportAccessService;
use App\Services\TransportRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransportRequestController extends Controller
{
    public function __construct(private readonly TransportRequestService $service, private readonly TransportAccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->access->scope(TransportRequest::query(), $request->user());
        $page = $this->service->filter($query, $request)->with(['requester', 'driver.user', 'vehicle'])
            ->orderByDesc('travel_date')->paginate($this->service->perPage($request))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (TransportRequest $transport) => $this->present($transport))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, TransportRequest $transportRequest): JsonResponse
    {
        abort_unless($this->access->canView($transportRequest, $request->user()), 403);

        return response()->json(['data' => $this->present($transportRequest->load(['requester', 'driver.user', 'vehicle', 'approver', 'attachments']), true, $request)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('transport.request'), 403);
        $transport = $this->service->create($request->user(), $request->validate($this->service->requestRules()));

        return response()->json(['data' => $this->present($transport->load(['requester']))], 201);
    }

    public function approve(Request $request, TransportRequest $transportRequest): JsonResponse
    {
        abort_unless($this->access->canApprove($transportRequest, $request->user()), 403);
        $transport = $this->service->approve($transportRequest, $request->user(), $request->validate($this->service->approvalRules()));

        return response()->json(['data' => $this->present($transport->load('requester'))]);
    }

    public function reject(Request $request, TransportRequest $transportRequest): JsonResponse
    {
        abort_unless($this->access->canApprove($transportRequest, $request->user()), 403);
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => $this->present($this->service->reject($transportRequest, $request->user(), $data['rejection_reason'])->load('requester'))]);
    }

    public function assign(Request $request, TransportRequest $transportRequest): JsonResponse
    {
        abort_unless($this->access->canDispatch($transportRequest, $request->user()), 403);
        $data = $request->validate($this->service->assignmentRules());

        return response()->json(['data' => $this->present($this->service->assign($transportRequest, $request->user(), $data['driver_personnel_id'], $data['inspectable_asset_id'])->load('requester'))]);
    }

    public function trip(Request $request, TransportRequest $transportRequest): JsonResponse
    {
        abort_unless($this->access->canUpdateTrip($transportRequest, $request->user()), 403);

        return response()->json(['data' => $this->present($this->service->saveTrip($transportRequest, $request->user(), $request->validate($this->service->tripRules($request)))->load('requester'))]);
    }

    public function complete(Request $request, TransportRequest $transportRequest): JsonResponse
    {
        abort_unless($this->access->canUpdateTrip($transportRequest, $request->user()) && $request->user()->can('transport.complete_assigned_trip'), 403);

        return response()->json(['data' => $this->present($this->service->saveTrip($transportRequest, $request->user(), $request->validate($this->service->tripRules($request)), complete: true)->load('requester'))]);
    }

    /** @return array<string, mixed> */
    private function present(TransportRequest $transport, bool $detail = false, ?Request $request = null): array
    {
        $data = [
            'id' => $transport->id, 'request_number' => $transport->request_number, 'status' => $transport->status->value,
            'purpose' => $transport->purpose, 'origin' => $transport->origin, 'destination' => $transport->destination,
            'travel_date' => $transport->travel_date->toDateString(),
            'expected_departure_at' => $transport->expected_departure_at, 'expected_return_at' => $transport->expected_return_at,
            'passenger_count' => $transport->passenger_count,
            'requester' => $transport->relationLoaded('requester') ? ['id' => $transport->requester->id, 'name' => $transport->requester->name] : null,
            'driver' => $transport->relationLoaded('driver') && $transport->driver ? ['id' => $transport->driver->id, 'name' => $transport->driver->user?->name] : null,
            'vehicle' => $transport->relationLoaded('vehicle') && $transport->vehicle ? ['id' => $transport->vehicle->id, 'asset_code' => $transport->vehicle->asset_code, 'name' => $transport->vehicle->name] : null,
            'actual_departed_at' => $transport->actual_departed_at, 'actual_arrived_at' => $transport->actual_arrived_at,
            'odometer_before' => $transport->odometer_before, 'odometer_after' => $transport->odometer_after, 'distance_km' => $transport->distance_km,
            'fuel_before' => $transport->fuel_before?->value, 'fuel_after' => $transport->fuel_after?->value,
        ];
        if (! $detail) {
            return $data;
        }
        $user = $request->user();
        $visible = $transport->attachments->filter(fn ($attachment) => match ($attachment->purpose) {
            TransportRequest::APPROVAL_EVIDENCE_PURPOSE => $this->access->canSeeApprovalEvidence($user),
            TransportRequest::TRIP_DOCUMENT_PURPOSE, TransportRequest::ISSUE_PHOTO_PURPOSE => $this->access->canSeeTripDocuments($transport, $user),
            default => true,
        });

        return $data + [
            'remarks' => $transport->remarks, 'passenger_details' => $transport->passenger_details, 'trip_remarks' => $transport->trip_remarks,
            'approval' => $transport->approved_at ? [
                'approved_by' => $transport->approver?->name, 'approved_at' => $transport->approved_at,
                'authority' => $transport->approval_authority?->value, 'instructed_by' => $transport->approval_instructed_by,
                'note' => $this->access->canSeeApprovalEvidence($user) ? $transport->approval_note : null,
            ] : null,
            'rejection_reason' => $transport->rejection_reason,
            'vehicle_issue_observed' => $transport->vehicle_issue_observed, 'vehicle_issue_remarks' => $transport->vehicle_issue_remarks,
            'attachments' => $visible->map(fn ($attachment) => [
                'id' => $attachment->id, 'purpose' => $attachment->purpose, 'filename' => $attachment->original_filename,
                'mime_type' => $attachment->mime_type, 'url' => route('api.transport-attachments.show', $attachment),
            ])->values(),
        ];
    }
}
