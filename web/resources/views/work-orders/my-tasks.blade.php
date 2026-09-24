@extends('layouts.app')
@section('title', 'My Assigned Work Orders')
@section('content')
<h1 class="text-2xl font-semibold">My Assigned Work Orders</h1>
<div class="mt-5 overflow-x-auto rounded border bg-white"><table class="w-full text-left text-sm"><thead class="bg-slate-100"><tr><th class="p-3">Number</th><th class="p-3">Category</th><th class="p-3">Building</th><th class="p-3">Subject</th><th class="p-3">Status</th><th class="p-3">Team</th></tr></thead><tbody>@forelse($orders as $order)<tr class="border-t"><td class="p-3"><a class="text-blue-700 underline" href="{{ route('work-orders.show', $order) }}">{{ $order->work_order_number }}</a></td><td class="p-3">{{ $order->category->name }}</td><td class="p-3">{{ $order->building->name }}</td><td class="p-3">{{ $order->subject }}</td><td class="p-3">{{ str_replace('_', ' ', $order->status->value) }}</td><td class="p-3">{{ $order->activeAssignments->map(fn($assignment) => $assignment->personnel->user->name)->join(', ') }}</td></tr>@empty<tr><td class="p-4" colspan="6">No assigned Work Orders.</td></tr>@endforelse</tbody></table></div><div class="mt-4">{{ $orders->links() }}</div>
@endsection
