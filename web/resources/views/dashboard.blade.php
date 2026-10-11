@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
@php
    $staffContext = ! $management && auth()->user()->can('work_orders.view_assigned');
    $pageTitle = $management ? 'Work Order Overview' : ($staffContext ? 'My Task Overview' : 'My Requests');
    $pageDescription = $management
        ? 'A focused view of operational queues and work requiring attention.'
        : ($staffContext ? 'Prioritize assigned field work and track your active operational states.' : 'Track submitted facilities requests and items that need your response.');
    $dashboardCards = $management
        ? [
            ['New requests', $counts['SUBMITTED'] ?? 0, 'SUBMITTED', 'clipboard'],
            ['For screening', $counts['FOR_SCREENING'] ?? 0, 'FOR_SCREENING', 'search'],
            ['For approval', $counts['FOR_APPROVAL'] ?? 0, 'FOR_APPROVAL', 'check-circle'],
            ['Awaiting assignment', $counts['APPROVED'] ?? 0, 'APPROVED', 'assignment'],
            ['Assessment review', $counts['ASSESSMENT_REVIEW'] ?? 0, 'ASSESSMENT_REVIEW', 'assessment'],
            ['Active work', $counts['IN_PROGRESS'] ?? 0, 'IN_PROGRESS', 'play'],
            ['For verification', $counts['FOR_VERIFICATION'] ?? 0, 'FOR_VERIFICATION', 'shield-check'],
            ['Completed', $counts['COMPLETED'] ?? 0, 'COMPLETED', 'check-circle'],
        ]
        : ($staffContext
            ? [
                ['Needs assessment', $counts['FOR_ASSESSMENT'] ?? 0, 'FOR_ASSESSMENT', 'assessment'],
                ['Ready for work', $counts['READY_FOR_WORK'] ?? 0, 'READY_FOR_WORK', 'check-circle'],
                ['Active work', $counts['IN_PROGRESS'] ?? 0, 'IN_PROGRESS', 'play'],
                ['For continuation', $counts['FOR_CONTINUATION'] ?? 0, 'FOR_CONTINUATION', 'clock'],
                ['Waiting for materials', $counts['WAITING_FOR_MATERIALS'] ?? 0, 'WAITING_FOR_MATERIALS', 'warning'],
                ['For verification', $counts['FOR_VERIFICATION'] ?? 0, 'FOR_VERIFICATION', 'shield-check'],
            ]
            : [
                ['Open requests', collect($counts)->except(['COMPLETED', 'DISAPPROVED', 'CANCELLED'])->sum(), null, 'clipboard'],
                ['Needs my response', $counts['NEEDS_INFORMATION'] ?? 0, 'NEEDS_INFORMATION', 'warning'],
                ['In progress', collect($counts)->only(['ASSIGNED', 'FOR_ASSESSMENT', 'ASSESSMENT_REVIEW', 'READY_FOR_WORK', 'IN_PROGRESS', 'FOR_CONTINUATION', 'PAUSED', 'WAITING_FOR_MATERIALS', 'NEEDS_INVESTIGATION', 'FOR_VERIFICATION'])->sum(), null, 'play'],
                ['Completed', $counts['COMPLETED'] ?? 0, 'COMPLETED', 'check-circle'],
            ]);
@endphp
<x-page-header :title="$pageTitle" :description="$pageDescription">
    <x-slot:action>
        <div class="flex flex-wrap gap-2">
            @can('work-orders.create-request')<a class="btn btn-primary" href="{{ route('work-orders.create') }}"><x-icon name="plus" />New Request</a>@endcan
            <a class="btn btn-secondary" href="{{ route('reports.history') }}"><x-icon name="chart" />Work history</a>
        </div>
    </x-slot:action>
