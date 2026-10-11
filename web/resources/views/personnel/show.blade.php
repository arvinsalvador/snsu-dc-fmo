@extends('layouts.app')
@section('title', 'FMO Staff')
@section('content')
    <x-breadcrumbs :items="['FMO Staff' => route('personnel.index'), $personnel->user->name]" />
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-4"><x-avatar :name="$personnel->user->name" size="lg" /><div><h1>{{ $personnel->user->name }}</h1><p class="mt-1 text-sm text-slate-600">{{ $personnel->designation ?? 'FMO Personnel' }}</p><div class="mt-2"><x-status-badge :status="$personnel->personnel_status" /></div></div></div>
        @can('personnel.update')<a class="btn btn-primary" href="{{ route('personnel.edit', $personnel) }}"><x-icon name="pencil" />Edit profile</a>@endcan
    </div>
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="section-card"><h2 class="text-lg">Employment</h2><dl class="definition-grid mt-5"><div><dt>Email</dt><dd>{{ $personnel->user->email }}</dd></div><div><dt>Account status</dt><dd><x-status-badge :status="$personnel->user->status" /></dd></div><div><dt>Personnel ID</dt><dd>{{ $personnel->personnel_identifier ?? 'Not specified' }}</dd></div><div><dt>Employment type</dt><dd>{{ $personnel->employment_type ? str($personnel->employment_type->value)->replace('_', ' ')->title() : 'Not specified' }}</dd></div><div><dt>Start date</dt><dd>{{ $personnel->start_date?->format('F j, Y') ?? 'Not specified' }}</dd></div><div><dt>Assignment availability</dt><dd>{{ $personnel->isAssignable() ? 'Available' : 'Not assignable' }}</dd></div></dl></section>
        <section class="section-card"><h2 class="text-lg">Skills</h2><div class="mt-5 flex flex-wrap gap-2">@forelse ($personnel->skills as $skill)<span class="rounded-full bg-blue-50 px-3 py-1.5 text-sm font-medium text-blue-800 ring-1 ring-blue-200">{{ $skill->name }}{{ $skill->pivot->is_primary ? ' · Primary' : '' }}</span>@empty<p class="text-sm text-slate-500">No skills recorded.</p>@endforelse</div>@can('personnel.update')<div class="mt-6 border-t border-slate-100 pt-5"><p class="detail-label">Administrative notes</p><p class="mt-2 text-sm leading-6 text-slate-700">{{ $personnel->notes ?? 'No notes recorded.' }}</p></div>@endcan</section>
    </div>
    <div class="mt-6 flex flex-wrap gap-3">
        @can('reports.view_personnel_history')<a class="btn btn-secondary" href="{{ route('reports.history', ['personnel_id' => $personnel->id]) }}"><x-icon name="chart" />View Work History</a>@endcan
    </div>
    @can('personnel.manage_status')
        <form class="section-card mt-6 max-w-2xl" method="POST" action="{{ route('personnel.status', $personnel) }}">@csrf @method('PUT')<h2 class="text-lg">Availability status</h2><p class="mt-1 text-sm text-slate-500">Status controls whether this staff member can receive new assignments.</p><div class="mt-4 flex flex-wrap items-end gap-3"><label class="min-w-64 flex-1">Personnel status<select name="personnel_status">@foreach (\App\Enums\PersonnelStatus::cases() as $status)<option value="{{ $status->value }}" @selected($personnel->personnel_status === $status)>{{ str($status->value)->replace('_', ' ')->title() }}</option>@endforeach</select></label><button class="btn btn-secondary" type="submit">Update status</button></div></form>
    @endcan
    @can('personnel.delete')
        <form class="mt-5" method="POST" action="{{ route('personnel.destroy', $personnel) }}" data-confirm="Delete this unused personnel profile? The linked user account will remain, but this personnel profile cannot be recovered.">@csrf @method('DELETE')<button class="btn btn-danger" type="submit"><x-icon name="trash" />Delete unused profile</button></form>
    @endcan
@endsection
