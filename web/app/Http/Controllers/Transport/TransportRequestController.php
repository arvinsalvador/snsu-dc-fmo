<?php

namespace App\Http\Controllers\Transport;

use App\Enums\FuelLevel;
use App\Enums\TransportApprovalAuthority;
use App\Enums\TransportStatus;
use App\Http\Controllers\Controller;
use App\Models\TransportRequest;
use App\Services\TransportAccessService;
use App\Services\TransportRequestService;
use Illuminate\Http\Request;

class TransportRequestController extends Controller
{
    public function __construct(private readonly TransportRequestService $service, private readonly TransportAccessService $access) {}

    /** All trip requests for management (approval and dispatch queue). */
    public function index(Request $request)
    {
        abort_unless($request->user()->can('transport.view_all'), 403);

        return $this->list($request, TransportRequest::query(), 'all', 'Trip Requests', 'Review, approve, and assign every transport request.');
    }

    /** Requests the user submitted or is assigned to drive. */
    public function mine(Request $request)
    {
        abort_unless($request->user()->can('transport.view_own'), 403);
        $personnel = $this->access->personnel($request->user());
        $query = TransportRequest::query()->where(function ($inner) use ($request, $personnel): void {
            $inner->where('requester_id', $request->user()->id);
            if ($personnel && $request->user()->can('transport.update_assigned_trip')) {
                $inner->orWhere('driver_personnel_id', $personnel->id);
            }
        });

        return $this->list($request, $query, 'mine', 'My Trip Requests', 'Your transport requests and the trips assigned to you.');
    }

    /** Completed trips, scoped to what the user may see. */
    public function history(Request $request)
    {
        abort_unless($request->user()->can('transport.history') || $request->user()->can('transport.view_own'), 403);
        $query = $this->access->scope(TransportRequest::query(), $request->user())->where('status', TransportStatus::Completed->value);
        if (! $request->user()->can('transport.history')) {
            $personnel = $this->access->personnel($request->user());
            $query = TransportRequest::query()->where('status', TransportStatus::Completed->value)->where(function ($inner) use ($request, $personnel): void {
                $inner->where('requester_id', $request->user()->id);
                if ($personnel) {
                    $inner->orWhere('driver_personnel_id', $personnel->id);
                }
            });
        }
        $request->merge(['status' => null]);

        return $this->list($request, $query, 'history', 'Trip History', 'Completed trips with route, driver, vehicle, and distance travelled.');
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('transport.request'), 403);

        return view('transport.create');
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('transport.request'), 403);
        $transport = $this->service->create($request->user(), $request->validate($this->service->requestRules()));

        return redirect()->route('transport.show', $transport)->with('status', 'Transport request '.$transport->request_number.' submitted for approval.');
    }

    public function show(Request $request, TransportRequest $transportRequest)
    {
        abort_unless($this->access->canView($transportRequest, $request->user()), 403);
        $transportRequest->load(['requester', 'approver', 'rejecter', 'driver.user', 'vehicle', 'assigner', 'attachments.uploader', 'events.actor', 'correctiveWorkOrders']);
        $user = $request->user();
        $canDispatch = $this->access->canDispatch($transportRequest, $user);

        return view('transport.show', [
            'transport' => $transportRequest,
            'access' => $this->access,
            'canApprove' => $this->access->canApprove($transportRequest, $user),
            'canDispatch' => $canDispatch,
            'canUpdateTrip' => $this->access->canUpdateTrip($transportRequest, $user),
            'canCancel' => $this->access->canCancel($transportRequest, $user),
            'canSeeApprovalEvidence' => $this->access->canSeeApprovalEvidence($user),
            'canSeeTripDocuments' => $this->access->canSeeTripDocuments($transportRequest, $user),
            'canCreateWorkOrder' => $this->access->canCreateCorrectiveWorkOrder($transportRequest, $user),
            'authorities' => $user->can('transport.approve')
                ? TransportApprovalAuthority::cases()
                : [TransportApprovalAuthority::CampusDirector, TransportApprovalAuthority::FmoHead],
            'drivers' => $canDispatch ? $this->service->eligibleDrivers() : collect(),
            'vehicles' => $canDispatch ? $this->service->eligibleVehicles() : collect(),
            'fuelLevels' => FuelLevel::cases(),
        ]);
    }

    public function cancel(Request $request, TransportRequest $transportRequest)
    {
        abort_unless($this->access->canCancel($transportRequest, $request->user()), 403);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $this->service->cancel($transportRequest, $request->user(), $data['reason'] ?? null);

        return redirect()->route('transport.show', $transportRequest)->with('status', 'Transport request cancelled.');
    }

    private function list(Request $request, $query, string $mode, string $title, string $description)
    {
        $counts = (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $summary = $mode === 'history'
            ? (clone $query)->selectRaw('count(*) as trips, coalesce(sum(distance_km), 0) as distance, count(distinct inspectable_asset_id) as vehicles')->first()
            : null;
        $completedThisMonth = $mode === 'history' ? null : (clone $query)->where('status', TransportStatus::Completed->value)
            ->whereBetween('completed_at', [now(config('app.timezone'))->startOfMonth(), now(config('app.timezone'))->endOfMonth()])->count();
        $requests = $this->service->filter($query, $request)
            ->with(['requester', 'driver.user', 'vehicle'])
            ->when($mode === 'history', fn ($q) => $q->orderByDesc('completed_at'), fn ($q) => $q->orderByRaw("CASE WHEN status = 'PENDING_APPROVAL' THEN 0 WHEN status = 'APPROVED' THEN 1 WHEN status = 'ASSIGNED' THEN 2 ELSE 3 END")->orderByDesc('travel_date'))
            ->paginate($this->service->perPage($request))->withQueryString();

        return view('transport.index', [
            'requests' => $requests, 'mode' => $mode, 'counts' => $counts, 'summary' => $summary, 'completedThisMonth' => $completedThisMonth, 'title' => $title, 'description' => $description,
            'statuses' => TransportStatus::cases(),
            'drivers' => $request->user()->can('transport.view_all') ? $this->service->eligibleDrivers() : collect(),
            'vehicles' => $request->user()->can('transport.view_all') ? $this->service->eligibleVehicles() : collect(),
        ]);
    }
}
