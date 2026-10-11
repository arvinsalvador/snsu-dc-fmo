@extends('layouts.app')
@section('title', 'Roles & Permissions')
@section('content')
    <x-page-header title="Roles & Permissions" description="Review access roles and create narrowly scoped custom roles when required." />
    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_26rem]">
        <section class="section-card"><h2 class="text-lg">Existing Roles</h2><div class="mt-4 divide-y divide-slate-100">@forelse ($roles as $role)<a class="flex items-center justify-between gap-4 py-4 hover:text-blue-800" href="{{ route('roles.show', $role) }}"><div><p class="font-semibold">{{ $role->name }}</p><p class="mt-1 text-sm text-slate-500">{{ $role->users_count }} {{ \Illuminate\Support\Str::plural('user', $role->users_count) }}</p></div><x-icon name="chevron-right" class="text-slate-400" /></a>@empty<x-empty-state title="No roles configured" />@endforelse</div></section>
        @can('roles.create')
            @php($permissionGroups = $permissions->groupBy(fn ($permission) => str($permission->name)->before('.')->replace('_', ' ')->title()->toString()))
            <form method="POST" action="{{ route('roles.store') }}" class="section-card self-start">@csrf<h2 class="text-lg">Create Custom Role</h2><label class="mt-4 block">Role name <span class="text-rose-600">*</span><input name="name" value="{{ old('name') }}" required maxlength="100"></label><fieldset class="mt-5"><legend class="font-semibold text-slate-900">Permissions</legend><div class="mt-3 max-h-[32rem] space-y-4 overflow-y-auto pr-1">@foreach ($permissionGroups as $module => $group)<div class="permission-group"><p class="text-sm font-semibold text-slate-900">{{ $module }}</p>@foreach ($group as $permission)<label class="permission-option"><input type="checkbox" name="permissions[]" value="{{ $permission->name }}"><span>{{ str($permission->name)->after('.')->replace('_', ' ')->title() }}</span></label>@endforeach</div>@endforeach</div></fieldset><button class="btn btn-primary mt-5" type="submit"><x-icon name="plus" />Create role</button></form>
        @endcan
    </div>
@endsection
