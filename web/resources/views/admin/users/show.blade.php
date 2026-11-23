@extends('layouts.app')
@section('title', 'User Details')
@section('content')
    <x-breadcrumbs :items="['Users' => route('users.index'), $managedUser->name]" />
    <div class="flex items-center gap-4"><x-user-avatar :user="$managedUser" size="lg" /><div><h1>{{ $managedUser->name }}</h1><p class="mt-1 text-sm text-slate-600">{{ $managedUser->email }}</p></div></div>
    <section class="section-card mt-6"><div class="flex items-center justify-between gap-3"><h2 class="text-lg">Account Information</h2><x-status-badge :status="$managedUser->status" /></div><dl class="definition-grid mt-5"><div><dt>Institutional user type</dt><dd>{{ ucfirst($managedUser->user_type ?? 'Unknown') }}</dd></div><div><dt>Roles</dt><dd>{{ $managedUser->getRoleNames()->join(', ') ?: 'None assigned' }}</dd></div><div><dt>Last updated</dt><dd>{{ $managedUser->updated_at?->timezone(config('app.timezone'))->format('M j, Y · g:i A') }}</dd></div>@if ($managedUser->personnelProfile)<div><dt>Personnel</dt><dd>{{ $managedUser->personnelProfile->designation ?: 'FMO Personnel' }} · {{ $managedUser->personnelProfile->skills->pluck('name')->join(', ') ?: 'No skills' }}</dd></div>@endif<div class="sm:col-span-2"><dt>Direct permissions</dt><dd>{{ $managedUser->getDirectPermissions()->pluck('name')->join(', ') ?: 'None assigned' }}</dd></div></dl></section>

    @if ($canEditProfile || $canResetPassword)
        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            @if ($canEditProfile)
                <form method="POST" action="{{ route('users.profile', $managedUser) }}" class="section-card" novalidate>@csrf @method('PUT')
                    <h2 class="text-lg">Profile</h2><p class="mt-1 text-sm text-slate-500">Name and email only. Roles and permissions are managed separately.</p>
                    <div class="mt-4 space-y-4">
                        <label>Full name <span class="text-rose-600">*</span><input name="name" required maxlength="120" value="{{ old('name', $managedUser->name) }}">@error('name')<span class="field-error">{{ $message }}</span>@enderror</label>
                        <label>Email address <span class="text-rose-600">*</span><input type="email" name="email" required maxlength="255" value="{{ old('email', $managedUser->email) }}">@error('email')<span class="field-error">{{ $message }}</span>@enderror</label>
                    </div>
                    <button class="btn btn-primary mt-5" type="submit"><x-icon name="check-circle" />Save profile</button>
                </form>
            @endif
            @if ($canResetPassword && auth()->id() !== $managedUser->id)
                <form method="POST" action="{{ route('users.password', $managedUser) }}" class="section-card" autocomplete="off"
                      data-confirm="Reset the password for {{ $managedUser->name }}? They will be signed out everywhere and must use the new password."
                      x-data="{ generated: '', generate() { const c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%'; const a = new Uint32Array(16); crypto.getRandomValues(a); this.generated = Array.from(a, n => c[n % c.length]).join(''); this.$refs.p.value = this.generated; this.$refs.c.value = this.generated; this.$refs.p.type = 'text'; } }">@csrf @method('PUT')
                    <h2 class="text-lg">Security</h2><p class="mt-1 text-sm text-slate-500">Set a new password for this user. Existing passwords are never shown, and the new password is not recorded in the activity log.</p>
                    <div class="mt-4 space-y-4">
                        <label>New password <span class="text-rose-600">*</span><input x-ref="p" type="password" name="password" required minlength="12" autocomplete="new-password">@error('password')<span class="field-error">{{ $message }}</span>@enderror</label>
                        <label>Confirm new password <span class="text-rose-600">*</span><input x-ref="c" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
                        <div><button type="button" class="btn btn-ghost !px-2" @click="generate()"><x-icon name="lock" size="size-4" />Generate temporary password</button><p class="help-text" x-show="generated" x-cloak>Share it with the user securely. They should change it after signing in.</p></div>
                        <label>Your password <span class="text-rose-600">*</span><input type="password" name="admin_password" required autocomplete="current-password">@error('admin_password')<span class="field-error">{{ $message }}</span>@enderror<span class="help-text block">Confirm it is you before resetting another account.</span></label>
                    </div>
                    <button class="btn btn-danger mt-5" type="submit"><x-icon name="lock" size="size-4" />Reset password</button>
                </form>
            @endif
        </div>
    @endif
    <section class="section-card mt-6" aria-labelledby="audit-heading"><h2 id="audit-heading" class="text-lg">Activity / Audit</h2>
        <ol class="mt-4">@forelse ($auditEvents as $event)
            <li class="timeline-item"><p class="text-sm font-semibold text-slate-900">{{ match ($event->event) { 'email_changed' => 'Email changed', 'profile_updated' => 'Profile updated', 'photo_changed' => 'Profile photo changed', 'photo_removed' => 'Profile photo removed', 'password_changed' => 'Password changed by the user', 'password_reset' => 'Password reset by an administrator', default => str($event->event)->replace('_', ' ')->title()->value() } }}</p>
                <p class="text-xs text-slate-500">{{ $event->actor?->name ?? 'System' }} · {{ $event->created_at->timezone(config('app.timezone'))->format('M j, Y · g:i A') }}</p>
                @if ($event->event === 'email_changed')<p class="mt-1 text-xs text-slate-600">{{ $event->meta['from'] ?? '' }} → {{ $event->meta['to'] ?? '' }}</p>@endif</li>
        @empty<li class="text-sm text-slate-500">No account activity has been recorded yet.</li>@endforelse</ol></section>
    @if (auth()->id() !== $managedUser->id)
        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            @can('users.manage_status')
                <form method="POST" action="{{ route('users.status', $managedUser) }}" class="section-card">@csrf @method('PUT')<h2 class="text-lg">Account Status</h2><p class="mt-1 text-sm text-slate-500">Suspended and deactivated users cannot use approved application features.</p>@if ($managedUser->status === \App\Enums\AccountStatus::Rejected)<p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">This registration was rejected. You can reconsider it by approving the account.</p><label class="mt-4 block">New status<select name="status"><option value="APPROVED">Approved</option></select></label>@else<label class="mt-4 block">New status<select name="status"><option value="APPROVED">Approved</option><option value="SUSPENDED">Suspended</option><option value="DEACTIVATED">Deactivated</option></select></label>@endif<label class="mt-4 block">Reason <span class="text-rose-600">*</span><textarea name="reason" required></textarea></label><button class="btn btn-primary mt-5" type="submit">Update status</button></form>
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
