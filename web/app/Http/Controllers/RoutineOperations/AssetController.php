<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Http\Controllers\Controller;
use App\Models\AssetType;
use App\Models\Building;
use App\Models\BuildingLocation;
use App\Models\Campus;
use App\Models\Floor;
use App\Models\InspectableAsset;
use App\Services\AssetLifecycleService;
use App\Services\OperationalEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssetController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('assets.view'), 403);
        $assets = InspectableAsset::with(['assetType', 'campus', 'building', 'floor', 'location'])
            ->when($request->filled('type'), fn ($q) => $q->where('asset_type_id', $request->string('type')))
            ->when($request->filled('campus'), fn ($q) => $q->where('campus_id', $request->string('campus')))
            ->when($request->filled('building'), fn ($q) => $q->where('building_id', $request->string('building')))
            ->when($request->filled('condition'), fn ($q) => $q->where('condition', $request->string('condition')))
            ->when($request->filled('lifecycle'), fn ($q) => $q->where('lifecycle_status', $request->string('lifecycle')))
            ->when($request->boolean('pending'), fn ($q) => $q->whereHas('actionRequests', fn ($actions) => $actions->where('status', 'PENDING')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($inner) => $inner
                ->where('asset_code', 'like', '%'.$request->string('q').'%')->orWhere('name', 'like', '%'.$request->string('q').'%')
                ->orWhere('description', 'like', '%'.$request->string('q').'%')->orWhere('serial_number', 'like', '%'.$request->string('q').'%')
                ->orWhere('exact_placement', 'like', '%'.$request->string('q').'%')->orWhereHas('assetType', fn ($type) => $type->where('name', 'like', '%'.$request->string('q').'%'))
                ->orWhereHas('building', fn ($building) => $building->where('name', 'like', '%'.$request->string('q').'%'))))
            ->orderBy('asset_code')->paginate(25)->withQueryString();
        $assetTypes = AssetType::orderBy('name')->get();
        $campuses = Campus::where('is_active', true)->orderBy('name')->get();
        $buildings = Building::where('is_active', true)->orderBy('name')->get();

        return view('routine-operations.assets.index', compact('assets', 'assetTypes', 'campuses', 'buildings'));
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('assets.manage'), 403);

        return view('routine-operations.assets.form', $this->formData(new InspectableAsset));
    }

    public function store(Request $request, OperationalEvidenceService $evidence, AssetLifecycleService $lifecycle)
    {
        abort_unless($request->user()->can('assets.manage'), 403);
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $request, $evidence, $lifecycle, &$asset): void {
            $asset = InspectableAsset::create(collect($data)->except(['asset_photos', 'placement_photos'])->all());
            if (! empty($data['asset_photos'])) {
                $evidence->attach($asset, $data['asset_photos'], $request->user()->id, 'ASSET_PHOTO');
            }
            if (! empty($data['placement_photos'])) {
                $evidence->attach($asset, $data['placement_photos'], $request->user()->id, 'PLACEMENT_PHOTO');
            }
            $lifecycle->event($asset, $request->user(), 'ASSET_REGISTERED');
        });

        return redirect()->route('assets.show', $asset)->with('status', 'Inspectable asset added.');
    }

    public function show(Request $request, InspectableAsset $asset)
    {
        abort_unless($request->user()->can('assets.view'), 403);
        $asset->load(['assetType', 'campus', 'building', 'floor', 'location', 'attachments.uploader',
            'actionRequests.requester', 'actionRequests.reviewer', 'actionRequests.attachments',
            'maintenanceRecords.recorder', 'maintenanceRecords.attachments', 'lifecycleEvents.actor']);
        $history = $asset->inspectionItems()->with(['run.template', 'inspector.user', 'attachments'])->where('status', 'INSPECTED')->latest('inspected_at')->paginate(15);
        $pendingAction = $asset->actionRequests->where('status', 'PENDING')->sortByDesc('requested_at')->first();

        return view('routine-operations.assets.show', compact('asset', 'history', 'pendingAction'));
    }

    public function edit(Request $request, InspectableAsset $asset)
    {
        abort_unless($request->user()->can('assets.manage'), 403);

        return view('routine-operations.assets.form', $this->formData($asset));
    }

    public function update(Request $request, InspectableAsset $asset, OperationalEvidenceService $evidence)
    {
        abort_unless($request->user()->can('assets.manage'), 403);
        $data = $this->validated($request, $asset);
        DB::transaction(function () use ($asset, $data, $request, $evidence): void {
            $asset->update(collect($data)->except(['asset_photos', 'placement_photos'])->all());
            if (! empty($data['asset_photos'])) {
                $evidence->attach($asset, $data['asset_photos'], $request->user()->id, 'ASSET_PHOTO');
            }
            if (! empty($data['placement_photos'])) {
                $evidence->attach($asset, $data['placement_photos'], $request->user()->id, 'PLACEMENT_PHOTO');
            }
        });

        return redirect()->route('assets.show', $asset)->with('status', 'Asset registry entry updated. Inspection history was preserved.');
    }

    private function validated(Request $request, ?InspectableAsset $asset = null): array
    {
        $data = $request->validate([
            'asset_code' => ['required', 'string', 'max:40', Rule::unique('inspectable_assets')->ignore($asset)],
            'asset_type_id' => ['required', Rule::exists('asset_types', 'id')->where(fn ($query) => $query->where('is_active', true)->when($asset, fn ($types) => $types->orWhere('id', $asset->asset_type_id)))], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'campus_id' => ['required', 'exists:campuses,id'], 'building_id' => ['required', 'exists:buildings,id'], 'floor_id' => ['nullable', 'exists:floors,id'],
            'building_location_id' => ['nullable', 'exists:building_locations,id'], 'exact_placement' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'], 'model' => ['nullable', 'string', 'max:255'], 'serial_number' => ['nullable', 'string', 'max:255'],
            'installed_at' => ['nullable', 'date'], 'inspection_frequency' => ['required', Rule::in(['DAILY', 'WEEKLY', 'MONTHLY'])],
            'condition' => ['required', Rule::in(['PRISTINE', 'GOOD', 'FAIR', 'NEEDS_REPAIR', 'DAMAGED', 'BROKEN', 'UNUSABLE'])],
            'lifecycle_status' => ['required', Rule::in(['ACTIVE', 'UNDER_REPAIR', 'FOR_DISPOSAL_REVIEW', 'APPROVED_FOR_DISPOSAL', 'DISPOSED', 'RETIRED'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'asset_photos' => ['nullable', 'array', 'max:'.config('work_orders.attachments.max_count')],
            'asset_photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')],
            'placement_photos' => ['nullable', 'array', 'max:'.config('work_orders.attachments.max_count')],
            'placement_photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')],
        ]);
        $errors = [];
        if (! Building::whereKey($data['building_id'])->where('campus_id', $data['campus_id'])->where('is_active', true)->exists()) {
            $errors['building_id'] = 'The selected building does not belong to the selected campus.';
        }
        if (($data['floor_id'] ?? null) && ! Floor::whereKey($data['floor_id'])->where('building_id', $data['building_id'])->where('is_active', true)->exists()) {
            $errors['floor_id'] = 'The selected floor does not belong to the selected building.';
        }
        if (($data['building_location_id'] ?? null) && ! BuildingLocation::whereKey($data['building_location_id'])->where('building_id', $data['building_id'])->where('is_active', true)->where(fn ($query) => ($data['floor_id'] ?? null) ? $query->whereNull('floor_id')->orWhere('floor_id', $data['floor_id']) : $query->whereNull('floor_id'))->exists()) {
            $errors['building_location_id'] = 'The selected area does not belong to the selected building and floor.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $data['status'] = in_array($data['lifecycle_status'], ['ACTIVE', 'UNDER_REPAIR'], true) ? 'ACTIVE' : ($data['lifecycle_status'] === 'RETIRED' ? 'RETIRED' : 'OUT_OF_SERVICE');

        return $data;
    }

    private function formData(InspectableAsset $asset): array
    {
        return ['asset' => $asset, 'assetTypes' => AssetType::where('is_active', true)->when($asset->exists, fn ($query) => $query->orWhereKey($asset->asset_type_id))->orderBy('name')->get(),
            'campuses' => Campus::where('is_active', true)->orderBy('name')->get()];
    }
}
