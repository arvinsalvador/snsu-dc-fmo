<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountProfileService;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(private readonly AccountProfileService $profiles) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->can('users.view'), 403);
        $search = trim((string) $request->query('search', ''));

        return view('admin.users.index', [
            'users' => User::query()->when($search, fn ($query) => $query
                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")))
                ->latest()->paginate(20)->withQueryString(),
            'search' => $search,
        ]);
    }

    public function show(Request $request, User $user): View
    {
        abort_unless($request->user()->can('users.view'), 403);
        $canManageAll = $request->user()->can('permissions.manage');

        return view('admin.users.show', [
            'managedUser' => $user->load('roles', 'permissions', 'personnelProfile.skills', 'requesterProfile'),
            'auditEvents' => $user->auditEvents()->with('actor:id,name')->limit(15)->get(),
            'canResetPassword' => $this->mayManageAccount($request->user(), $user) && $request->user()->can('users.reset_password'),
            'canEditProfile' => $this->mayManageAccount($request->user(), $user) && $request->user()->can('profiles.update_others'),
            'roles' => Role::where('guard_name', 'web')->orderBy('name')->get(),
            'permissions' => Permission::whereIn('name', $canManageAll
                ? config('authorization.permissions')
                : config('authorization.delegable_permissions'))->orderBy('name')->get(),
        ]);
    }

    public function status(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('users.manage_status'), 403);
        abort_if($request->user()->is($user), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in([
                AccountStatus::Approved->value,
                AccountStatus::Suspended->value,
                AccountStatus::Deactivated->value,
            ])],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $user, $data): void {
            $approvedAdministrators = $this->approvedAdministratorCountLocked();
            $managed = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($managed->status, [
                AccountStatus::Approved, AccountStatus::Suspended, AccountStatus::Deactivated, AccountStatus::Rejected,
            ], true), 409);
            // A rejected registration can only be reconsidered and approved, not suspended or deactivated.
            if ($managed->status === AccountStatus::Rejected && $data['status'] !== AccountStatus::Approved->value) {
                throw ValidationException::withMessages(['status' => 'A rejected registration can only be approved. Choose Approved to reconsider it.']);
            }
            abort_if($managed->hasRole('System Administrator') &&
                $data['status'] !== AccountStatus::Approved->value &&
                $approvedAdministrators <= 1, 409);

            $previous = $managed->status->value;
            $managed->status = AccountStatus::from($data['status']);
            if ($previous === AccountStatus::Rejected->value) {
                $managed->approved_at = now();
                $managed->approved_by = $request->user()->id;
                if ($managed->roles()->count() === 0) {
                    $managed->assignRole('Requester');
                }
            }
            $managed->save();
            if (! $managed->isApproved()) {
                $managed->tokens()->delete();
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $managed->id)->delete();
                }
            }
            $managed->reviews()->create([
                'reviewer_id' => $request->user()->id,
                'action' => 'status_changed',
                'previous_status' => $previous,
                'new_status' => $managed->status->value,
                'reason' => $data['reason'],
            ]);
        });

        return back()->with('success', 'Account status updated.');
    }

    public function roles(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('users.assign_roles'), 403);
        abort_if($request->user()->is($user), 403);
        $data = $request->validate([
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ]);
        $names = $data['roles'] ?? [];
        DB::transaction(function () use ($user, $names): void {
            $approvedAdministrators = $this->approvedAdministratorCountLocked();
            $managed = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if($managed->isApproved() && $managed->hasRole('System Administrator') &&
                ! in_array('System Administrator', $names, true) && $approvedAdministrators <= 1, 409);
            $managed->syncRoles($names);
        });

        return back()->with('success', 'Roles updated.');
    }

    public function permissions(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('users.assign_permissions'), 403);
        abort_if($request->user()->is($user), 403);
        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(config('authorization.permissions'))],
        ]);
        $selected = $data['permissions'] ?? [];
        $canManageAll = $request->user()->can('permissions.manage');
        if (! $canManageAll) {
            abort_unless($user->hasRole('FMO Staff') && $user->isApproved(), 403);
            abort_if(array_diff($selected, config('authorization.delegable_permissions')), 403);
            $protected = $user->getDirectPermissions()->pluck('name')
                ->diff(config('authorization.delegable_permissions'))->all();
            $selected = [...$selected, ...$protected];
        }

        $user->syncPermissions($selected);

        return back()->with('success', 'Direct permissions updated.');
    }

    /** Name and email of another user. Roles, permissions and status stay in their own workflows. */
    public function profile(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('profiles.update_others') && $this->mayManageAccount($request->user(), $user), 403);
        $request->merge(AccountProfileService::normalize($request->only(['name', 'email'])));
        $data = $request->validate($this->profiles->personalRules($user, withEmail: true, withContact: false), $this->profiles->messages());
        $this->profiles->updatePersonal($user, $request->user(), $data);

        return back()->with('success', 'User profile updated.');
    }

    /** Resets another user's password. Existing passwords can never be viewed. */
    public function password(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('users.reset_password') && $this->mayManageAccount($request->user(), $user), 403);
        abort_if($request->user()->is($user), 403, 'Use Change Password on your own profile.');
        $data = $request->validate([
            'admin_password' => ['required', 'current_password'],
            'password' => PasswordPolicy::rules(),
        ]);
        $this->profiles->resetPassword($user, $request->user(), $data['password']);

        return back()->with('success', 'Password reset. '.$user->name.' was signed out and must use the new password.');
    }

    /** Only System Administrators manage other System Administrator accounts. */
    private function mayManageAccount(User $actor, User $target): bool
    {
        return ! $target->hasRole('System Administrator') || $actor->can('permissions.manage');
    }

    private function approvedAdministratorCountLocked(): int
    {
        return User::role('System Administrator')->where('status', AccountStatus::Approved->value)
            ->orderBy('users.id')->lockForUpdate()->get(['users.id'])->count();
    }
}
