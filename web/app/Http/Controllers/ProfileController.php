<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        abort_unless($request->user()->can('profiles.view_own'), 403);

        return view('profile.show', ['user' => $request->user()->load('requesterProfile', 'personnelProfile.skills')]);
    }

    public function update(Request $request): RedirectResponse
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

        $request->user()->requesterProfile()->updateOrCreate([], $data);

        return back()->with('success', 'Profile updated.');
    }
}
