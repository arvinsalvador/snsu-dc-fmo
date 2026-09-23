<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('roles.view'), 403);

        return view('admin.roles.index', [
            'roles' => Role::where('guard_name', 'web')->withCount('users')->orderBy('name')->get(),
            'permissions' => Permission::where('guard_name', 'web')->orderBy('name')->get(),
        ]);
    }

    public function show(Request $request, Role $role): View
    {
        abort_unless($request->user()->can('roles.view'), 403);

        return view('admin.roles.show', [
            'role' => $role->load('permissions'),
            'permissions' => Permission::where('guard_name', 'web')->orderBy('name')->get(),
            'users' => $role->users()->orderBy('name')->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('roles.create') && $request->user()->can('permissions.manage'), 403);
        $data = $this->validated($request);
        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);

        return redirect()->route('roles.show', $role)->with('success', 'Role created.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_unless($request->user()->can('roles.update') && $request->user()->can('permissions.manage'), 403);
        abort_if(in_array($role->name, config('authorization.core_roles'), true), 403);
        $data = $this->validated($request, $role);
        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        abort_unless($request->user()->can('roles.delete'), 403);
        abort_if(in_array($role->name, config('authorization.core_roles'), true), 403);
        abort_if($role->users()->exists(), 409);
        $role->delete();

        return redirect()->route('roles.index')->with('success', 'Role deleted.');
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')
                ->where('guard_name', 'web')->ignore($role?->id)],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(config('authorization.permissions'))],
        ]);
    }
}
