@extends('layouts.app')
@section('title', $title)
@section('content')
@php
    $filterRoute = ['all' => 'transport.index', 'mine' => 'transport.mine', 'history' => 'transport.history'][$mode];
    $count = fn (string ...$keys) => collect($keys)->sum(fn ($key) => (int) ($counts[$key] ?? 0));
    $advanced = collect(['requester', 'destination', 'driver', 'vehicle', 'from', 'to'])->contains(fn ($key) => request()->filled($key));
    $tabs = [
        '' => ['All', $count(...array_map(fn ($s) => $s->value, $statuses))],
        'PENDING_APPROVAL' => ['Pending approval', $count('PENDING_APPROVAL')],
        'APPROVED' => ['Approved', $count('APPROVED')],
        'ASSIGNED' => ['Assigned', $count('ASSIGNED')],
        'COMPLETED' => ['Completed', $count('COMPLETED')],
        'REJECTED' => ['Rejected', $count('REJECTED')],
        'CANCELLED' => ['Cancelled', $count('CANCELLED')],
    ];
    $dt = fn ($value) => $value?->format('M j, Y');
@endphp
<x-page-header :title="$title" :description="$description">
    @can('transport.request')
        <x-slot:action><a class="btn btn-primary" href="{{ route('transport.create') }}"><x-icon name="plus" />New Transport Request</a></x-slot:action>
    @endcan
</x-page-header>

<section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Summary">
    @if ($mode === 'history')
        <x-stat-card label="Completed trips" :value="number_format($summary->trips ?? 0)" icon="check-circle" hint="Matches the records you may view" />
        <x-stat-card label="Distance travelled" :value="number_format($summary->distance ?? 0).' km'" icon="gauge" hint="Sum of recorded trip distances" />
        <x-stat-card label="Vehicles used" :value="number_format($summary->vehicles ?? 0)" icon="truck" hint="Distinct vehicles on completed trips" />
        <x-stat-card label="Showing" :value="number_format($requests->total())" icon="filter" hint="Trips matching the current filters" />
    @else
        <x-stat-card label="Pending approval" :value="$count('PENDING_APPROVAL')" icon="clock" :href="route($filterRoute, ['status' => 'PENDING_APPROVAL'])" hint="Waiting for a decision" />
        <x-stat-card label="Awaiting assignment" :value="$count('APPROVED')" icon="assignment" :href="route($filterRoute, ['status' => 'APPROVED'])" hint="Approved, no driver or vehicle yet" />
        <x-stat-card label="Scheduled trips" :value="$count('ASSIGNED')" icon="truck" :href="route($filterRoute, ['status' => 'ASSIGNED'])" hint="Driver and vehicle assigned" />
        <x-stat-card label="Completed this month" :value="$completedThisMonth" icon="check-circle" :href="route($filterRoute, ['status' => 'COMPLETED'])" hint="Trip records closed" />
    @endif
</section>

