<?php

namespace App\Http\Controllers\Locations;

use App\Enums\LocationType;
use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\BuildingLocation;
use App\Models\Campus;
use App\Models\Floor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationManagementController extends Controller
{
    public function campuses(Request $r)
    {
        abort_unless($r->user()->can('campuses.view'), 403);

        return view('locations.campuses', ['campuses' => Campus::withCount('buildings')->when($r->search, fn ($q, $v) => $q->where('name', 'like', "%$v%")->orWhere('code', 'like', "%$v%"))->paginate(20)]);
    }

    public function campusStore(Request $r)
    {
        abort_unless($r->user()->can('campuses.create'), 403);
        Campus::create($this->campusData($r));

        return back()->with('success', 'Campus saved.');
    }

    public function campusUpdate(Request $r, Campus $campus)
    {
        abort_unless($r->user()->can('campuses.update'), 403);
        $campus->update($this->campusData($r, $campus));

        return back()->with('success', 'Campus updated.');
    }

    public function campusDelete(Request $r, Campus $campus)
    {
        abort_unless($r->user()->can('campuses.delete'), 403);
        abort_if($campus->buildings()->exists(), 409, 'Deactivate a campus with buildings.');
        $campus->delete();

        return back()->with('success', 'Unused campus deleted.');
    }

    public function buildings(Request $r)
    {
        abort_unless($r->user()->can('buildings.view'), 403);
        $q = Building::with('campus')->when($r->campus_id, fn ($q, $v) => $q->where('campus_id', $v))->when($r->search, fn ($q, $v) => $q->where(fn ($x) => $x->where('name', 'like', "%$v%")->orWhere('code', 'like', "%$v%")))->when($r->filled('active'), fn ($q) => $q->where('is_active', $r->boolean('active')));

        return view('locations.buildings', ['buildings' => $q->paginate(20)->withQueryString(), 'campuses' => Campus::orderBy('name')->get()]);
    }

    public function buildingStore(Request $r)
    {
        abort_unless($r->user()->can('buildings.create'), 403);
        Building::create($this->buildingData($r));

        return back()->with('success', 'Building saved.');
    }

    public function buildingUpdate(Request $r, Building $building)
    {
        abort_unless($r->user()->can('buildings.update'), 403);
        $building->update($this->buildingData($r, $building));

        return back()->with('success', 'Building updated.');
    }

    public function buildingDelete(Request $r, Building $building)
    {
        abort_unless($r->user()->can('buildings.delete'), 403);
        abort_if($building->floors()->exists() || $building->locations()->exists(), 409, 'Deactivate a building with child records.');
        $building->delete();

        return back()->with('success', 'Unused building deleted.');
    }

    public function buildingShow(Request $r, Building $building)
    {
        abort_unless($r->user()->can('buildings.view'), 403);

        return view('locations.building-show', ['building' => $building->load('campus', 'floors', 'locations.floor')]);
    }

    public function floorStore(Request $r, Building $building)
    {
        abort_unless($r->user()->can('locations.create'), 403);
        $data = $r->validate(['name' => ['required', 'max:255', Rule::unique('floors', 'name')->where('building_id', $building->id)], 'code' => ['nullable', 'max:50'], 'display_order' => ['nullable', 'integer', 'min:0'], 'description' => ['nullable'], 'is_active' => ['required', 'boolean']]);
        $building->floors()->create($data);

        return back()->with('success', 'Floor saved.');
    }

    public function locationStore(Request $r)
    {
        abort_unless($r->user()->can('locations.create'), 403);
        $data = $this->locationData($r);
        BuildingLocation::create($data);

        return back()->with('success', 'Location saved.');
    }

    public function locationUpdate(Request $r, BuildingLocation $location)
    {
        abort_unless($r->user()->can('locations.update'), 403);
        $location->update($this->locationData($r, $location));

        return back()->with('success', 'Location updated.');
    }

    public function locationDelete(Request $r, BuildingLocation $location)
    {
        abort_unless($r->user()->can('locations.delete'), 403);
        $location->delete();

        return back()->with('success', 'Unused location deleted.');
    }

    public function locations(Request $r)
    {
        abort_unless($r->user()->can('locations.view'), 403);
        $q = BuildingLocation::with('building.campus', 'floor')->when($r->campus_id, fn ($q, $v) => $q->whereHas('building', fn ($b) => $b->where('campus_id', $v)))->when($r->building_id, fn ($q, $v) => $q->where('building_id', $v))->when($r->floor_id, fn ($q, $v) => $q->where('floor_id', $v))->when($r->type, fn ($q, $v) => $q->where('type', $v))->when($r->filled('active'), fn ($q) => $q->where('is_active', $r->boolean('active')))->when($r->search, fn ($q, $v) => $q->where(fn ($x) => $x->where('name', 'like', "%$v%")->orWhere('code', 'like', "%$v%")));

        return view('locations.locations', ['locations' => $q->paginate(20)->withQueryString(), 'buildings' => Building::with('campus')->get(), 'floors' => Floor::all(), 'types' => LocationType::cases()]);
    }

    private function campusData(Request $r, ?Campus $c = null): array
    {
        return $r->validate(['code' => ['required', 'max:50', Rule::unique('campuses', 'code')->ignore($c?->id)], 'name' => ['required', 'max:255', Rule::unique('campuses', 'name')->ignore($c?->id)], 'short_name' => ['nullable', 'max:100'], 'description' => ['nullable'], 'address' => ['nullable', 'max:255'], 'is_active' => ['required', 'boolean']]);
    }

    private function buildingData(Request $r, ?Building $b = null): array
    {
        return $r->validate(['campus_id' => ['required', Rule::exists('campuses', 'id')], 'code' => ['nullable', 'max:50', Rule::unique('buildings', 'code')->where('campus_id', $r->campus_id)->ignore($b?->id)], 'name' => ['required', 'max:255', Rule::unique('buildings', 'name')->where('campus_id', $r->campus_id)->ignore($b?->id)], 'description' => ['nullable'], 'notes' => ['nullable'], 'is_active' => ['required', 'boolean']]);
    }

    private function locationData(Request $r, ?BuildingLocation $l = null): array
    {
        $d = $r->validate(['building_id' => ['required', Rule::exists('buildings', 'id')], 'floor_id' => ['nullable', Rule::exists('floors', 'id')], 'type' => ['required', Rule::enum(LocationType::class)], 'code' => ['nullable', 'max:100'], 'name' => ['required', 'max:255'], 'description' => ['nullable'], 'is_active' => ['required', 'boolean']]);
        if ($d['floor_id'] ?? null) {
            abort_unless(Floor::whereKey($d['floor_id'])->where('building_id',$d['building_id'])->exists(),422,'Floor must belong to the selected building.');
        }

return $d;
    }
}
