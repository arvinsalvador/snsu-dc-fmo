@extends('layouts.app')

@section('title', 'Offices / Rooms / Areas')

@section('content')
    <x-page-header title="Offices / Rooms / Areas" description="Manage specific work locations. A floor is optional for building-level and outdoor areas.">
        <x-slot:action><a class="btn btn-secondary" href="{{ route('locations.index') }}">Location Management</a></x-slot:action>
    </x-page-header>

    <form class="filter-bar mt-6" method="GET">
        <label class="min-w-52 flex-1">Search<input name="search" value="{{ request('search') }}" placeholder="Name or code"></label>
        <label>Building<select name="building_id"><option value="">All buildings</option>@foreach ($buildings as $building)<option value="{{ $building->id }}" @selected((string) request('building_id') === (string) $building->id)>{{ $building->campus->name }} — {{ $building->name }}</option>@endforeach</select></label>
        <label>Floor<select name="floor_id"><option value="">All floors</option>@foreach ($floors as $floor)<option value="{{ $floor->id }}" @selected((string) request('floor_id') === (string) $floor->id)>{{ $floor->building->name }} — {{ $floor->name }}</option>@endforeach</select></label>
        <button class="btn btn-primary" type="submit">Apply filters</button>
    </form>

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="table-wrap"><table><thead><tr><th>Office / Room / Area</th><th>Building</th><th>Floor</th><th>Type</th><th>Status</th><th class="text-right">Actions</th></tr></thead><tbody>
            @forelse ($locations as $location)
                <tr><td class="font-semibold text-slate-900">{{ $location->name }}<p class="mt-0.5 text-xs font-normal text-slate-500">{{ $location->code ?: 'No code' }}</p></td><td>{{ $location->building->name }}</td><td>{{ $location->floor?->name ?: 'Building-level' }}</td><td>{{ str($location->type->value)->replace('_', ' ')->title() }}</td><td><x-status-badge :status="$location->isOperational() ? 'ACTIVE' : 'INACTIVE'" /></td><td class="text-right">@can('locations.update')<details class="relative inline-block text-left"><summary class="btn btn-ghost !min-h-9 !px-3">Edit</summary><form class="absolute right-0 z-10 mt-2 w-80 space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-lg" method="POST" action="{{ route('locations.update', $location) }}">@csrf @method('PUT')<label>Name<input name="name" value="{{ $location->name }}" required></label><label>Building<select name="building_id">@foreach ($buildings as $building)<option value="{{ $building->id }}" @selected($building->id === $location->building_id)>{{ $building->campus->name }} — {{ $building->name }}</option>@endforeach</select></label><label>Floor<select name="floor_id"><option value="">No floor</option>@foreach ($floors->where('building_id', $location->building_id) as $floor)<option value="{{ $floor->id }}" @selected($floor->id === $location->floor_id)>{{ $floor->name }}</option>@endforeach</select></label><label>Type<select name="type">@foreach ($types as $type)<option value="{{ $type->value }}" @selected($type === $location->type)>{{ str($type->value)->replace('_', ' ')->title() }}</option>@endforeach</select></label><label class="flex items-center gap-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($location->is_active)> Active</label><button class="btn btn-primary w-full">Save location</button></form></details>@endcan</td></tr>
            @empty
                <tr><td colspan="6"><x-empty-state title="No offices, rooms or areas found" description="Add a specific location, or keep Work Orders building-wide." /></td></tr>
            @endforelse
        </tbody></table></div>

        @can('locations.create')
            <form class="section-card h-fit space-y-4" method="POST" action="{{ route('locations.store') }}">
                @csrf
                <div><h2 class="text-lg">Add Office / Room / Area</h2><p class="mt-1 text-sm text-slate-500">Choose a building. Floor is optional.</p></div>
                <label>Building <span class="text-rose-600">*</span><select name="building_id" required><option value="">Select building</option>@foreach ($buildings as $building)<option value="{{ $building->id }}" @selected(old('building_id', request('building_id')) == $building->id)>{{ $building->campus->name }} — {{ $building->name }}</option>@endforeach</select></label>
                <label>Floor <span class="text-slate-500">(optional)</span><select name="floor_id"><option value="">No floor / building-level</option>@foreach ($floors as $floor)<option value="{{ $floor->id }}" @selected(old('floor_id') == $floor->id)>{{ $floor->building->name }} — {{ $floor->name }}</option>@endforeach</select></label>
                <label>Location type <select name="type">@foreach ($types as $type)<option value="{{ $type->value }}">{{ str($type->value)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
                <label>Name <span class="text-rose-600">*</span><input name="name" value="{{ old('name') }}" required></label>
                <label>Code / room number<input name="code" value="{{ old('code') }}"></label>
                <input type="hidden" name="is_active" value="1"><button class="btn btn-primary w-full" type="submit">Add location</button>
            </form>
        @endcan
    </div>
    <div class="mt-5">{{ $locations->links() }}</div>
@endsection
