@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<div class="flex flex-wrap items-end justify-between gap-4">
    <div><h1 class="text-2xl font-semibold">{{ $management ? 'Work Order overview' : 'My Work Orders' }}</h1>
        <p class="mt-1 text-slate-600">{{ $management ? 'Operational attention and current workload' : 'Your authorized requests and field work' }}</p></div>
    <a class="text-blue-700 underline" href="{{ route('reports.history') }}">Browse Work Order history</a>
</div>
<form method="GET" action="{{ route('dashboard') }}" class="mt-5 flex flex-wrap items-end gap-3 rounded border bg-white p-4">
    <label>Period from <input class="block rounded border p-2" type="date" name="from" value="{{ $from }}"></label>
    <label>through <input class="block rounded border p-2" type="date" name="to" value="{{ $to }}"></label>
    <button class="rounded bg-blue-700 px-4 py-2 text-white">Apply period</button>
</form>
<h2 class="mt-7 text-lg font-semibold">Current Work Orders</h2>
<div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    @foreach ([
        'SUBMITTED' => 'New submitted', 'FOR_SCREENING' => 'For screening', 'NEEDS_INFORMATION' => 'Needs information',
        'FOR_APPROVAL' => 'For approval', 'APPROVED' => 'Awaiting assignment', 'ASSIGNED' => 'Assigned',
        'FOR_ASSESSMENT' => 'For assessment', 'ASSESSMENT_REVIEW' => 'Assessment review',
        'READY_FOR_WORK' => 'Ready for work', 'IN_PROGRESS' => 'In progress',
        'FOR_CONTINUATION' => 'For continuation', 'PAUSED' => 'Paused',
        'WAITING_FOR_MATERIALS' => 'Waiting for materials', 'NEEDS_INVESTIGATION' => 'Needs investigation',
        'FOR_VERIFICATION' => 'For verification', 'COMPLETED' => 'Completed',
    ] as $status => $label)
        <a href="{{ route('reports.history', ['status' => $status]) }}" class="rounded border bg-white p-4 hover:border-blue-400">
            <p class="text-sm text-slate-600">{{ $label }}</p>
            <p class="mt-1 text-2xl font-semibold">{{ $counts[$status] ?? 0 }}</p>
        </a>
    @endforeach
</div>
<h2 class="mt-7 text-lg font-semibold">Selected period</h2>
<div class="mt-3 grid gap-3 sm:grid-cols-3">
    <div class="rounded border bg-white p-4"><p>Submitted</p><strong class="text-2xl">{{ $periodSubmitted }}</strong></div>
    <div class="rounded border bg-white p-4"><p>Verified complete</p><strong class="text-2xl">{{ $periodCompleted }}</strong></div>
    <div class="rounded border bg-white p-4"><p>Average submission-to-completion</p><strong class="text-2xl">{{ $averageHours === null ? '—' : number_format($averageHours, 1).' hours' }}</strong></div>
</div>
@if ($management)
<div class="mt-8 grid gap-6 lg:grid-cols-2">
    <section><h2 class="text-lg font-semibold">Needs attention</h2><p class="text-sm text-slate-600">Age indicators are not SLA violations.</p>
        <div class="mt-3 space-y-2">@forelse ($attention as $order)
            @php($hours = \Carbon\Carbon::parse($order->state_entered_at ?? $order->updated_at, 'Asia/Manila')->diffInHours(now()))
            @php($threshold = config('reporting.attention_hours.'.$order->status->value))
            <a href="{{ route('work-orders.show', $order) }}" class="block rounded border bg-white p-3">
                <strong>{{ $order->work_order_number }}</strong> · {{ $order->subject }}
                <p class="text-sm">{{ str_replace('_', ' ', $order->status->value) }} · {{ $order->building->name }} · {{ number_format($hours, 0) }} hours in current state
                    @if ($threshold && $hours >= $threshold)<span class="text-amber-800">· Review recommended</span>@endif</p>
                @if ($order->status === \App\Enums\WorkOrderStatus::WaitingForMaterials && $order->material_note)<p class="text-sm">Resources: {{ $order->material_note }}</p>@endif
                @if ($order->status === \App\Enums\WorkOrderStatus::ForContinuation && $order->remaining_note)<p class="text-sm">Remaining: {{ $order->remaining_note }}</p>@endif
            </a>
        @empty<p class="rounded border bg-white p-3">Nothing currently needs attention.</p>@endforelse</div>
    </section>
    <section><h2 class="text-lg font-semibold">Active staff sessions</h2>
        <div class="mt-3 space-y-2">@forelse ($activeSessions as $session)
            <a href="{{ route('work-orders.show', $session->workOrder) }}" class="block rounded border bg-white p-3">
                <strong>{{ $session->personnel->user->name }}</strong> · {{ $session->workOrder->work_order_number }}
                <p class="text-sm">{{ $session->workOrder->building->name }} · Active {{ number_format($session->started_at->diffInHours(now()), 0) }} hours
                    @if ($session->started_at->diffInHours(now()) >= config('reporting.stale_session_hours'))<span class="text-amber-800">· Review recommended</span>@endif</p>
            </a>
        @empty<p class="rounded border bg-white p-3">No active sessions.</p>@endforelse</div>
    </section>
</div>
<section class="mt-8"><h2 class="text-lg font-semibold">Personnel open workload</h2><p class="text-sm text-slate-600">Factual active-assignment counts, not performance ratings.</p>
    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">@forelse ($personnelWorkload as $person)
        <div class="rounded border bg-white p-3">{{ $person->user->name }} · {{ $person->open_workload }} open Work Orders</div>
    @empty<p>No active personnel profiles.</p>@endforelse</div>
</section>
<section class="mt-8"><h2 class="text-lg font-semibold">Recently completed</h2>
    <div class="mt-3 space-y-2">@forelse ($recentCompleted as $order)
        <a href="{{ route('work-orders.show', $order) }}" class="block rounded border bg-white p-3">{{ $order->work_order_number }} · {{ $order->subject }} · {{ $order->building->name }} · {{ $order->verified_at?->timezone('Asia/Manila')->format('M j, Y') }}</a>
    @empty<p class="rounded border bg-white p-3">No completed Work Orders yet.</p>@endforelse</div>
</section>
@endif
@endsection
