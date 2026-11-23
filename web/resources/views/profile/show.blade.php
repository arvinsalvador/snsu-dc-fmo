@extends('layouts.app')
@section('title', 'My Profile')
@section('content')
@php
    $personnel = $user->personnelProfile;
    $primarySkill = $personnel?->skills->first(fn ($skill) => $skill->pivot->is_primary);
@endphp
<x-page-header title="My Profile" description="Manage your personal information, profile picture and account security." />

<div class="mt-6 grid items-start gap-6 lg:grid-cols-[21rem_minmax(0,1fr)]">
<aside class="space-y-6 lg:sticky lg:top-24">
    <section class="section-card text-center" aria-labelledby="photo-heading" x-data="{ preview: null, name: '' }">
        <h2 id="photo-heading" class="sr-only">Profile photo</h2>
        <div class="relative mx-auto w-fit">
            <template x-if="preview"><img :src="preview" alt="Preview of the selected photo" class="size-28 rounded-full object-cover ring-4 ring-blue-100"></template>
            <div x-show="! preview"><x-user-avatar :user="$user" size="xl" class="!size-28 !text-3xl ring-4 ring-slate-100" /></div>
        </div>
        <p class="mt-4 text-lg font-semibold text-slate-900">{{ $user->name }}</p>
        <p class="text-sm text-slate-500">{{ $user->email }}</p>

        <form method="POST" action="{{ route('profile.photo.store') }}" enctype="multipart/form-data" class="mt-5 space-y-3">@csrf
            <label class="btn btn-secondary w-full cursor-pointer focus-within:ring-3 focus-within:ring-slate-200"><x-icon name="camera" size="size-4" /><span x-text="name || '{{ $user->hasAvatar() ? 'Change photo' : 'Upload photo' }}'"></span>
                <input class="sr-only" type="file" name="photo" accept="image/jpeg,image/png,image/webp" required
                       @change="const f = $event.target.files[0]; if (f) { name = f.name; preview = URL.createObjectURL(f); }">
            </label>
            <button class="btn btn-primary w-full" type="submit" x-show="preview" x-cloak><x-icon name="check-circle" size="size-4" />Save photo</button>
            @error('photo')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            <p class="help-text">JPG, PNG or WebP. Maximum {{ $maxPhotoMb }} MB. Your photo is cropped to a square.</p>
        </form>
        @if ($user->hasAvatar())
            <form method="POST" action="{{ route('profile.photo.destroy') }}" class="mt-3" data-confirm="Remove your profile picture? Your initials will be shown instead.">@csrf @method('DELETE')
                <button class="btn btn-ghost !text-rose-700 hover:!bg-rose-50" type="submit"><x-icon name="trash" size="size-4" />Remove photo</button>
            </form>
        @endif
    </section>

    <section class="section-card" aria-labelledby="account-heading">
        <h2 id="account-heading" class="text-base">Account information</h2>
        <p class="help-text !mt-1">Managed by FMO administration. These details are read-only.</p>
        <dl class="mt-4 divide-y divide-slate-100 text-sm">
            <div class="flex items-center justify-between gap-3 py-2.5"><dt class="detail-label">Institutional type</dt><dd class="font-semibold text-slate-900">{{ ucfirst($user->user_type ?? 'Not set') }}</dd></div>
            <div class="flex items-start justify-between gap-3 py-2.5"><dt class="detail-label">Role</dt><dd class="text-right font-semibold text-slate-900">{{ $user->getRoleNames()->join(', ') ?: 'None assigned' }}</dd></div>
            <div class="flex items-center justify-between gap-3 py-2.5"><dt class="detail-label">Account status</dt><dd><x-status-badge :status="$user->status" /></dd></div>
            <div class="flex items-center justify-between gap-3 py-2.5"><dt class="detail-label">Member since</dt><dd class="font-semibold text-slate-900">{{ $user->created_at?->format('M j, Y') }}</dd></div>
            @if ($personnel)
                <div class="flex items-center justify-between gap-3 py-2.5"><dt class="detail-label">Designation</dt><dd class="text-right font-semibold text-slate-900">{{ $personnel->designation ?: 'Not specified' }}</dd></div>
                <div class="flex items-center justify-between gap-3 py-2.5"><dt class="detail-label">Employment</dt><dd class="font-semibold text-slate-900">{{ $personnel->employment_type ? str($personnel->employment_type->value)->replace('_', ' ')->title() : 'Not specified' }}</dd></div>
                <div class="flex items-center justify-between gap-3 py-2.5"><dt class="detail-label">Personnel status</dt><dd><x-status-badge :status="$personnel->personnel_status" /></dd></div>
                @if ($primarySkill)<div class="flex items-center justify-between gap-3 py-2.5"><dt class="detail-label">Primary skill</dt><dd class="font-semibold text-slate-900">{{ $primarySkill->name }}</dd></div>@endif
                <div class="py-2.5"><dt class="detail-label">Skills</dt><dd class="mt-1 flex flex-wrap gap-1.5">@forelse ($personnel->skills as $skill)<span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">{{ $skill->name }}</span>@empty<span class="text-slate-500">None recorded</span>@endforelse</dd></div>
            @endif
        </dl>
    </section>