</x-page-header>
<form method="GET" action="{{ route('dashboard') }}" class="filter-bar mt-6"><label>From<input type="date" name="from" value="{{ $from }}"></label><label>Through<input type="date" name="to" value="{{ $to }}"></label><button class="btn btn-primary" type="submit"><x-icon name="filter" />Apply period</button></form>
<section class="mt-8"><div><h2 class="text-lg">Current Work Orders</h2><p class="mt-1 text-sm text-slate-500">{{ $management ? 'Operational states across your authorized scope.' : 'Only Work Orders within your requester or assignment scope are included.' }}</p></div>
<div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
@foreach ($dashboardCards as [$label, $value, $status, $icon])
    <x-stat-card :label="$label" :value="$value" :icon="$icon" :href="$status ? route('reports.history', ['status' => $status]) : route('reports.history')" />
@endforeach
</div></section>
<section class="mt-8"><h2 class="text-lg">Selected Period</h2><div class="mt-4 grid gap-4 sm:grid-cols-3"><x-stat-card label="Submitted" :value="$periodSubmitted" icon="clipboard" /><x-stat-card label="Verified complete" :value="$periodCompleted" icon="check-circle" /><x-stat-card label="Average time to completion" :value="$averageHours === null ? '—' : number_format($averageHours, 1).' hours'" icon="clock" /></div></section>
@if ($management)
<div class="mt-8 grid gap-6 xl:grid-cols-2"><section class="section-card"><h2 class="text-lg">Needs Attention</h2><p class="mt-1 text-sm text-slate-500">Age indicators help prioritize review; they are not SLA violations.</p><div class="mt-5 space-y-3">@forelse($attention as $order) @php($hours = \Carbon\Carbon::parse($order->state_entered_at ?? $order->updated_at, 'Asia/Manila')->diffInHours(now())) @php($threshold = config('reporting.attention_hours.'.$order->status->value))
    <a href="{{ route('work-orders.show', $order) }}" class="block rounded-lg border border-slate-200 p-4 transition hover:border-blue-300 hover:bg-blue-50/30"><div class="flex flex-wrap items-center justify-between gap-2"><span class="font-semibold text-blue-800">{{ $order->work_order_number }}</span><x-status-badge :status="$order->status" /></div><p class="mt-1 font-medium text-slate-800">{{ $order->subject }}</p><p class="mt-1 text-sm text-slate-500">{{ $order->building->name }} · {{ number_format($hours, 0) }} hours in current state @if($threshold && $hours >= $threshold) · Review recommended @endif</p></a>
@empty <x-empty-state title="Nothing currently needs attention" description="Operational items needing review will appear here." /> @endforelse</div></section>
<section class="section-card"><h2 class="text-lg">Active Staff Sessions</h2><p class="mt-1 text-sm text-slate-500">Current on-site work, visible to authorized management.</p><div class="mt-5 space-y-3">@forelse($activeSessions as $session)<a href="{{ route('work-orders.show', $session->workOrder) }}" class="block rounded-lg border border-slate-200 p-4 transition hover:border-blue-300"><p class="font-semibold text-slate-800">{{ $session->personnel->user->name }} <span class="font-normal text-slate-500">· {{ $session->workOrder->work_order_number }}</span></p><p class="mt-1 text-sm text-slate-500">{{ $session->workOrder->building->name }} · Active {{ number_format($session->started_at->diffInHours(now()), 0) }} hours</p></a>@empty <x-empty-state title="No active work sessions" description="Active staff sessions will be displayed here." /> @endforelse</div></section></div>
<section class="mt-8 section-card"><h2 class="text-lg">Personnel Open Workload</h2><p class="mt-1 text-sm text-slate-500">Current assignment counts, not performance ratings.</p><div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">@forelse($personnelWorkload as $person)<div class="rounded-lg border border-slate-200 px-4 py-3"><p class="font-medium text-slate-800">{{ $person->user->name }}</p><p class="mt-1 text-sm text-slate-500">{{ $person->open_workload }} open Work Orders</p></div>@empty <p class="text-sm text-slate-500">No active personnel profiles.</p>@endforelse</div></section>
@endif
@endsection
