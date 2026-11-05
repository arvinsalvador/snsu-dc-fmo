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
                <tr><td class="font-semibold text-slate-900">{{ $location->name }}<p class="mt-0.5 text-xs font-normal text-slate-500">{{ $location->code ?: 'No code' }}</p></td><td>{{ $location->building->name }}</td><td>{{ $location->floor?->name ?: 'Building-level' }}</td><td>{{ str($location->type->value)->replace('_', ' ')->title() }}</td><td><x-status-badge :status="$location->isOperational() ? 'ACTIVE' : 'INACTIVE'" /></td><td class="text-right">@can('locations.update')<details class="relative inline-block text-left"><summary class="btn btn-ghost !min-h-9 !px-3">Edit</summary><form class="absolute right-0 z-10 mt-2 w-80 space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-lg" method="POST" action="{{ route('locations.update', $location) }}" x-data="floorSelector({ url: '{{ route('work-orders.location-floors') }}', building: '{{ $location->building_id }}', floor: '{{ $location->floor_id }}', floors: {{ Js::from($floors->where('building_id', $location->building_id)->map(fn ($floor) => ['id' => (string) $floor->id, 'name' => $floor->name])->values()) }} })">@csrf @method('PUT')<label>Name<input name="name" value="{{ $location->name }}" required></label><label>Building<select name="building_id" x-model="building" @change="load()">@foreach ($buildings as $building)<option value="{{ $building->id }}">{{ $building->campus->name }} — {{ $building->name }}</option>@endforeach</select></label><div><label for="edit-floor-{{ $location->id }}">Floor <span class="text-slate-500">(optional)</span></label><select id="edit-floor-{{ $location->id }}" name="floor_id" :disabled="disabled" :aria-busy="loading" aria-describedby="edit-floor-help-{{ $location->id }}" @change="floor = $event.target.value"><option value="" x-text="placeholder" :selected="floor === ''"></option><template x-for="item in options" :key="item.id"><option :value="item.id" x-text="item.name" :selected="item.id === floor"></option></template></select><p id="edit-floor-help-{{ $location->id }}" class="mt-1 text-xs text-slate-500" role="status" aria-live="polite"><span x-show="noFloors" x-cloak>No floors are configured for this building.</span><span x-show="failed" x-cloak class="text-rose-600">Unable to load floors. <button type="button" class="underline" @click="load()">Retry</button></span></p></div><label>Type<select name="type">@foreach ($types as $type)<option value="{{ $type->value }}" @selected($type === $location->type)>{{ str($type->value)->replace('_', ' ')->title() }}</option>@endforeach</select></label><label class="flex items-center gap-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($location->is_active)> Active</label><button class="btn btn-primary w-full">Save location</button></form></details>@endcan</td></tr>
            @empty
                <tr><td colspan="6"><x-empty-state title="No offices, rooms or areas found" description="Add a specific location, or keep Work Orders building-wide." /></td></tr>
            @endforelse
        </tbody></table></div>

        @can('locations.create')
            <form class="section-card h-fit space-y-4" method="POST" action="{{ route('locations.store') }}" x-data="floorSelector({ url: '{{ route('work-orders.location-floors') }}', building: '{{ old('building_id', request('building_id')) }}', floor: '{{ old('floor_id') }}', floors: {{ Js::from($floors->where('building_id', (int) old('building_id', request('building_id')))->map(fn ($floor) => ['id' => (string) $floor->id, 'name' => $floor->name])->values()) }} })">
                @csrf
                <div><h2 class="text-lg">Add Office / Room / Area</h2><p class="mt-1 text-sm text-slate-500">Choose a building. Floor is optional.</p></div>
                <label>Building <span class="text-rose-600">*</span><select name="building_id" required x-model="building" @change="load()"><option value="">Select building</option>@foreach ($buildings as $building)<option value="{{ $building->id }}">{{ $building->campus->name }} — {{ $building->name }}</option>@endforeach</select></label>
                <div><label for="create-floor">Floor <span class="text-slate-500">(optional)</span></label><select id="create-floor" name="floor_id" :disabled="disabled" :aria-busy="loading" aria-describedby="create-floor-help" @change="floor = $event.target.value"><option value="" x-text="placeholder" :selected="floor === ''"></option><template x-for="item in options" :key="item.id"><option :value="item.id" x-text="item.name" :selected="item.id === floor"></option></template></select><p id="create-floor-help" class="mt-1 text-xs text-slate-500" role="status" aria-live="polite"><span x-show="noFloors" x-cloak>No floors are configured for this building.</span><span x-show="failed" x-cloak class="text-rose-600">Unable to load floors. <button type="button" class="underline" @click="load()">Retry</button></span></p></div>
                <label>Location type <select name="type">@foreach ($types as $type)<option value="{{ $type->value }}">{{ str($type->value)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
                <label>Name <span class="text-rose-600">*</span><input name="name" value="{{ old('name') }}" required></label>
                <label>Code / room number<input name="code" value="{{ old('code') }}"></label>
                <input type="hidden" name="is_active" value="1"><button class="btn btn-primary w-full" type="submit">Add location</button>
            </form>
        @endcan
    </div>
    <div class="mt-5">{{ $locations->links() }}</div>
@endsection
