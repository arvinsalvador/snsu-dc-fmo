@extends('layouts.app')
@section('title', $queue === 'active' ? 'Active Work' : 'For Verification')
@section('content')
<h1 class="text-2xl font-semibold">{{ $queue === 'active' ? 'Active Work' : 'For Verification' }}</h1>
<div class="mt-5 space-y-3">
@forelse ($orders as $order)
    <a class="block rounded border bg-white p-4" href="{{ route('work-orders.show', $order) }}"><b class="text-blue-700">{{ $order->work_order_number }}</b> — {{ $order->subject }} <span class="text-sm">{{ str_replace('_', ' ', $order->status->value) }} · {{ $order->building->name }} · {{ $order->activeAssignments->pluck('personnel.user.name')->join(', ') }} · {{ $order->activeSessions->count() }} active session(s)</span></a>
@empty<p>No Work Orders in this queue.</p>@endforelse
</div>
{{ $orders->links() }}
@endsection
