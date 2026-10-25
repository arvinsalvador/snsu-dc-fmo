@extends('layouts.app')
@section('title', $queue === 'active' ? 'Active Work' : 'For Verification')
@section('content')
    @php
        $activeQueue = $queue === 'active';
    @endphp
    <x-page-header
        :title="$activeQueue ? 'Active Work' : 'For Verification'"
        :description="$activeQueue ? 'Monitor Work Orders that currently have active or ongoing execution.' : 'Review completed work submitted by FMO personnel before final completion.'"
    />

    <section class="mt-6 grid gap-4 sm:grid-cols-2" aria-label="{{ $activeQueue ? 'Active work' : 'Verification' }} summary">
        @if($activeQueue)
            <x-stat-card label="Active Jobs" :value="$summary['jobs']" icon="wrench" />
            <x-stat-card label="Active Sessions" :value="$summary['sessions']" icon="play" />
        @else
            <x-stat-card label="Awaiting Verification" :value="$summary['waiting']" icon="shield-check" />
            <x-stat-card label="Submitted Today" :value="$summary['submitted_today']" icon="check-circle" />
        @endif
    </section>

    <form class="section-card mt-6" method="GET">
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-12">
            <label class="md:col-span-2 xl:col-span-4">Search<input name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="Work Order, requester, subject or location..."></label>
            <label class="xl:col-span-2">Work Type<select name="category"><option value="">All work types</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($filters['category'] === $category->id)>{{ $category->name }}</option>@endforeach</select></label>
            <label class="xl:col-span-2">Assigned Personnel<select name="personnel"><option value="">All personnel</option>@foreach($personnel as $person)<option value="{{ $person->id }}" @selected($filters['personnel'] === $person->id)>{{ $person->user->name }}</option>@endforeach</select></label>
            <label class="xl:col-span-2">Priority<select name="priority"><option value="">All priorities</option><option value="URGENT" @selected($filters['priority'] === 'URGENT')>Urgent</option><option value="NORMAL" @selected($filters['priority'] === 'NORMAL')>Normal</option></select></label>
            @if(!$activeQueue)<label class="xl:col-span-2">Submitted<select name="submitted"><option value="">Any date</option><option value="7" @selected($filters['submitted'] === '7')>Last 7 days</option><option value="30" @selected($filters['submitted'] === '30')>Last 30 days</option><option value="90" @selected($filters['submitted'] === '90')>Last 90 days</option></select></label>@endif
            <label class="xl:col-span-2">Per Page<select name="per_page">@foreach([20, 50, 100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>@endforeach</select></label>
            <label class="md:col-span-2 xl:col-span-4">Sort<select name="sort">@if($activeQueue)<option value="latest_activity" @selected($filters['sort'] === 'latest_activity')>Latest Activity</option><option value="oldest_activity" @selected($filters['sort'] === 'oldest_activity')>Longest Running</option>@else<option value="oldest_waiting" @selected($filters['sort'] === 'oldest_waiting')>Longest Waiting</option><option value="newest_waiting" @selected($filters['sort'] === 'newest_waiting')>Newest Submitted</option>@endif<option value="highest_priority" @selected($filters['sort'] === 'highest_priority')>Highest Priority</option></select></label>
        </div>
        <div class="mt-5 flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end"><a class="btn btn-secondary" href="{{ route('work-orders.execution-queue', $queue) }}">Clear Filters</a><button class="btn btn-primary" type="submit"><x-icon name="filter" />Apply Filters</button></div>
    </form>

    @if($orders->isEmpty())
        <div class="mt-6"><x-empty-state :title="$hasFilters ? 'No matching records' : ($activeQueue ? 'No active Work Orders' : 'Nothing waiting for verification')" :description="$hasFilters ? 'Try changing your search or filters.' : ($activeQueue ? 'Work in progress and continuing jobs will appear here.' : 'No completed Work Orders currently require review.')" :icon="$activeQueue ? 'play' : 'shield-check'">@if($hasFilters)<a class="btn btn-secondary mt-4" href="{{ route('work-orders.execution-queue', $queue) }}">Clear Filters</a>@endif</x-empty-state></div>
    @else
        <div class="table-wrap mt-6 hidden lg:block"><table class="min-w-[72rem]"><thead><tr><th>Work Order</th><th>Requester</th><th>Work Type</th>@if($activeQueue)<th>Location</th><th>Assigned Team / Session</th><th>Started</th><th>Latest Update</th>@else<th>Assigned Team</th><th>Submitted</th><th>Evidence</th>@endif<th>Status</th><th class="text-right">Action</th></tr></thead><tbody>
            @foreach($orders as $order)
                @php
                    $mySession = $order->activeSessions->first(fn ($session) => $session->personnel?->user_id === auth()->id());
                    $latestUpdate = $order->updates->first();
                    $team = $order->activeAssignments->pluck('personnel.user.name')->filter()->join(', ');
                @endphp
                <tr>
                    <td><a class="font-semibold text-blue-800 hover:underline" href="{{ route('work-orders.show', $order) }}">{{ $order->work_order_number }}</a><p class="mt-1 max-w-xs text-sm text-slate-600">{{ $order->subject }}</p></td>
                    <td>{{ $order->requester->name }}</td><td>{{ $order->category->name }}</td>
                    @if($activeQueue)
                        <td><span class="font-medium text-slate-800">{{ $order->building->name }}</span><span class="mt-1 block text-xs text-slate-500">{{ collect([$order->floor?->name, $order->location?->name])->filter()->join(' • ') ?: 'Building-wide' }}</span></td>
                        <td><span class="block max-w-56 text-sm">{{ $team ?: 'No active assignee' }}</span>@if($mySession)<span class="mt-1 inline-flex rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-800">Your active session</span>@elseif($order->active_sessions_count)<span class="mt-1 block text-xs text-slate-500">{{ $order->active_sessions_count }} active {{ str('session')->plural($order->active_sessions_count) }}</span>@endif</td>
                        <td class="whitespace-nowrap">{{ $order->activeSessions->min('started_at')?->timezone('Asia/Manila')->format('M j, g:i A') ?? '—' }}</td>
                        <td>@if($latestUpdate)<span class="block max-w-52 text-sm text-slate-700">{{ str($latestUpdate->description)->limit(70) }}</span><span class="mt-1 block whitespace-nowrap text-xs text-slate-500">{{ $latestUpdate->recorded_at?->timezone('Asia/Manila')->diffForHumans() }}</span>@else<span class="text-slate-400">No update yet</span>@endif</td>
                    @else
                        <td><span class="block max-w-56 text-sm">{{ $team ?: 'No active assignee' }}</span></td>
                        <td class="whitespace-nowrap">{{ $order->completion_submitted_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? '—' }}</td>
                        <td><span class="font-semibold text-slate-800">{{ $order->completion_evidence_count }} {{ str('photo')->plural($order->completion_evidence_count) }}</span></td>
                    @endif
                    <td><x-status-badge :status="$order->status" /></td>
                    <td class="text-right"><a class="btn {{ $activeQueue && $mySession ? 'btn-primary' : ($activeQueue ? 'btn-secondary' : 'btn-success') }} !min-h-9 !px-3" href="{{ route('work-orders.show', $order).($activeQueue ? '#field-actions' : '#completion') }}">{{ $activeQueue ? ($mySession ? 'Continue Work' : 'View Work') : 'Review Completion' }}</a></td>
                </tr>
            @endforeach
        </tbody></table></div>

        <div class="mt-6 grid gap-4 lg:hidden">@foreach($orders as $order)@php($mySession = $order->activeSessions->first(fn ($session) => $session->personnel?->user_id === auth()->id()))<article class="app-card p-4"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><a class="font-semibold text-blue-800" href="{{ route('work-orders.show', $order) }}">{{ $order->work_order_number }}</a><h2 class="mt-1 text-sm leading-5">{{ $order->subject }}</h2></div><x-status-badge :status="$order->status" /></div><dl class="mt-4 grid gap-3 sm:grid-cols-2"><div><dt class="detail-label">Requester</dt><dd class="mt-1 text-sm">{{ $order->requester->name }}</dd></div><div><dt class="detail-label">Work Type</dt><dd class="mt-1 text-sm">{{ $order->category->name }}</dd></div>@if($activeQueue)<div><dt class="detail-label">Location</dt><dd class="mt-1 text-sm">{{ $order->building->name }}</dd></div><div><dt class="detail-label">Session</dt><dd class="mt-1 text-sm">{{ $mySession ? 'Your active session' : $order->active_sessions_count.' active' }}</dd></div>@else<div><dt class="detail-label">Submitted</dt><dd class="mt-1 text-sm">{{ $order->completion_submitted_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? '—' }}</dd></div><div><dt class="detail-label">Evidence</dt><dd class="mt-1 text-sm">{{ $order->completion_evidence_count }} {{ str('photo')->plural($order->completion_evidence_count) }}</dd></div>@endif</dl><a class="btn {{ $activeQueue && $mySession ? 'btn-primary' : ($activeQueue ? 'btn-secondary' : 'btn-success') }} mt-4 w-full" href="{{ route('work-orders.show', $order).($activeQueue ? '#field-actions' : '#completion') }}">{{ $activeQueue ? ($mySession ? 'Continue Work' : 'View Work') : 'Review Completion' }}</a></article>@endforeach</div>
        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-slate-500">Showing {{ $orders->firstItem() }}–{{ $orders->lastItem() }} of {{ $orders->total() }} Work Orders</p>{{ $orders->onEachSide(1)->links() }}</div>
    @endif
@endsection
