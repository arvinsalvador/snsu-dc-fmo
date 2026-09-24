@extends('layouts.app')
@section('title', 'For Assignment')
@section('content')
<h1 class="text-2xl font-semibold">For Assignment</h1>
<form class="mt-4" method="GET"><input class="rounded border p-2" name="search" value="{{ request('search') }}" placeholder="Work Order number"><button class="rounded bg-slate-700 px-3 py-2 text-white">Filter</button></form>
<div class="mt-5 overflow-x-auto rounded border bg-white"><table class="w-full text-left text-sm"><thead class="bg-slate-100"><tr><th class="p-3">Number</th><th class="p-3">Requester</th><th class="p-3">Category</th><th class="p-3">Building</th><th class="p-3">Subject</th><th class="p-3">Preferred</th></tr></thead><tbody>@forelse($orders as $order)<tr class="border-t"><td class="p-3"><a class="text-blue-700 underline" href="{{ route('work-orders.show', $order) }}">{{ $order->work_order_number }}</a></td><td class="p-3">{{ $order->requester->name }}</td><td class="p-3">{{ $order->category->name }}</td><td class="p-3">{{ $order->building->name }}</td><td class="p-3">{{ $order->subject }}</td><td class="p-3">{{ $order->preferredPersonnel?->user?->name ?? 'None' }}</td></tr>@empty<tr><td class="p-4" colspan="6">No approved Work Orders awaiting assignment.</td></tr>@endforelse</tbody></table></div><div class="mt-4">{{ $orders->links() }}</div>
@endsection
