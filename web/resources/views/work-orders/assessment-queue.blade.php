@extends('layouts.app')
@section('title', 'Assessment Review')
@section('content')
<h1 class="text-2xl font-semibold">Assessment Review</h1>
<div class="mt-5 space-y-3">@forelse ($orders as $order)<a class="block rounded border bg-white p-4 text-blue-700" href="{{ route('work-orders.show', $order) }}">{{ $order->work_order_number }} — {{ $order->subject }} · {{ $order->requester->name }}</a>@empty<p>No assessments awaiting review.</p>@endforelse</div>
{{ $orders->links() }}
@endsection
