@extends('layouts.app')
@section('title', 'Role Details')
@section('content')
    @php($isCoreRole = in_array($role->name, config('authorization.core_roles'), true))
    <x-breadcrumbs :items="['Roles & Permissions' => route('roles.index'), $role->name]" />
    <x-page-header :title="$role->name" :description="$isCoreRole ? 'Core role permissions are managed by the application authorization configuration.' : 'Custom role permissions can be updated below.'" />
    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <section class="section-card">
            <h2 class="text-lg">Permissions</h2>
            @if (! $isCoreRole && auth()->user()->can('roles.update'))
                @php($permissionGroups = $permissions->groupBy(fn ($permission) => str($permission->name)->before('.')->replace('_', ' ')->title()->toString()))
                <form method="POST" action="{{ route('roles.update', $role) }}" class="mt-5">@csrf @method('PUT')<label>Role name <span class="text-rose-600">*</span><input name="name" value="{{ old('name', $role->name) }}" required></label><div class="mt-5 grid gap-4 md:grid-cols-2">@foreach ($permissionGroups as $module => $group)<fieldset class="permission-group"><legend class="px-1 text-sm font-semibold text-slate-900">{{ $module }}</legend>@foreach ($group as $permission)<label class="permission-option"><input type="checkbox" name="permissions[]" value="{{ $permission->name }}" @checked($role->hasPermissionTo($permission->name))><span>{{ str($permission->name)->after('.')->replace('_', ' ')->title() }}</span></label>@endforeach</fieldset>@endforeach</div><button class="btn btn-primary mt-5" type="submit">Save role</button></form>
            @else
                @php($groupedAssigned = $role->permissions->groupBy(fn ($permission) => str($permission->name)->before('.')->replace('_', ' ')->title()->toString()))
                <div class="mt-5 grid gap-4 md:grid-cols-2">@forelse ($groupedAssigned as $module => $group)<div class="permission-group"><h3 class="text-sm font-semibold text-slate-900">{{ $module }}</h3><ul class="mt-2 space-y-2 text-sm text-slate-600">@foreach ($group as $permission)<li class="flex gap-2"><x-icon name="check-circle" size="size-4" class="mt-0.5 text-emerald-700" /><span>{{ str($permission->name)->after('.')->replace('_', ' ')->title() }}</span></li>@endforeach</ul></div>@empty<x-empty-state title="No permissions assigned" />@endforelse</div>
            @endif
            @if (! $isCoreRole && auth()->user()->can('roles.delete') && $users->total() === 0)
                <form method="POST" action="{{ route('roles.destroy', $role) }}" class="mt-6 border-t border-slate-100 pt-5" data-confirm="Delete this unassigned custom role? This action cannot be undone.">@csrf @method('DELETE')<button class="btn btn-danger" type="submit"><x-icon name="trash" />Delete role</button></form>
            @endif
        </section>
        <section class="section-card self-start"><h2 class="text-lg">Assigned Users</h2><div class="mt-4 space-y-3">@forelse ($users as $user)<div class="flex items-center gap-3"><x-avatar :name="$user->name" size="sm" /><span class="text-sm font-medium text-slate-800">{{ $user->name }}</span></div>@empty<p class="text-sm text-slate-500">No users have this role.</p>@endforelse</div><div class="mt-5">{{ $users->links() }}</div></section>
    </div>
@endsection
