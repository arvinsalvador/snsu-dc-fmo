@extends('layouts.app')
@section('title', 'User details')
@section('content')
<a class="text-sm text-blue-700 underline" href="{{ route('users.index') }}">← Users</a><h1 class="mt-3 text-2xl font-semibold">{{ $managedUser->name }}</h1>
<div class="mt-4 rounded-lg border bg-white p-5"><p>{{ $managedUser->email }} · {{ ucfirst($managedUser->user_type ?? 'Unknown') }}</p><p class="mt-1">Status: <strong>{{ $managedUser->status->value }}</strong></p><p class="mt-1">Roles: {{ $managedUser->getRoleNames()->join(', ') ?: 'None' }}</p><p class="mt-1">Direct permissions: {{ $managedUser->getDirectPermissions()->pluck('name')->join(', ') ?: 'None' }}</p></div>
@if (auth()->id() !== $managedUser->id)
<div class="mt-6 grid gap-5 md:grid-cols-2">
@can('users.manage_status')
<form method="POST" action="{{ route('users.status', $managedUser) }}" class="rounded border bg-white p-5">@csrf @method('PUT')<h2 class="mb-3 text-lg font-semibold">Account status</h2><select class="w-full rounded border p-2" name="status"><option value="APPROVED">Approved</option><option value="SUSPENDED">Suspended</option><option value="DEACTIVATED">Deactivated</option></select><label class="mt-3 block">Reason<textarea class="mt-1 w-full rounded border p-2" name="reason" required></textarea></label><button class="mt-3 rounded bg-blue-700 px-4 py-2 text-white">Update status</button></form>
@endcan
@can('users.assign_roles')
<form method="POST" action="{{ route('users.roles', $managedUser) }}" class="rounded border bg-white p-5">@csrf @method('PUT')<h2 class="mb-3 text-lg font-semibold">Roles</h2><div class="space-y-2">@foreach ($roles as $role)<label class="block"><input type="checkbox" name="roles[]" value="{{ $role->name }}" @checked($managedUser->hasRole($role->name))> {{ $role->name }}</label>@endforeach</div><button class="mt-4 rounded bg-blue-700 px-4 py-2 text-white">Save roles</button></form>
@endcan
@if (auth()->user()->can('users.assign_permissions') && (auth()->user()->can('permissions.manage') || ($managedUser->isApproved() && $managedUser->hasRole('FMO Staff'))))
<form method="POST" action="{{ route('users.permissions', $managedUser) }}" class="rounded border bg-white p-5">@csrf @method('PUT')<h2 class="mb-3 text-lg font-semibold">Direct permissions</h2><p class="mb-3 text-sm text-slate-600">These permissions apply only to this user. Registration approvers also need permission to view pending registrations.</p><div class="space-y-2">@foreach ($permissions as $permission)<label class="block"><input type="checkbox" name="permissions[]" value="{{ $permission->name }}" @checked($managedUser->hasDirectPermission($permission->name))> {{ $permission->name }}</label>@endforeach</div><button class="mt-4 rounded bg-blue-700 px-4 py-2 text-white">Save permissions</button></form>
@endif
</div>
@endif
@endsection
