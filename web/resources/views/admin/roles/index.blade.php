@extends('layouts.app')
@section('title', 'Roles')
@section('content')
<h1 class="mb-5 text-2xl font-semibold">Roles</h1>
<div class="grid gap-6 md:grid-cols-2"><div class="rounded border bg-white p-5"><h2 class="mb-3 text-lg font-semibold">Existing roles</h2><ul class="space-y-2">@foreach ($roles as $role)<li><a class="text-blue-700 underline" href="{{ route('roles.show', $role) }}">{{ $role->name }}</a> <span class="text-sm text-slate-500">({{ $role->users_count }} users)</span></li>@endforeach</ul></div>
@can('roles.create')
<form method="POST" action="{{ route('roles.store') }}" class="rounded border bg-white p-5">@csrf<h2 class="mb-3 text-lg font-semibold">Create custom role</h2><label class="block">Name<input class="mt-1 w-full rounded border p-2" name="name" required maxlength="100"></label><fieldset class="mt-4"><legend class="mb-2 font-medium">Permissions</legend><div class="max-h-64 space-y-2 overflow-y-auto">@foreach ($permissions as $permission)<label class="block text-sm"><input type="checkbox" name="permissions[]" value="{{ $permission->name }}"> {{ $permission->name }}</label>@endforeach</div></fieldset><button class="mt-4 rounded bg-blue-700 px-4 py-2 text-white">Create role</button></form>
@endcan</div>
@endsection