<form class="mt-6 space-y-3" method="GET" action="{{ route($filterRoute) }}">
    @if ($mode !== 'history')
        <nav class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1" aria-label="Filter by status">
            @foreach ($tabs as $value => [$label, $total])
                @php
                    $active = (request('status') ?? '') === $value;
                @endphp
                <a href="{{ route($filterRoute, array_merge(request()->except(['status', 'page']), $value === '' ? [] : ['status' => $value])) }}" @if ($active) aria-current="page" @endif
                   class="inline-flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-semibold transition {{ $active ? 'border-blue-700 bg-blue-700 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:bg-blue-50' }}">
                    {{ $label }}<span class="rounded-full px-1.5 text-xs {{ $active ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600' }}">{{ $total }}</span>
                </a>
            @endforeach
        </nav>
        @if (request()->filled('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
    @endif
    <div class="app-card p-4">
        <div class="flex flex-wrap items-end gap-3">
            <label class="min-w-56 flex-1">Search
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Trip no., purpose, origin, or destination">
            </label>
            <button class="btn btn-primary" type="submit"><x-icon name="search" />Search</button>
            <a class="btn btn-ghost" href="{{ route($filterRoute) }}">Reset</a>
        </div>
        <details class="mt-3 border-t border-slate-100 pt-3" @if ($advanced) open @endif>
            <summary class="inline-flex cursor-pointer items-center gap-1.5 text-sm font-semibold text-blue-800 focus:outline-none focus-visible:ring-3 focus-visible:ring-blue-100"><x-icon name="filter" size="size-4" />More filters</summary>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @if ($mode !== 'mine')<label>Requester<input name="requester" value="{{ request('requester') }}"></label>@endif
                <label>Destination<input name="destination" value="{{ request('destination') }}"></label>
                @if ($drivers->isNotEmpty())
                    <label>Driver<select name="driver"><option value="">All drivers</option>@foreach ($drivers as $driver)<option value="{{ $driver->id }}" @selected(request('driver') === $driver->id)>{{ $driver->user->name }}</option>@endforeach</select></label>
                @endif
                @if ($vehicles->isNotEmpty())
                    <label>Vehicle<select name="vehicle"><option value="">All vehicles</option>@foreach ($vehicles as $vehicle)<option value="{{ $vehicle->id }}" @selected(request('vehicle') === $vehicle->id)>{{ $vehicle->name }}</option>@endforeach</select></label>
                @endif
                <label>Travel date from<input type="date" name="from" value="{{ request('from') }}"></label>
                <label>Travel date to<input type="date" name="to" value="{{ request('to') }}"></label>
                <label>Rows per page<select name="per_page">@foreach ([20, 50, 100] as $size)<option value="{{ $size }}" @selected((int) request('per_page', 20) === $size)>{{ $size }}</option>@endforeach</select></label>
            </div>
        </details>
    </div>
</form>

<div class="table-wrap mobile-card-table mt-6">
    <table>
        <thead>
            @if ($mode === 'history')
                <tr><th>Trip No.</th><th>Date</th><th>Requester</th><th>Route</th><th>Driver</th><th>Vehicle</th><th class="text-right">Distance</th><th>Status</th><th class="text-right">Action</th></tr>
            @else
                <tr><th>Trip</th><th>Route</th>@if ($mode === 'all')<th>Requester</th>@endif<th>Driver &amp; vehicle</th><th>Status</th><th class="text-right">Action</th></tr>
            @endif
        </thead>
        <tbody>
        @forelse ($requests as $item)
            @if ($mode === 'history')
                <tr>
                    <td><a class="font-semibold text-blue-800 hover:underline" href="{{ route('transport.show', $item) }}">{{ $item->request_number }}</a></td>
                    <td data-label="Date" class="whitespace-nowrap">{{ $dt($item->actual_departed_at ?? $item->travel_date) }}</td>
                    <td data-label="Requester"><span class="inline-flex items-center gap-2"><x-avatar :name="$item->requester->name" size="sm" />{{ $item->requester->name }}</span></td>
                    <td data-label="Route"><x-transport-route :from="$item->origin" :to="$item->destination" compact class="justify-end sm:justify-start" /></td>
                    <td data-label="Driver">{{ $item->driver?->user?->name ?? '—' }}</td>
                    <td data-label="Vehicle">{{ $item->vehicle?->name ?? '—' }}</td>
                    <td data-label="Distance" class="whitespace-nowrap text-right font-semibold text-slate-900">{{ $item->distance_km !== null ? number_format($item->distance_km).' km' : '—' }}</td>
                    <td data-label="Status"><x-status-badge :status="$item->status" /></td>
                    <td data-label="Action" class="text-right"><a class="btn btn-secondary !min-h-9 !px-3" href="{{ route('transport.show', $item) }}"><x-icon name="eye" size="size-4" />View</a></td>
                </tr>
            @else
                <tr>
                    <td>
                        <a class="font-semibold text-blue-800 hover:underline" href="{{ route('transport.show', $item) }}">{{ $item->request_number }}</a>
                        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs font-normal text-slate-500">
                            <span class="inline-flex items-center gap-1"><x-icon name="calendar" size="size-3.5" />{{ $dt($item->travel_date) }}</span>
                            <span class="inline-flex items-center gap-1"><x-icon name="users" size="size-3.5" />{{ $item->passenger_count }}</span>
                        </p>
                    </td>
                    <td data-label="Route">
                        <x-transport-route :from="$item->origin" :to="$item->destination" compact class="justify-end sm:justify-start" />
                        <p class="mt-0.5 text-xs font-normal text-slate-500">{{ \Illuminate\Support\Str::limit($item->purpose, 70) }}</p>
                    </td>
                    @if ($mode === 'all')
                        <td data-label="Requester"><span class="inline-flex items-center gap-2"><x-avatar :name="$item->requester->name" size="sm" />{{ $item->requester->name }}</span></td>
                    @endif
                    <td data-label="Driver & vehicle">
                        @if ($item->driver)
                            <p class="font-medium text-slate-900">{{ $item->driver->user?->name }}</p>
                            <p class="text-xs text-slate-500">{{ $item->vehicle?->name }}</p>
                        @else
                            <span class="text-sm text-slate-400">Not yet assigned</span>
                        @endif
                    </td>
                    <td data-label="Status"><x-status-badge :status="$item->status" /></td>
                    <td data-label="Action" class="text-right"><a class="btn btn-secondary !min-h-9 !px-3" href="{{ route('transport.show', $item) }}"><x-icon name="eye" size="size-4" />View</a></td>
                </tr>
            @endif
        @empty
            <tr><td colspan="9">
                <x-empty-state :title="$mode === 'history' ? 'No completed trips yet' : 'No trip requests found'" :description="$mode === 'history' ? 'Trips appear here once the driver completes the trip record.' : 'Adjust the filters, or submit a new transport request.'" icon="truck" />
            </td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-5">{{ $requests->links() }}</div>
@endsection
