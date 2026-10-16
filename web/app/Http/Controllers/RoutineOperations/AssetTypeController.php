<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Http\Controllers\Controller;
use App\Models\AssetType;
use App\Models\InspectableAsset;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetTypeController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('asset_types.manage'), 403);
        $types = AssetType::query()->withCount('assets')
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($search) => $search
                ->where('name', 'like', '%'.$request->string('q').'%')
                ->orWhere('code', 'like', '%'.$request->string('q').'%')
                ->orWhere('description', 'like', '%'.$request->string('q').'%')))
            ->when($request->string('status')->value() === 'ACTIVE', fn ($query) => $query->where('is_active', true))
            ->when($request->string('status')->value() === 'INACTIVE', fn ($query) => $query->where('is_active', false))
            ->when($request->filled('frequency'), fn ($query) => $query->where('default_inspection_frequency', $request->string('frequency')))
            ->orderBy('name')->paginate(25)->withQueryString();
        $summary = [
            'types' => AssetType::count(),
            'active' => AssetType::where('is_active', true)->count(),
            'assets' => InspectableAsset::count(),
        ];

        return view('routine-operations.asset-types.index', compact('types', 'summary'));
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('asset_types.manage'), 403);

        return view('routine-operations.asset-types.form', ['type' => new AssetType]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('asset_types.manage'), 403);
        AssetType::create($this->validated($request));

        return redirect()->route('asset-types.index')->with('status', 'Asset type created.');
    }

    public function edit(Request $request, AssetType $assetType)
    {
        abort_unless($request->user()->can('asset_types.manage'), 403);

        return view('routine-operations.asset-types.form', ['type' => $assetType]);
    }

    public function update(Request $request, AssetType $assetType)
    {
        abort_unless($request->user()->can('asset_types.manage'), 403);
        $assetType->update($this->validated($request, $assetType));

        return redirect()->route('asset-types.index')->with('status', 'Asset type updated.');
    }

    public function deactivate(Request $request, AssetType $assetType)
    {
        abort_unless($request->user()->can('asset_types.manage'), 403);
        $assetType->update(['is_active' => false]);

        return back()->with('status', 'Asset type deactivated. Existing assets remain available in history.');
    }

    private function validated(Request $request, ?AssetType $type = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('asset_types')->ignore($type)],
            'code' => ['required', 'alpha_dash', 'max:12', Rule::unique('asset_types')->ignore($type)],
            'description' => ['nullable', 'string', 'max:5000'],
            'default_inspection_frequency' => ['required', Rule::in(['DAILY', 'WEEKLY', 'MONTHLY'])],
            'checklist_text' => ['required', 'string', 'max:10000'], 'is_active' => ['nullable', 'boolean'],
        ]);
        $checks = collect(preg_split('/\R/', $data['checklist_text']))->map(fn ($line) => trim($line))->filter()->values();

        return collect($data)->except('checklist_text')->all() + [
            'code' => strtoupper($data['code']), 'is_active' => (bool) ($data['is_active'] ?? false),
            'checklist' => $checks->map(fn (string $label, int $index) => ['code' => 'CHECK_'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 'label' => $label])->all(),
            'result_options' => ['PRISTINE', 'GOOD', 'NEEDS_ATTENTION', 'NEEDS_REPAIR', 'DAMAGED', 'RECOMMEND_DISPOSAL'],
        ];
    }
}
