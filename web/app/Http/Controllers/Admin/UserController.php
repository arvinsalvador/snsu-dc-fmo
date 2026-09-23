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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
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
            'managedUser' => $user->load('roles', 'permissions'),
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
            $managed = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($managed->status, [
                AccountStatus::Approved, AccountStatus::Suspended, AccountStatus::Deactivated,
            ], true), 409);
            abort_if($managed->hasRole('System Administrator') &&
                $data['status'] !== AccountStatus::Approved->value &&
                User::role('System Administrator')->where('status', AccountStatus::Approved->value)->count() <= 1, 409);

            $previous = $managed->status->value;
            $managed->status = AccountStatus::from($data['status']);
            $managed->save();
            if (! $managed->isApproved()) {
                $managed->tokens()->delete();
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
        abort_if($user->hasRole('System Administrator') &&
            ! in_array('System Administrator', $names, true) &&
            User::role('System Administrator')->where('status', AccountStatus::Approved->value)->count() <= 1, 409);
        $user->syncRoles($names);

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
}
