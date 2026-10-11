@extends('layouts.app')
@section('title', 'User Details')
@section('content')
    <x-breadcrumbs :items="['Users' => route('users.index'), $managedUser->name]" />
    <div class="flex items-center gap-4"><x-avatar :name="$managedUser->name" size="lg" /><div><h1>{{ $managedUser->name }}</h1><p class="mt-1 text-sm text-slate-600">{{ $managedUser->email }}</p></div></div>
    <section class="section-card mt-6"><div class="flex items-center justify-between gap-3"><h2 class="text-lg">Account Information</h2><x-status-badge :status="$managedUser->status" /></div><dl class="definition-grid mt-5"><div><dt>Institutional user type</dt><dd>{{ ucfirst($managedUser->user_type ?? 'Unknown') }}</dd></div><div><dt>Roles</dt><dd>{{ $managedUser->getRoleNames()->join(', ') ?: 'None assigned' }}</dd></div><div class="sm:col-span-2"><dt>Direct permissions</dt><dd>{{ $managedUser->getDirectPermissions()->pluck('name')->join(', ') ?: 'None assigned' }}</dd></div></dl></section>
    @if (auth()->id() !== $managedUser->id)
        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            @can('users.manage_status')
                <form method="POST" action="{{ route('users.status', $managedUser) }}" class="section-card">@csrf @method('PUT')<h2 class="text-lg">Account Status</h2><p class="mt-1 text-sm text-slate-500">Suspended and deactivated users cannot use approved application features.</p><label class="mt-4 block">New status<select name="status"><option value="APPROVED">Approved</option><option value="SUSPENDED">Suspended</option><option value="DEACTIVATED">Deactivated</option></select></label><label class="mt-4 block">Reason <span class="text-rose-600">*</span><textarea name="reason" required></textarea></label><button class="btn btn-primary mt-5" type="submit">Update status</button></form>
            @endcan
            @can('users.assign_roles')
                <form method="POST" action="{{ route('users.roles', $managedUser) }}" class="section-card">@csrf @method('PUT')<h2 class="text-lg">Roles</h2><p class="mt-1 text-sm text-slate-500">Roles grant bundled permissions appropriate to the user's responsibilities.</p><div class="mt-4 grid gap-2 sm:grid-cols-2">@foreach ($roles as $role)<label class="permission-option"><input type="checkbox" name="roles[]" value="{{ $role->name }}" @checked($managedUser->hasRole($role->name))><span>{{ $role->name }}</span></label>@endforeach</div><button class="btn btn-primary mt-5" type="submit">Save roles</button></form>
            @endcan
            @if (auth()->user()->can('users.assign_permissions') && (auth()->user()->can('permissions.manage') || ($managedUser->isApproved() && $managedUser->hasRole('FMO Staff'))))
                @php($permissionGroups = $permissions->groupBy(fn ($permission) => str($permission->name)->before('.')->replace('_', ' ')->title()->toString()))
                <form method="POST" action="{{ route('users.permissions', $managedUser) }}" class="section-card xl:col-span-2">@csrf @method('PUT')<h2 class="text-lg">Direct Permissions</h2><p class="mt-1 text-sm text-slate-500">Direct permissions apply only to this user and should be used sparingly.</p><div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">@foreach ($permissionGroups as $module => $group)<fieldset class="permission-group"><legend class="px-1 text-sm font-semibold text-slate-900">{{ $module }}</legend><div class="mt-2 space-y-1">@foreach ($group as $permission)<label class="permission-option"><input type="checkbox" name="permissions[]" value="{{ $permission->name }}" @checked($managedUser->hasDirectPermission($permission->name))><span>{{ str($permission->name)->after('.')->replace('_', ' ')->title() }}</span></label>@endforeach</div></fieldset>@endforeach</div><button class="btn btn-primary mt-5" type="submit">Save permissions</button></form>
            @endif
        </div>
    @endif
@endsection
