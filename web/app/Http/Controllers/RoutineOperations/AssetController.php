<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Http\Controllers\Controller;
use App\Models\AssetType;
use App\Models\Building;
use App\Models\BuildingLocation;
use App\Models\Campus;
use App\Models\Floor;
use App\Models\InspectableAsset;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssetController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('assets.view'), 403);
        $assets = InspectableAsset::with(['assetType', 'campus', 'building', 'floor', 'location'])
            ->when($request->filled('type'), fn ($q) => $q->where('asset_type_id', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($inner) => $inner->where('asset_code', 'like', '%'.$request->string('q').'%')->orWhere('name', 'like', '%'.$request->string('q').'%')))
            ->orderBy('asset_code')->paginate(25)->withQueryString();
        $assetTypes = AssetType::orderBy('name')->get();

        return view('routine-operations.assets.index', compact('assets', 'assetTypes'));
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('assets.manage'), 403);

        return view('routine-operations.assets.form', $this->formData(new InspectableAsset));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('assets.manage'), 403);
        $asset = InspectableAsset::create($this->validated($request));

        return redirect()->route('assets.show', $asset)->with('status', 'Inspectable asset added.');
    }

    public function show(Request $request, InspectableAsset $asset)
    {
        abort_unless($request->user()->can('assets.view'), 403);
        $asset->load(['assetType', 'campus', 'building', 'floor', 'location']);
        $history = $asset->inspectionItems()->with(['run.template', 'inspector.user', 'attachments'])->where('status', 'INSPECTED')->latest('inspected_at')->paginate(15);

        return view('routine-operations.assets.show', compact('asset', 'history'));
    }

    public function edit(Request $request, InspectableAsset $asset)
    {
        abort_unless($request->user()->can('assets.manage'), 403);

        return view('routine-operations.assets.form', $this->formData($asset));
    }

    public function update(Request $request, InspectableAsset $asset)
    {
        abort_unless($request->user()->can('assets.manage'), 403);
        $asset->update($this->validated($request, $asset));

        return redirect()->route('assets.show', $asset)->with('status', 'Asset registry entry updated. Inspection history was preserved.');
    }

    private function validated(Request $request, ?InspectableAsset $asset = null): array
    {
        $data = $request->validate([
            'asset_code' => ['required', 'string', 'max:40', Rule::unique('inspectable_assets')->ignore($asset)],
            'asset_type_id' => ['required', 'exists:asset_types,id'], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'campus_id' => ['required', 'exists:campuses,id'], 'building_id' => ['required', 'exists:buildings,id'], 'floor_id' => ['nullable', 'exists:floors,id'],
            'building_location_id' => ['nullable', 'exists:building_locations,id'], 'exact_placement' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'], 'model' => ['nullable', 'string', 'max:255'], 'serial_number' => ['nullable', 'string', 'max:255'],
            'installed_at' => ['nullable', 'date'], 'inspection_frequency' => ['required', Rule::in(['DAILY', 'WEEKLY', 'MONTHLY'])],
            'status' => ['required', Rule::in(['ACTIVE', 'OUT_OF_SERVICE', 'RETIRED', 'MISSING'])], 'notes' => ['nullable', 'string', 'max:5000'],
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

        return $data;
    }

    private function formData(InspectableAsset $asset): array
    {
        return ['asset' => $asset, 'assetTypes' => AssetType::where('is_active', true)->orderBy('name')->get(),
            'campuses' => Campus::where('is_active', true)->with(['buildings' => fn ($q) => $q->where('is_active', true)->with(['floors' => fn ($f) => $f->where('is_active', true), 'locations' => fn ($l) => $l->where('is_active', true)])])->orderBy('name')->get()];
    }
}
