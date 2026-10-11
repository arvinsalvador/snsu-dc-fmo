@extends('layouts.app')
@section('title', $queue === 'active' ? 'Active Work' : 'For Verification')
@section('content')
    @php($activeQueue = $queue === 'active')
    <x-page-header
        :title="$activeQueue ? 'Active Work' : 'For Verification'"
        :description="$activeQueue ? 'Monitor Work Orders with active or continuing field activity.' : 'Review completed work and evidence before closing the Work Order.'"
    />
    <div class="mt-6 grid gap-3">
        @forelse ($orders as $order)
            <a class="queue-card" href="{{ route('work-orders.show', $order) }}">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0"><p class="font-semibold text-blue-800">{{ $order->work_order_number }}</p><h2 class="mt-1 truncate">{{ $order->subject }}</h2><p class="mt-2 text-sm text-slate-600">{{ $order->building->name }} · {{ $order->activeAssignments->pluck('personnel.user.name')->join(', ') ?: 'No active assignee' }}</p></div>
                    <div class="flex items-center gap-3"><x-status-badge :status="$order->status" />@if ($activeQueue)<span class="text-sm font-medium text-indigo-700">{{ $order->activeSessions->count() }} active {{ \Illuminate\Support\Str::plural('session', $order->activeSessions->count()) }}</span>@endif<x-icon name="chevron-right" class="text-slate-400" /></div>
                </div>
            </a>
        @empty
            <x-empty-state :title="$activeQueue ? 'No active Work Orders' : 'No Work Orders await verification'" :description="$activeQueue ? 'Work in progress and continuation items will appear here.' : 'Completed field work will appear here for final review.'" :icon="$activeQueue ? 'play' : 'shield-check'" />
        @endforelse
    </div>
    <div class="mt-5">{{ $orders->links() }}</div>
@endsection
