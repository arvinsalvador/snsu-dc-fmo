<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PersonnelResource;
use App\Models\FmoPersonnel;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonnelController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('personnel.view'), 403);
        $query = FmoPersonnel::with(['user', 'skills']);
        if ($request->filled('skill')) {
            $query->whereHas('skills', fn ($q) => $q->whereKey($request->integer('skill')));
        }
        if ($request->filled('status')) {
            $query->where('personnel_status', $request->string('status')->value());
        }

        return PersonnelResource::collection($query->paginate(20));
    }

    public function show(Request $request, FmoPersonnel $personnel): PersonnelResource
    {
        abort_unless($request->user()->can('personnel.view') || $personnel->user_id === $request->user()->id, 403);

        return new PersonnelResource($personnel->load(['user', 'skills']));
    }

    public function skills(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('skills.view'), 403);

        return response()->json(['data' => Skill::where('is_active', true)->orderBy('name')->get(['id', 'name', 'description'])]);
    }
}
