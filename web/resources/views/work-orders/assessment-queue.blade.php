@extends('layouts.app')
@section('title', 'Assessment Review')
@section('content')
    <x-page-header title="Assessment Review" description="Review staff findings and decide whether each Work Order is ready for its next operational step." />
    <div class="mt-6 grid gap-3">
        @forelse ($orders as $order)
            <a class="queue-card" href="{{ route('work-orders.show', $order) }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><p class="font-semibold text-blue-800">{{ $order->work_order_number }}</p><h2 class="mt-1">{{ $order->subject }}</h2><p class="mt-2 text-sm text-slate-600">{{ $order->requester->name }} · {{ $order->building->name }}</p></div>
                    <div class="flex items-center gap-2"><x-status-badge :status="$order->status" /><x-icon name="chevron-right" class="text-slate-400" /></div>
                </div>
            </a>
        @empty
            <x-empty-state title="No assessments await review" description="Completed staff assessments will appear here when management action is required." icon="assessment" />
        @endforelse
    </div>
    <div class="mt-5">{{ $orders->links() }}</div>
@endsection
