@extends('layouts.app')
@section('title', 'FMO Staff')
@section('content')
    <x-page-header title="FMO Staff" description="Manage facilities personnel, skills and assignment availability.">
        @can('personnel.create')
            <x-slot:action><a class="btn btn-primary" href="{{ route('personnel.create') }}"><x-icon name="plus" />Add FMO Staff</a></x-slot:action>
        @endcan
    </x-page-header>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Active" :value="$summary['active']" icon="users" />
        <x-stat-card label="Available" :value="$summary['available']" icon="check-circle" />
        <x-stat-card label="On Leave" :value="$summary['on_leave']" icon="calendar" />
        <x-stat-card label="Unavailable" :value="$summary['unavailable']" icon="warning" />
    </div>

    <form class="filter-bar mt-6" method="GET">
        <label class="min-w-56 flex-1">Search staff<input name="search" placeholder="Name or email" value="{{ $filters['search'] ?? '' }}"></label>
        <label>Status<select name="status"><option value="">All statuses</option>@foreach (\App\Enums\PersonnelStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ str($status->value)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
        <label>Skill<select name="skill"><option value="">All skills</option>@foreach ($skills as $skill)<option value="{{ $skill->id }}" @selected(($filters['skill'] ?? '') == $skill->id)>{{ $skill->name }}</option>@endforeach</select></label>
        <label>Designation<select name="designation"><option value="">All designations</option>@foreach ($designations as $designation)<option value="{{ $designation }}" @selected(($filters['designation'] ?? '') === $designation)>{{ $designation }}</option>@endforeach</select></label>
        <button class="btn btn-primary" type="submit"><x-icon name="filter" />Filter</button>
        <a class="btn btn-ghost" href="{{ route('personnel.index') }}">Reset</a>
    </form>

    <div class="table-wrap mt-6">
        <table><thead><tr><th>Staff member</th><th>Personnel ID</th><th>Designation</th><th>Employment</th><th>Primary skill</th><th>Status</th><th>Availability</th><th class="text-right">Action</th></tr></thead><tbody>
            @forelse ($personnel as $member)
                <tr>
                    <td><div class="flex items-center gap-3"><x-avatar :name="$member->user->name" /><div><p class="font-semibold text-slate-900">{{ $member->user->name }}</p><p class="text-xs text-slate-500">{{ $member->user->email }}</p></div></div></td>
                    <td>{{ $member->personnel_identifier ?? '—' }}</td>
                    <td>{{ $member->designation ?? '—' }}</td>
                    <td>{{ $member->employment_type ? str($member->employment_type->value)->replace('_', ' ')->title() : '—' }}</td>
                    <td>{{ $member->skills->firstWhere('pivot.is_primary', true)?->name ?? '—' }}</td>
                    <td><x-status-badge :status="$member->personnel_status" /></td>
                    <td><span class="{{ $member->isAssignable() ? 'text-emerald-700' : 'text-slate-500' }} font-medium">{{ $member->isAssignable() ? 'Available for assignment' : 'Not assignable' }}</span></td>
                    <td class="text-right"><a class="btn btn-secondary !min-h-9 !px-3" href="{{ route('personnel.show', $member) }}"><x-icon name="eye" size="size-4" />View</a></td>
                </tr>
            @empty
                <tr><td colspan="8"><x-empty-state title="No FMO personnel found" description="Adjust the filters or add the first staff profile." icon="users" /></td></tr>
            @endforelse
        </tbody></table>
    </div>
    <div class="mt-5">{{ $personnel->links() }}</div>
@endsection
