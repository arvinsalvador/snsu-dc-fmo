@extends('layouts.app')
@section('title', 'Role details')
@section('content')
<a class="text-sm text-blue-700 underline" href="{{ route('roles.index') }}">← Roles</a><h1 class="mt-3 text-2xl font-semibold">{{ $role->name }}</h1>
<p class="mt-2 text-sm text-slate-600">{{ in_array($role->name, config('authorization.core_roles'), true) ? 'Core role: changes are managed through the authorization seeder.' : 'Custom role' }}</p>
<div class="mt-6 grid gap-6 md:grid-cols-2"><div class="rounded border bg-white p-5"><h2 class="mb-3 text-lg font-semibold">Permissions</h2>
@if (! in_array($role->name, config('authorization.core_roles'), true) && auth()->user()->can('roles.update'))
<form method="POST" action="{{ route('roles.update', $role) }}">@csrf @method('PUT')<label class="block">Name<input class="mt-1 w-full rounded border p-2" name="name" value="{{ $role->name }}" required></label><div class="mt-4 max-h-64 space-y-2 overflow-y-auto">@foreach ($permissions as $permission)<label class="block text-sm"><input type="checkbox" name="permissions[]" value="{{ $permission->name }}" @checked($role->hasPermissionTo($permission->name))> {{ $permission->name }}</label>@endforeach</div><button class="mt-4 rounded bg-blue-700 px-4 py-2 text-white">Save role</button></form>
@else <p>{{ $role->permissions->pluck('name')->join(', ') ?: 'No permissions' }}</p> @endif
@if (! in_array($role->name, config('authorization.core_roles'), true) && auth()->user()->can('roles.delete') && $users->total() === 0)
<form method="POST" action="{{ route('roles.destroy', $role) }}" class="mt-4">@csrf @method('DELETE')<button class="text-red-700 underline" onclick="return confirm('Delete this unassigned role?')">Delete role</button></form>
@endif
</div><div class="rounded border bg-white p-5"><h2 class="mb-3 text-lg font-semibold">Assigned users</h2><ul class="space-y-2">@forelse ($users as $user)<li>{{ $user->name }}</li>@empty <li>No assigned users.</li>@endforelse</ul><div class="mt-4">{{ $users->links() }}</div></div></div>
@endsection
