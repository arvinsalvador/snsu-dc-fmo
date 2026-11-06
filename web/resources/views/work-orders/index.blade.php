@extends('layouts.app')
@section('title', $all ? 'All Work Orders' : 'My Requests')
@section('content')
@php
    $route = $all ? 'work-orders.index' : 'work-orders.mine';
    $advanced = request()->filled('status');
    $total = $stageCounts->sum();
    $active = request('stage');
@endphp
<x-page-header
    :title="$all ? 'All Work Orders' : 'My Requests'"
    :description="$all ? 'Review authorized facilities requests across the campus.' : 'View and track the facilities requests you have submitted.'"
>
    @can('work-orders.create-request')
        <x-slot:action>
            <a class="btn btn-primary" href="{{ route('work-orders.create') }}"><x-icon name="plus" />New Request</a>
        </x-slot:action>
    @endcan
</x-page-header>

<section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Summary">
    <x-stat-card label="Total requests" :value="$total" icon="clipboard" :href="route($route)" hint="Everything you can see" />
    <x-stat-card :label="$all ? 'Needs review' : 'Being reviewed'" :value="$stageCounts['review']" icon="search" :href="route($route, ['stage' => 'review'])" hint="Submitted, screening, or approval" />
    <x-stat-card label="In progress" :value="$stageCounts['assignment'] + $stageCounts['active'] + $stageCounts['verification']" icon="wrench" :href="route($route, ['stage' => 'active'])" hint="Assigned, assessed, or being worked on" />
    <x-stat-card label="Urgent and open" :value="$urgentOpen" icon="warning" :tone="$urgentOpen > 0 ? 'danger' : 'default'" hint="Flagged urgent by the requester" />
</section>

<form class="mt-6 space-y-3" method="GET" action="{{ route($route) }}">
    <nav class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1" aria-label="Filter by stage">
        <a href="{{ route($route, request()->except(['stage', 'page'])) }}" @if (! $active) aria-current="page" @endif
           class="inline-flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-semibold transition {{ ! $active ? 'border-blue-700 bg-blue-700 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:bg-blue-50' }}">All<span class="rounded-full px-1.5 text-xs {{ ! $active ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600' }}">{{ $total }}</span></a>
        @foreach ($stages as $key => [$label])
            @php $on = $active === $key; @endphp
            <a href="{{ route($route, array_merge(request()->except(['stage', 'page']), ['stage' => $key])) }}" @if ($on) aria-current="page" @endif
               class="inline-flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-semibold transition {{ $on ? 'border-blue-700 bg-blue-700 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:bg-blue-50' }}">{{ $label }}<span class="rounded-full px-1.5 text-xs {{ $on ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600' }}">{{ $stageCounts[$key] }}</span></a>
        @endforeach
    </nav>
    @if ($active)<input type="hidden" name="stage" value="{{ $active }}">@endif
    <div class="app-card p-4">
        <div class="flex flex-wrap items-end gap-3">
            <label class="min-w-56 flex-1">Search
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Work Order number or subject">
            </label>
            <button class="btn btn-primary" type="submit"><x-icon name="search" />Search</button>
            <a class="btn btn-ghost" href="{{ route($route) }}">Reset</a>
        </div>
        <details class="mt-3 border-t border-slate-100 pt-3" @if ($advanced) open @endif>
            <summary class="inline-flex cursor-pointer items-center gap-1.5 text-sm font-semibold text-blue-800 focus:outline-none focus-visible:ring-3 focus-visible:ring-blue-100"><x-icon name="filter" size="size-4" />Filter by exact status</summary>
            <div class="mt-3 max-w-xs">
                <label>Status
                    <select name="status">
                        <option value="">All statuses</option>
                        @foreach (\App\Enums\WorkOrderStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ \Illuminate\Support\Str::of($status->value)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </details>
    </div>
</form>

<div class="table-wrap mobile-card-table mt-6">
    <table>
        <thead>
            <tr>
                <th>Work Order</th>
                <th>Category &amp; location</th>
                @if ($all)<th>Requester</th>@endif
                <th>Submitted</th>
                <th>Status</th>
                <th class="text-right">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td>
                        <div class="flex flex-wrap items-center gap-2">
                            <a class="font-semibold text-blue-800 hover:underline" href="{{ route('work-orders.show', $order) }}">{{ $order->work_order_number }}</a>
                            @if ($order->urgency === 'URGENT')<span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-800 ring-1 ring-inset ring-rose-200"><x-icon name="warning" size="size-3.5" />Urgent</span>@endif
                            @if (($order->unread_messages_count ?? 0) > 0)
                                <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800"><x-icon name="mail" size="size-3.5" />{{ $order->unread_messages_count }} unread {{ \Illuminate\Support\Str::plural('message', $order->unread_messages_count) }}</span>
                            @endif
                        </div>
                        <p class="mt-1 max-w-md text-sm font-medium text-slate-800">{{ \Illuminate\Support\Str::limit($order->subject, 90) }}</p>
                    </td>
                    <td data-label="Category & location">
                        <p class="inline-flex items-center gap-1.5 text-sm text-slate-800"><x-icon name="tag" size="size-4" class="text-slate-400" />{{ $order->category->name }}</p>
                        <p class="mt-0.5 inline-flex items-center gap-1.5 text-xs text-slate-500"><x-icon name="map-pin" size="size-3.5" />{{ $order->building->name }}@if ($order->location) · {{ $order->location->name }}@endif</p>
                    </td>
                    @if ($all)
                        <td data-label="Requester"><span class="inline-flex items-center gap-2"><x-avatar :name="$order->requester?->name ?? '—'" size="sm" />{{ $order->requester?->name }}</span></td>
                    @endif
                    <td data-label="Submitted" class="whitespace-nowrap">
                        {{ $order->submitted_at?->timezone('Asia/Manila')->format('M j, Y') }}
                        <p class="text-xs text-slate-500">{{ $order->submitted_at?->diffForHumans() }}</p>
                    </td>
                    <td data-label="Status"><x-status-badge :status="$order->status" /></td>
                    <td data-label="Action" class="text-right">
                        <a class="btn btn-secondary !min-h-9 !px-3" href="{{ route('work-orders.show', $order) }}"><x-icon name="eye" size="size-4" />View</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $all ? 6 : 5 }}">
                        <x-empty-state title="No Work Orders found" description="There are no Work Orders matching your current filters." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-5">{{ $orders->links() }}</div>
@endsection
