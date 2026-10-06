@extends('layouts.app')

@section('title', 'Buildings')

@section('content')
    <x-page-header title="Buildings" description="Manage buildings and open a building to maintain its floors and specific areas.">
        <x-slot:action><a class="btn btn-secondary" href="{{ route('locations.index') }}">Location Management</a></x-slot:action>
    </x-page-header>

    <form class="filter-bar mt-6" method="GET">
        <label class="min-w-52 flex-1">Search<input name="search" value="{{ request('search') }}" placeholder="Building name or code"></label>
        <label>Campus<select name="campus_id"><option value="">All campuses</option>@foreach ($campuses as $campus)<option value="{{ $campus->id }}" @selected((string) request('campus_id') === (string) $campus->id)>{{ $campus->name }}</option>@endforeach</select></label>
        <label>Status<select name="active"><option value="">All statuses</option><option value="1" @selected(request('active') === '1')>Active</option><option value="0" @selected(request('active') === '0')>Inactive</option></select></label>
        <button class="btn btn-primary" type="submit">Apply filters</button>
    </form>

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Building</th><th>Campus</th><th>Code</th><th>Floors</th><th>Areas</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($buildings as $building)
                        <tr>
                            <td class="font-semibold text-slate-900">{{ $building->name }}</td><td>{{ $building->campus->name }}</td><td>{{ $building->code ?: '—' }}</td><td>{{ $building->floors_count }}</td><td>{{ $building->locations_count }}</td>
                            <td><x-status-badge :status="$building->isOperational() ? 'ACTIVE' : 'INACTIVE'" /></td>
                            <td class="text-right"><a class="btn btn-secondary !min-h-9 !px-3" href="{{ route('buildings.show', $building) }}">View</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty-state title="No buildings found" description="Add a building to begin managing floors and areas." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @can('buildings.create')
            <form class="section-card h-fit space-y-4" method="POST" action="{{ route('buildings.store') }}">
                @csrf
                <div><h2 class="text-lg">Add Building</h2><p class="mt-1 text-sm text-slate-500">A building is required for new Work Orders.</p></div>
                <label>Campus <span class="text-rose-600">*</span><select name="campus_id" required><option value="">Select campus</option>@foreach ($campuses as $campus)<option value="{{ $campus->id }}" @selected(old('campus_id') == $campus->id)>{{ $campus->name }}</option>@endforeach</select></label>
                <label>Building name <span class="text-rose-600">*</span><input name="name" value="{{ old('name') }}" required></label>
                <label>Code<input name="code" value="{{ old('code') }}"></label>
                <input type="hidden" name="is_active" value="1">
                <button class="btn btn-primary w-full" type="submit">Add building</button>
            </form>
        @endcan
    </div>
    <div class="mt-5">{{ $buildings->links() }}</div>
@endsection
