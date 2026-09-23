<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\BuildingLocation;
use App\Models\Campus;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function campuses(Request $r)
    {
        abort_unless($r->user()->can('campuses.view'), 403);

        return response()->json(['data' => Campus::when($r->boolean('active'), fn ($q) => $q->where('is_active', true))->get(['id', 'code', 'name', 'is_active'])]);
    }

    public function buildings(Request $r)
    {
        abort_unless($r->user()->can('buildings.view'), 403);
        $q = Building::when($r->campus_id, fn ($q, $v) => $q->where('campus_id', $v))->when($r->boolean('active'), fn ($q) => $q->where('is_active', true)->whereHas('campus', fn ($c) => $c->where('is_active', true)));

        return response()->json(['data' => $q->get(['id', 'campus_id', 'code', 'name', 'is_active'])]);
    }

    public function locations(Request $r)
    {
        abort_unless($r->user()->can('locations.view'), 403);
        $q = BuildingLocation::with('building:id,campus_id,name,is_active', 'floor:id,name,is_active')->when($r->building_id, fn ($q, $v) => $q->where('building_id', $v))->when($r->floor_id, fn ($q, $v) => $q->where('floor_id', $v))->when($r->type, fn ($q, $v) => $q->where('type', $v))->when($r->boolean('active'), fn ($q) => $q->where('is_active', true)->whereHas('building', fn ($b) => $b->where('is_active', true)->whereHas('campus', fn ($c) => $c->where('is_active', true))));

        return response()->json(['data' => $q->get()->map(fn ($l) => ['id' => $l->id, 'building_id' => $l->building_id, 'floor_id' => $l->floor_id, 'type' => $l->type->value, 'code' => $l->code, 'name' => $l->name, 'is_active' => $l->is_active, 'effective_active' => $l->isOperational()])]);
    }
}
