<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RegistrationStatusController extends Controller
{
    public function show(Request $request): View
    {
        return view('auth.status', [
            'user' => $request->user(),
            'reviews' => $request->user()->reviews()->with('reviewer')->latest()->get(),
        ]);
    }

    public function resubmit(Request $request): RedirectResponse
    {
        abort_unless($request->user()->status === AccountStatus::NeedsCorrection, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($request->user()->id)],
            'user_type' => ['required', Rule::in(['student', 'faculty', 'staff'])],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->status === AccountStatus::NeedsCorrection, 409);
            $user->update($data);
            $user->status = AccountStatus::Pending;
            $user->save();
            $user->reviews()->create([
                'action' => 'resubmitted',
                'previous_status' => AccountStatus::NeedsCorrection->value,
                'new_status' => AccountStatus::Pending->value,
            ]);
        });

        return redirect()->route('registration.status')->with('success', 'Your registration was resubmitted.');
    }
}
