@extends('layouts.app')

@section('title', $building->name)

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a class="text-sm font-medium text-blue-800 hover:underline" href="{{ route('buildings.index') }}">← Buildings</a>
            <h1 class="mt-2">{{ $building->name }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $building->campus->name }} · {{ $building->isOperational() ? 'Active' : 'Inactive or unavailable campus' }}</p>
        </div>
        <div class="flex gap-2">
            @can('reports.view_location_history')<a class="btn btn-secondary" href="{{ route('reports.history', ['building_id' => $building->id]) }}">View Work Order history</a>@endcan
            @can('buildings.update')
                <details class="relative">
                    <summary class="btn btn-primary">Edit Building</summary>
                    <form class="absolute right-0 z-10 mt-2 w-96 space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-lg" method="POST" action="{{ route('buildings.update', $building) }}">
                        @csrf @method('PUT')
                        <label>Campus<select name="campus_id" required>@foreach ($campuses as $campus)<option value="{{ $campus->id }}" @selected($campus->id === $building->campus_id)>{{ $campus->name }}</option>@endforeach</select></label>
                        <label>Building name<input name="name" value="{{ $building->name }}" required></label>
                        <label>Code<input name="code" value="{{ $building->code }}"></label>
                        <label class="flex items-center gap-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($building->is_active)> Active</label>
                        <button class="btn btn-primary w-full" type="submit">Save building</button>
                    </form>
                </details>
            @endcan
        </div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="section-card">
            <div class="flex items-center justify-between gap-3"><div><h2 class="text-lg">Floors</h2><p class="mt-1 text-sm text-slate-500">Optional levels within this building.</p></div><span class="text-sm text-slate-500">{{ $building->floors->count() }} total</span></div>
            <div class="mt-5 divide-y divide-slate-100">
                @forelse ($building->floors->sortBy('display_order') as $floor)
                    <div class="flex items-center justify-between gap-3 py-3"><div><p class="font-medium text-slate-900">{{ $floor->name }}</p><p class="text-xs text-slate-500">{{ $floor->code ?: 'No code' }} · {{ $floor->locations->count() }} areas</p></div><div class="flex items-center gap-2"><x-status-badge :status="$floor->is_active ? 'ACTIVE' : 'INACTIVE'" />@can('locations.update')<details class="relative"><summary class="btn btn-ghost !min-h-9 !px-2">Edit</summary><form class="absolute right-0 z-10 mt-2 w-72 space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-lg" method="POST" action="{{ route('floors.update', $floor) }}">@csrf @method('PUT')<label>Name<input name="name" value="{{ $floor->name }}" required></label><label>Code<input name="code" value="{{ $floor->code }}"></label><label>Sort order<input name="display_order" type="number" min="0" value="{{ $floor->display_order }}"></label><label class="flex items-center gap-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($floor->is_active)> Active</label><button class="btn btn-primary w-full">Save floor</button></form></details>@endcan</div></div>
                @empty
                    <x-empty-state title="No floors have been added yet" description="Add a floor when requests need level-specific locations." />
                @endforelse
            </div>
            @can('locations.create')
                <form class="mt-5 grid gap-3 border-t border-slate-100 pt-5 sm:grid-cols-[1fr_auto]" method="POST" action="{{ route('floors.store', $building) }}">
                    @csrf<input type="hidden" name="is_active" value="1"><label>Floor name <span class="text-rose-600">*</span><input name="name" placeholder="Example: Ground Floor" required></label><button class="btn btn-primary self-end" type="submit">+ Add Floor</button>
                </form>
            @endcan
        </section>

        <section class="section-card">
            <div class="flex items-center justify-between gap-3"><div><h2 class="text-lg">Offices / Rooms / Areas</h2><p class="mt-1 text-sm text-slate-500">Specific locations, including building-level areas.</p></div><a class="btn btn-secondary !min-h-9 !px-3" href="{{ route('locations.areas', ['building_id' => $building->id]) }}">Manage</a></div>
            <div class="mt-5 divide-y divide-slate-100">
                @forelse ($building->locations as $location)
                    <div class="flex items-center justify-between gap-3 py-3"><div><p class="font-medium text-slate-900">{{ $location->name }}</p><p class="text-xs text-slate-500">{{ str($location->type->value)->replace('_', ' ')->title() }} · {{ $location->floor?->name ?: 'Building-level' }}</p></div><x-status-badge :status="$location->isOperational() ? 'ACTIVE' : 'INACTIVE'" /></div>
                @empty
                    <x-empty-state title="No offices, rooms or areas have been added yet" description="Add locations to make detailed Work Order reporting easier." />
                @endforelse
            </div>
            @can('locations.create')<a class="btn btn-primary mt-5" href="{{ route('locations.areas', ['building_id' => $building->id]) }}">+ Add Location</a>@endcan
        </section>
    </div>
@endsection
