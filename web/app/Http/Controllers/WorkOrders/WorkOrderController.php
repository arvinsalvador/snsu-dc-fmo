<?php

namespace App\Http\Controllers\WorkOrders;

use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\BuildingLocation;
use App\Models\Campus;
use App\Models\Floor;
use App\Models\FmoPersonnel;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Models\WorkOrderCategory;
use App\Services\EvidenceMediaService;
use App\Services\StoredFileTransaction;
use App\Services\WorkOrderAssignmentService;
use App\Services\WorkOrderWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkOrderController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('work_orders.view_all'), 403);

        return $this->listOrders($request, true);
    }

    public function mine(Request $request)
    {
        abort_unless($request->user()->can('work_orders.view_own') || $request->user()->can('work_orders.view_all'), 403);

        return $this->listOrders($request, false);
    }

    private function listOrders(Request $request, bool $all)
    {
        $orders = WorkOrder::with('requester', 'category', 'building', 'location', 'preferredPersonnel.user')->latest('submitted_at');
        if (! $all) {
            $orders->where('requester_id', $request->user()->id);
        }
        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $orders->where(fn ($query) => $query->where('work_order_number', 'like', "%{$search}%")->orWhere('subject', 'like', "%{$search}%"));
        }
        if ($request->filled('status')) {
            $orders->where('status', $request->string('status')->value());
        }

        return view('work-orders.index', ['orders' => $orders->paginate(20)->withQueryString(), 'all' => $all]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('work-orders.create-request'), 403);

        return view('work-orders.form', $this->formData());
    }

    public function store(Request $request, WorkOrderWorkflowService $workflow)
    {
        abort_unless($request->user()->can('work-orders.create-request'), 403);

        return $this->createOrder($request, $workflow, false);
    }

    public function storeDirect(Request $request, WorkOrderWorkflowService $workflow)
    {
        abort_unless($request->user()->can('work_orders.create_direct'), 403);

        return $this->createOrder($request, $workflow, true);
    }

    private function createOrder(Request $request, WorkOrderWorkflowService $workflow, bool $direct)
    {
        $data = $this->validated($request);
        $order = app(StoredFileTransaction::class)->run(function () use ($request, $data, $workflow, $direct): WorkOrder {
            $order = WorkOrder::create([...$data, 'requester_id' => $request->user()->id, 'status' => $direct ? WorkOrderStatus::Approved : WorkOrderStatus::Submitted, 'submitted_at' => now(), 'work_order_number' => $this->number(), 'decided_by' => $direct ? $request->user()->id : null, 'decided_at' => $direct ? now() : null]);
            $this->uploads($request, $order);
            $workflow->recordCreation($order, $request->user(), $direct);

            return $order;
        });

        return redirect()->route('work-orders.show', $order)->with('success', $direct ? "Direct Work Order {$order->work_order_number} was authorized." : "Your Work Order Request {$order->work_order_number} has been submitted successfully.");
    }

    public function show(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeWorkOrder($request, $workOrder);

        $assignablePersonnel = collect();
        if ($request->user()->can('work_orders.assign') || $request->user()->can('work_orders.assign_direct') || $request->user()->can('work_orders.reassign')) {
            $assignablePersonnel = FmoPersonnel::with('user', 'skills')
                ->withCount(['workOrderAssignments as active_workload_count' => fn ($query) => $query->whereNull('unassigned_at')])
                ->where('personnel_status', 'ACTIVE')->whereNull('archived_at')->get()->filter->isAssignable();
        }

        $workOrder->load('requester.requesterProfile', 'category', 'campus', 'building', 'floor', 'location', 'preferredPersonnel.user', 'attachments.uploader', 'attachments.workUpdate', 'workflowEvents.actor', 'activeAssignments.personnel.user', 'assessments.personnel.user', 'sessions.personnel.user', 'updates.personnel.user', 'updates.attachments', 'completionSubmitter', 'verifier');

        return view('work-orders.show', [
            'order' => $workOrder,
            'assignablePersonnel' => $assignablePersonnel,
            'clarification' => $workOrder->status === WorkOrderStatus::NeedsInformation ? $workOrder->latestClarificationRequest() : null,
        ]);
    }

    public function edit(Request $request, WorkOrder $workOrder)
    {
        abort_unless($workOrder->requester_id === $request->user()->id && (
            ($workOrder->status === WorkOrderStatus::Submitted && $request->user()->can('work_orders.update_own_submitted'))
            || ($workOrder->status === WorkOrderStatus::NeedsInformation && $request->user()->can('work_orders.resubmit_own'))
        ), 403);

        $workOrder->load('attachments.uploader');

        return view('work-orders.form', [
            ...$this->formData(),
            'order' => $workOrder,
            'clarification' => $workOrder->status === WorkOrderStatus::NeedsInformation ? $workOrder->latestClarificationRequest() : null,
        ]);
    }

    public function update(Request $request, WorkOrder $workOrder)
    {
        app(StoredFileTransaction::class)->run(function () use ($request, $workOrder): void {
            $locked = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->requester_id === $request->user()->id && $locked->status === WorkOrderStatus::Submitted && $request->user()->can('work_orders.update_own_submitted'), 403);
            $locked->update($this->validated($request));
            $this->uploads($request, $locked);
        });

        return back()->with('success', 'Submitted request updated.');
    }

    public function attachment(Request $request, WorkOrder $workOrder, WorkOrderAttachment $attachment)
    {
        $this->authorizeAttachment($request, $workOrder, $attachment);

        return $this->privateFileResponse($attachment->stored_path, $attachment, true);
    }

    public function attachmentThumbnail(Request $request, WorkOrder $workOrder, WorkOrderAttachment $attachment)
    {
        $this->authorizeAttachment($request, $workOrder, $attachment);
        abort_unless($attachment->isImage(), 404);

        return $this->privateFileResponse($attachment->thumbnail_path ?: $attachment->stored_path, $attachment, true);
    }

    public function attachmentDownload(Request $request, WorkOrder $workOrder, WorkOrderAttachment $attachment)
    {
        $this->authorizeAttachment($request, $workOrder, $attachment);

        return Storage::disk('local')->download($attachment->stored_path, $attachment->safeDownloadName());
    }

    public function authorizeAttachment(Request $request, WorkOrder $workOrder, WorkOrderAttachment $attachment): void
    {
        $this->authorizeWorkOrder($request, $workOrder);
        abort_unless($attachment->work_order_id === $workOrder->id, 404);
        abort_if($attachment->purpose === 'ASSESSMENT' && ! ($request->user()->can('work_orders.view_assessments') || app(WorkOrderAssignmentService::class)->assignedTo($workOrder, $request->user())), 403);
        abort_if($attachment->purpose === 'EXECUTION' && ! ($request->user()->can('work_orders.view_execution') || app(WorkOrderAssignmentService::class)->assignedTo($workOrder, $request->user()) || ($workOrder->requester_id === $request->user()->id && $attachment->requester_visible)), 403);
    }

    private function privateFileResponse(string $path, WorkOrderAttachment $attachment, bool $inline)
    {
        abort_unless(Storage::disk('local')->exists($path), 404);
        $disposition = $inline ? 'inline' : 'attachment';
        $filename = str_replace(['"', "\r", "\n"], '_', $attachment->safeDownloadName());

        $response = response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    public function authorizeWorkOrder(Request $request, WorkOrder $workOrder): void
    {
        abort_unless($this->canViewWorkOrder($request, $workOrder), 403);
    }

    public function canViewWorkOrder(Request $request, WorkOrder $workOrder): bool
    {
        return $workOrder->requester_id === $request->user()->id
            || $request->user()->can('work_orders.view_all')
            || $request->user()->can('work_orders.view_execution')
            || ($request->user()->can('work_orders.view_assigned') && app(WorkOrderAssignmentService::class)->assignedTo($workOrder, $request->user()))
            || (app(WorkOrderAssignmentService::class)->mayManage($workOrder, $request->user()) && in_array($workOrder->status, [WorkOrderStatus::Approved, WorkOrderStatus::Assigned, WorkOrderStatus::ForAssessment, WorkOrderStatus::AssessmentReview, WorkOrderStatus::ReadyForWork], true))
            || ($request->user()->can('work_orders.screen') && in_array($workOrder->status, [WorkOrderStatus::Submitted, WorkOrderStatus::ForScreening, WorkOrderStatus::NeedsInformation, WorkOrderStatus::ForApproval], true))
            || ($request->user()->can('work_orders.approve') && $workOrder->status === WorkOrderStatus::ForApproval);
    }

    public function validated(Request $request): array
    {
        $data = $request->validate(['work_order_category_id' => ['required', Rule::exists('work_order_categories', 'id')->where('is_active', true)], 'campus_id' => ['required', Rule::exists('campuses', 'id')->where('is_active', true)], 'building_id' => ['required', Rule::exists('buildings', 'id')->where('is_active', true)], 'floor_id' => ['nullable', Rule::exists('floors', 'id')->where('is_active', true)], 'building_location_id' => ['nullable', Rule::exists('building_locations', 'id')->where('is_active', true)], 'subject' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:5000'], 'urgency' => ['required', Rule::in(['NORMAL', 'URGENT'])], 'preferred_fmo_personnel_id' => ['nullable', Rule::exists('fmo_personnel', 'id')], 'attachments' => ['array', 'max:'.config('work_orders.attachments.max_count')], 'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp,mp4', 'max:'.config('work_orders.attachments.max_size_kb')]]);
        $errors = [];
        if (! Building::whereKey($data['building_id'])->where('campus_id', $data['campus_id'])->where('is_active', true)->whereHas('campus', fn ($query) => $query->where('is_active', true))->exists()) {
            $errors['building_id'] = 'The selected building does not belong to the selected campus.';
        }
        if (($data['floor_id'] ?? null) && ! Floor::whereKey($data['floor_id'])->where('building_id', $data['building_id'])->where('is_active', true)->exists()) {
            $errors['floor_id'] = 'The selected floor does not belong to the selected building.';
        }
        if (($data['building_location_id'] ?? null) && ! BuildingLocation::whereKey($data['building_location_id'])
            ->where('building_id', $data['building_id'])
            ->where('is_active', true)
            ->where(function ($query) use ($data): void {
                if ($data['floor_id'] ?? null) {
                    $query->whereNull('floor_id')->orWhere('floor_id', $data['floor_id']);
                } else {
                    $query->whereNull('floor_id');
                }
            })->exists()) {
            $errors['building_location_id'] = 'The selected location does not belong to the selected building and floor.';
        }
        if (($data['preferred_fmo_personnel_id'] ?? null) && ! FmoPersonnel::with('user')->findOrFail($data['preferred_fmo_personnel_id'])->isAssignable()) {
            $errors['preferred_fmo_personnel_id'] = 'The selected personnel member is not currently available.';
        }
        $existing = $request->route('workOrder')?->attachments()->where('purpose', 'REQUEST_INITIAL')->count() ?? 0;
        if ($existing + count($request->file('attachments', [])) > config('work_orders.attachments.max_count')) {
            $errors['attachments'] = 'The Work Order has reached its initial attachment limit.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return collect($data)->except('attachments')->all();
    }

    public function number(): string
    {
        $year = now()->year;
        DB::table('work_order_number_sequences')->insertOrIgnore(['year' => $year, 'next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $sequence = DB::table('work_order_number_sequences')->where('year', $year)->lockForUpdate()->first();
        $number = $sequence->next_value;
        DB::table('work_order_number_sequences')->where('year', $year)->update(['next_value' => $number + 1, 'updated_at' => now()]);

        return sprintf('WO-%d-%06d', $year, $number);
    }

    public function uploads(Request $request, WorkOrder $workOrder): void
    {
        foreach ($request->file('attachments', []) as $file) {
            $media = app(EvidenceMediaService::class)->store($file, 'work-orders/'.$workOrder->id, 'attachments');
            $workOrder->attachments()->create(['uploaded_by' => $request->user()->id, 'purpose' => 'REQUEST_INITIAL', ...$media]);
        }
    }

    public function formData(): array
    {
        return [
            'categories' => WorkOrderCategory::where('is_active', true)->orderBy('display_order')->get(),
            'campuses' => Campus::where('is_active', true)->orderBy('name')->get(),
            'buildings' => Building::where('is_active', true)->whereHas('campus', fn ($query) => $query->where('is_active', true))->with('campus')->orderBy('name')->get(),
            'floors' => Floor::where('is_active', true)->whereHas('building', fn ($query) => $query->where('is_active', true)->whereHas('campus', fn ($campus) => $campus->where('is_active', true)))->orderBy('display_order')->orderBy('name')->get(),
            'locations' => BuildingLocation::where('is_active', true)->whereHas('building', fn ($query) => $query->where('is_active', true)->whereHas('campus', fn ($campus) => $campus->where('is_active', true)))->where(fn ($query) => $query->whereNull('floor_id')->orWhereHas('floor', fn ($floor) => $floor->where('is_active', true)))->orderBy('name')->get(),
            'personnel' => FmoPersonnel::with('user', 'skills')->where('personnel_status', 'ACTIVE')->get()->filter->isAssignable(),
        ];
    }
}
