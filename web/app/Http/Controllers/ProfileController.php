<?php

namespace App\Http\Controllers;

use App\Services\AccountProfileService;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(private readonly AccountProfileService $profiles) {}

    public function show(Request $request): View
    {
        $user = $request->user()->load('requesterProfile', 'personnelProfile.skills');

        return view('profile.show', [
            'user' => $user,
            'contactNumber' => $user->personnelProfile?->contact_number ?? $user->requesterProfile?->contact_number,
            'canEditInstitutional' => $user->can('profiles.update_own'),
            'maxPhotoMb' => AccountProfileService::AVATAR_MAX_KB / 1024,
        ]);
    }

    /** Institutional details used by requesters (unchanged behaviour). */
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

    /** Name and contact number only. The email address is read-only for the account owner. */
    public function updatePersonal(Request $request): RedirectResponse
    {
        $request->merge(AccountProfileService::normalize($request->only(['name'])));
        $data = $request->validate($this->profiles->personalRules($request->user()));
        $this->profiles->updatePersonal($request->user(), $request->user(), $data);

        return redirect()->route('profile.show')->with('success', 'Your profile was updated.');
    }

    public function storePhoto(Request $request): RedirectResponse
    {
        $data = $request->validate($this->profiles->photoRules());
        $this->profiles->replacePhoto($request->user(), $request->user(), $data['photo']);

        return redirect()->route('profile.show')->with('success', 'Profile picture updated.');
    }

    public function destroyPhoto(Request $request): RedirectResponse
    {
        $this->profiles->removePhoto($request->user(), $request->user());

        return redirect()->route('profile.show')->with('success', 'Profile picture removed.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => [...PasswordPolicy::rules(), 'different:current_password'],
        ]);
        $this->profiles->changeOwnPassword($request->user(), $data['password']);

        return redirect()->route('profile.show')->with('success', 'Password updated successfully.');
    }
}
