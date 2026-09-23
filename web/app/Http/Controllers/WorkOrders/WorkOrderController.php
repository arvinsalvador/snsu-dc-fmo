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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkOrderController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('work_orders.view_own') || $request->user()->can('work_orders.view_all'), 403);
        $orders = WorkOrder::with('category', 'building', 'location', 'preferredPersonnel.user')->latest('submitted_at');
        if (! $request->user()->can('work_orders.view_all')) {
            $orders->where('requester_id', $request->user()->id);
        }
        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $orders->where(fn ($query) => $query->where('work_order_number', 'like', "%{$search}%")->orWhere('subject', 'like', "%{$search}%"));
        }
        if ($request->filled('status')) {
            $orders->where('status', $request->string('status')->value());
        }

        return view('work-orders.index', ['orders' => $orders->paginate(20)->withQueryString(), 'all' => $request->user()->can('work_orders.view_all')]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('work_orders.create'), 403);

        return view('work-orders.form', $this->formData());
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('work_orders.create'), 403);
        $data = $this->validated($request);
        $order = DB::transaction(function () use ($request, $data): WorkOrder {
            $order = WorkOrder::create([...$data, 'requester_id' => $request->user()->id, 'status' => WorkOrderStatus::Submitted, 'submitted_at' => now(), 'work_order_number' => $this->number()]);
            $this->uploads($request, $order);

            return $order;
        });

        return redirect()->route('work-orders.show', $order)->with('success', "Your Work Order Request {$order->work_order_number} has been submitted successfully.");
    }

    public function show(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeWorkOrder($request, $workOrder);

        return view('work-orders.show', ['order' => $workOrder->load('category', 'campus', 'building', 'floor', 'location', 'preferredPersonnel.user', 'attachments')]);
    }

    public function update(Request $request, WorkOrder $workOrder)
    {
        abort_unless($workOrder->requester_id === $request->user()->id && $workOrder->status === WorkOrderStatus::Submitted && $request->user()->can('work_orders.update_own_submitted'), 403);
        $workOrder->update($this->validated($request));
        $this->uploads($request, $workOrder);

        return back()->with('success', 'Submitted request updated.');
    }

    public function attachment(Request $request, WorkOrder $workOrder, WorkOrderAttachment $attachment)
    {
        $this->authorizeWorkOrder($request, $workOrder);
        abort_unless($attachment->work_order_id === $workOrder->id, 404);

        return Storage::disk('local')->download($attachment->stored_path, $attachment->original_filename);
    }

    public function authorizeWorkOrder(Request $request, WorkOrder $workOrder): void
    {
        abort_unless($workOrder->requester_id === $request->user()->id || $request->user()->can('work_orders.view_all'), 403);
    }

    public function validated(Request $request): array
    {
        $data = $request->validate(['work_order_category_id' => ['required', Rule::exists('work_order_categories', 'id')->where('is_active', true)], 'campus_id' => ['required', Rule::exists('campuses', 'id')->where('is_active', true)], 'building_id' => ['required', Rule::exists('buildings', 'id')->where('is_active', true)], 'floor_id' => ['nullable', Rule::exists('floors', 'id')->where('is_active', true)], 'building_location_id' => ['nullable', Rule::exists('building_locations', 'id')->where('is_active', true)], 'subject' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:5000'], 'urgency' => ['required', Rule::in(['NORMAL', 'URGENT'])], 'preferred_fmo_personnel_id' => ['nullable', Rule::exists('fmo_personnel', 'id')], 'attachments' => ['array', 'max:'.config('work_orders.attachments.max_count')], 'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp,mp4', 'max:'.config('work_orders.attachments.max_size_kb')]]);
        $errors = [];
        if (! Building::whereKey($data['building_id'])->where('campus_id', $data['campus_id'])->exists()) {
            $errors['building_id'] = 'The selected building does not belong to the selected campus.';
        }
        if (($data['floor_id'] ?? null) && ! Floor::whereKey($data['floor_id'])->where('building_id', $data['building_id'])->exists()) {
            $errors['floor_id'] = 'The selected floor does not belong to the selected building.';
        }
        if (($data['building_location_id'] ?? null) && ! BuildingLocation::whereKey($data['building_location_id'])->where('building_id', $data['building_id'])->when($data['floor_id'] ?? null, fn ($query) => $query->where('floor_id', $data['floor_id']))->exists()) {
            $errors['building_location_id'] = 'The selected location does not belong to the selected building and floor.';
        }
        if (($data['preferred_fmo_personnel_id'] ?? null) && ! FmoPersonnel::with('user')->findOrFail($data['preferred_fmo_personnel_id'])->isAssignable()) {
            $errors['preferred_fmo_personnel_id'] = 'The selected personnel member is not currently available.';
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
            $path = $file->store('work-orders/'.$workOrder->id, 'local');
            $workOrder->attachments()->create(['uploaded_by' => $request->user()->id, 'purpose' => 'REQUEST_INITIAL', 'original_filename' => $file->getClientOriginalName(), 'stored_path' => $path, 'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize()]);
        }
    }

    private function formData(): array
    {
        return ['categories' => WorkOrderCategory::where('is_active', true)->orderBy('display_order')->get(), 'campuses' => Campus::where('is_active', true)->get(), 'buildings' => Building::where('is_active', true)->with('campus')->get(), 'floors' => Floor::where('is_active', true)->get(), 'locations' => BuildingLocation::where('is_active', true)->get(), 'personnel' => FmoPersonnel::with('user', 'skills')->where('personnel_status', 'ACTIVE')->get()->filter->isAssignable()];
    }
}
