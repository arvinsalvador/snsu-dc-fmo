<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountProfileService;
use App\Support\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function __construct(private readonly AccountProfileService $profiles) {}

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

    /** The signed-in user's account profile. No password or hash is ever included. */
    public function account(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user()->load('requesterProfile', 'personnelProfile.skills'))]);
    }

    public function updateAccount(Request $request): JsonResponse
    {
        $request->merge(AccountProfileService::normalize($request->only(['name'])));
        $data = $request->validate($this->profiles->personalRules($request->user()));
        $this->profiles->updatePersonal($request->user(), $request->user(), $data);

        return response()->json(['data' => $this->present($request->user()->fresh(['requesterProfile', 'personnelProfile.skills']))]);
    }

    public function storePhoto(Request $request): JsonResponse
    {
        $data = $request->validate($this->profiles->photoRules());
        $this->profiles->replacePhoto($request->user(), $request->user(), $data['photo']);

        return response()->json(['data' => $this->present($request->user()->fresh(['requesterProfile', 'personnelProfile.skills']))]);
    }

    public function destroyPhoto(Request $request): JsonResponse
    {
        $this->profiles->removePhoto($request->user(), $request->user());

        return response()->json(['data' => $this->present($request->user()->fresh(['requesterProfile', 'personnelProfile.skills']))]);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => [...PasswordPolicy::rules(), 'different:current_password'],
        ]);
        $this->profiles->changeOwnPassword($request->user(), $data['password'], $request->user()->currentAccessToken()?->id);

        return response()->json(['message' => 'Password updated successfully.']);
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'user_type' => $user->user_type,
            'status' => $user->status->value, 'roles' => $user->getRoleNames()->values(),
            'avatar_url' => $user->avatarUrl(true), 'initials' => $user->initials(),
            'contact_number' => $user->personnelProfile?->contact_number ?? $user->requesterProfile?->contact_number,
            'personnel' => $user->personnelProfile ? [
                'designation' => $user->personnelProfile->designation, 'status' => $user->personnelProfile->personnel_status->value,
                'skills' => $user->personnelProfile->skills->pluck('name')->values(),
            ] : null,
        ];
    }
}