</aside>

<div class="space-y-6">
    <form class="form-section" method="POST" action="{{ route('profile.personal') }}" novalidate>@csrf @method('PUT')
        <h2>Personal information</h2><p>Your name appears on requests, Work Orders and messages.</p>
        <div class="grid gap-5 sm:grid-cols-2">
            <label class="sm:col-span-2">Full name <span class="text-rose-600">*</span>
                <input name="name" required maxlength="120" autocomplete="name" value="{{ old('name', $user->name) }}" class="@error('name') field-invalid @enderror" @error('name') aria-invalid="true" @enderror>
                @error('name')<span class="field-error" role="alert">{{ $message }}</span>@enderror</label>
            <div>
                <label for="profile-email">Email address</label>
                <input id="profile-email" type="email" value="{{ $user->email }}" readonly aria-readonly="true" aria-describedby="email-help" class="cursor-not-allowed !bg-slate-50 text-slate-600">
                <p id="email-help" class="help-text flex items-center gap-1.5"><x-icon name="lock" size="size-3.5" />Your email is your sign-in and can only be changed by FMO administration.</p>
            </div>
            <label>Contact number <span class="font-normal text-slate-400">— Optional</span>
                <input name="contact_number" maxlength="50" autocomplete="tel" value="{{ old('contact_number', $contactNumber) }}" class="@error('contact_number') field-invalid @enderror">
                @error('contact_number')<span class="field-error" role="alert">{{ $message }}</span>@enderror</label>
        </div>
        <div class="mt-6 flex justify-end"><button class="btn btn-primary" type="submit"><x-icon name="check-circle" />Save changes</button></div>
    </form>

    @if ($canEditInstitutional)
        <form class="form-section" method="POST" action="{{ route('profile.update') }}">@csrf @method('PUT')
            <h2>Institutional information</h2><p>These details help FMO staff understand the context of submitted requests.</p>
            <div class="grid gap-5 sm:grid-cols-2">
                <label>Institutional ID <span class="text-slate-500">(optional)</span><input name="institutional_id" value="{{ old('institutional_id', $user->requesterProfile?->institutional_id) }}"></label>
                <label>Department <span class="text-slate-500">(optional)</span><input name="department" value="{{ old('department', $user->requesterProfile?->department) }}"></label>
                <label>College <span class="text-slate-500">(optional)</span><input name="college" value="{{ old('college', $user->requesterProfile?->college) }}"></label>
                <label>Program <span class="text-slate-500">(optional)</span><input name="program" value="{{ old('program', $user->requesterProfile?->program) }}"></label>
                <label>Year level <span class="text-slate-500">(optional)</span><input name="year_level" value="{{ old('year_level', $user->requesterProfile?->year_level) }}"></label>
                <label>Organizational office <span class="text-slate-500">(optional)</span><input name="organizational_office" value="{{ old('organizational_office', $user->requesterProfile?->organizational_office) }}"></label>
            </div>
            <div class="mt-6 flex justify-end"><button class="btn btn-primary" type="submit"><x-icon name="check-circle" />Save institutional details</button></div>
        </form>
    @endif

    <form id="password" class="form-section" method="POST" action="{{ route('profile.password') }}" autocomplete="off">@csrf @method('PUT')
        <h2>Password &amp; security</h2><p>Use a long, unique password. After changing it, other signed-in apps may need to sign in again.</p>
        <div class="grid gap-5 sm:grid-cols-2">
            <label class="sm:col-span-2">Current password <span class="text-rose-600">*</span>
                <input type="password" name="current_password" required autocomplete="current-password" class="@error('current_password') field-invalid @enderror">
                @error('current_password')<span class="field-error" role="alert">{{ $message }}</span>@enderror</label>
            <label>New password <span class="text-rose-600">*</span>
                <input type="password" name="password" required minlength="12" autocomplete="new-password" class="@error('password') field-invalid @enderror">
                @error('password')<span class="field-error" role="alert">{{ $message }}</span>@else<span class="help-text block">At least 12 characters.</span>@enderror</label>
            <label>Confirm new password <span class="text-rose-600">*</span>
                <input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
        </div>
        <div class="mt-6 flex justify-end"><button class="btn btn-primary" type="submit"><x-icon name="lock" />Update password</button></div>
    </form>
</div>
</div>
@endsection
