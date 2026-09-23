<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RegistrationReviewController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('users.view_pending_registrations'), 403);

        return view('admin.registrations.index', [
            'users' => User::whereIn('status', [AccountStatus::Pending, AccountStatus::NeedsCorrection])
                ->latest()->paginate(20),
        ]);
    }

    public function show(Request $request, User $user): View
    {
        abort_unless($request->user()->can('users.view_pending_registrations'), 403);

        return view('admin.registrations.show', [
            'applicant' => $user,
            'reviews' => $user->reviews()->with('reviewer')->latest()->get(),
        ]);
    }

    public function review(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'request_correction'])],
            'reason' => ['nullable', 'string', 'max:2000', 'required_if:action,reject,request_correction'],
        ]);

        $permission = match ($data['action']) {
            'approve' => 'users.approve_registration',
            'reject' => 'users.reject_registration',
            'request_correction' => 'users.request_registration_correction',
        };
        abort_unless($request->user()->can($permission), 403);

        DB::transaction(function () use ($request, $user, $data): void {
            $applicant = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($applicant->status === AccountStatus::Pending, 409);

            $next = match ($data['action']) {
                'approve' => AccountStatus::Approved,
                'reject' => AccountStatus::Rejected,
                'request_correction' => AccountStatus::NeedsCorrection,
            };
            $applicant->status = $next;
            if ($next === AccountStatus::Approved) {
                $applicant->approved_at = now();
                $applicant->approved_by = $request->user()->id;
                if ($applicant->roles()->count() === 0) {
                    $applicant->assignRole('Requester');
                }
            }
            $applicant->save();
            $applicant->reviews()->create([
                'reviewer_id' => $request->user()->id,
                'action' => match ($data['action']) {
                    'request_correction' => 'correction_requested',
                    'approve' => 'approved',
                    'reject' => 'rejected',
                },
                'previous_status' => AccountStatus::Pending->value,
                'new_status' => $next->value,
                'reason' => $data['reason'] ?? null,
            ]);
        });

        return redirect()->route('registrations.show', $user)->with('success', 'Registration reviewed.');
    }
}
