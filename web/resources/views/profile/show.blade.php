@extends('layouts.app')
@section('title', 'My Profile')
@section('content')
<div class="max-w-2xl rounded-lg border bg-white p-6"><h1 class="text-2xl font-semibold">My Profile</h1><p class="mt-2 text-sm text-slate-600">{{ ucfirst($user->user_type) }} · {{ $user->email }}</p>
<form class="mt-5 grid gap-4 sm:grid-cols-2" method="POST" action="{{ route('profile.update') }}">@csrf @method('PUT')
<label>Institutional ID<input class="mt-1 w-full rounded border p-2" name="institutional_id" value="{{ old('institutional_id', $user->requesterProfile?->institutional_id) }}"></label>
<label>Contact number<input class="mt-1 w-full rounded border p-2" name="contact_number" value="{{ old('contact_number', $user->requesterProfile?->contact_number) }}"></label>
<label>Department<input class="mt-1 w-full rounded border p-2" name="department" value="{{ old('department', $user->requesterProfile?->department) }}"></label>
<label>College<input class="mt-1 w-full rounded border p-2" name="college" value="{{ old('college', $user->requesterProfile?->college) }}"></label>
<label>Program<input class="mt-1 w-full rounded border p-2" name="program" value="{{ old('program', $user->requesterProfile?->program) }}"></label>
<label>Year level<input class="mt-1 w-full rounded border p-2" name="year_level" value="{{ old('year_level', $user->requesterProfile?->year_level) }}"></label>
<label class="sm:col-span-2">Organizational office<input class="mt-1 w-full rounded border p-2" name="organizational_office" value="{{ old('organizational_office', $user->requesterProfile?->organizational_office) }}"></label>
<div class="sm:col-span-2"><button class="rounded bg-blue-700 px-4 py-2 text-white">Save profile</button></div></form>
@if($user->personnelProfile)<div class="mt-6 border-t pt-4"><h2 class="font-semibold">FMO personnel profile</h2><p>{{ $user->personnelProfile->designation ?? 'No designation' }} · {{ str_replace('_', ' ', $user->personnelProfile->personnel_status->value) }}</p><p class="text-sm">Skills: {{ $user->personnelProfile->skills->pluck('name')->join(', ') ?: 'None' }}</p></div>@endif
</div>
@endsection
