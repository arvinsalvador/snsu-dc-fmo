@extends('layouts.app')

@section('title', 'Campuses')

@section('content')
    <x-page-header title="Campuses" description="Manage university campuses available for Work Order locations.">
        <x-slot:action><a class="btn btn-secondary" href="{{ route('locations.index') }}">Location Management</a></x-slot:action>
    </x-page-header>

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Campus</th><th>Code</th><th>Status</th><th>Buildings</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($campuses as $campus)
                        <tr>
                            <td class="font-semibold text-slate-900">{{ $campus->name }}</td>
                            <td>{{ $campus->code }}</td>
                            <td><x-status-badge :status="$campus->is_active ? 'ACTIVE' : 'INACTIVE'" /></td>
                            <td>{{ $campus->buildings_count }}</td>
                            <td class="text-right">
                                <a class="btn btn-secondary !min-h-9 !px-3" href="{{ route('buildings.index', ['campus_id' => $campus->id]) }}">View buildings</a>
                                @can('campuses.update')
                                    <details class="relative inline-block text-left">
                                        <summary class="btn btn-ghost !min-h-9 !px-3">Edit</summary>
                                        <form class="absolute right-0 z-10 mt-2 w-80 space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-lg" method="POST" action="{{ route('campuses.update', $campus) }}">
                                            @csrf @method('PUT')
                                            <label>Campus name<input name="name" value="{{ $campus->name }}" required></label>
                                            <label>Code<input name="code" value="{{ $campus->code }}" required></label>
                                            <label>Short name<input name="short_name" value="{{ $campus->short_name }}"></label>
                                            <label class="flex items-center gap-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($campus->is_active)> Active</label>
                                            <button class="btn btn-primary w-full" type="submit">Save campus</button>
                                        </form>
                                    </details>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty-state title="No campuses configured" description="Add a campus before creating its buildings." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @can('campuses.create')
            <form class="section-card h-fit space-y-4" method="POST" action="{{ route('campuses.store') }}">
                @csrf
                <div><h2 class="text-lg">Add Campus</h2><p class="mt-1 text-sm text-slate-500">Create a campus available for master-data setup.</p></div>
                <label>Campus name <span class="text-rose-600">*</span><input name="name" value="{{ old('name') }}" required></label>
                <label>Code <span class="text-rose-600">*</span><input name="code" value="{{ old('code') }}" required></label>
                <label>Short name<input name="short_name" value="{{ old('short_name') }}"></label>
                <input type="hidden" name="is_active" value="1">
                <button class="btn btn-primary w-full" type="submit">Add campus</button>
            </form>
        @endcan
    </div>
@endsection
