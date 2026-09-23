<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('profiles.view_own'), 403);
        $profile = $request->user()->load('requesterProfile')->requesterProfile;

        return response()->json(['data' => $profile]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('profiles.update_own'), 403);
        $data = $request->validate([
            'institutional_id' => ['nullable', 'string', 'max:100'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'department' => ['nullable', 'string', 'max:255', Rule::requiredIf($request->user()->user_type === 'faculty')],
            'college' => ['nullable', 'string', 'max:255'],
            'program' => ['nullable', 'string', 'max:255', Rule::requiredIf($request->user()->user_type === 'student')],
            'year_level' => ['nullable', 'string', 'max:30'],
            'organizational_office' => ['nullable', 'string', 'max:255', Rule::requiredIf($request->user()->user_type === 'staff')],
        ]);
        $profile = $request->user()->requesterProfile()->updateOrCreate([], $data);

        return response()->json(['data' => $profile]);
    }
}
