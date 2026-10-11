@extends('layouts.app')
@section('title', $queue === 'screening' ? 'For Screening' : 'For Approval')
@section('content')
    @php($isScreening = $queue === 'screening')
    <x-page-header
        :title="$isScreening ? 'For Screening' : 'For Approval'"
        :description="$isScreening ? 'Review newly submitted requests and identify the correct next step.' : 'Review screening recommendations and make an authorized decision.'"
    />

    <form class="filter-bar mt-6" method="GET">
        <label class="min-w-64 flex-1">Search Work Orders<input name="search" value="{{ request('search') }}" placeholder="Work Order number or subject"></label>
        <button class="btn btn-primary" type="submit"><x-icon name="filter" />Filter</button>
        <a class="btn btn-ghost" href="{{ route('work-orders.queue', $queue) }}">Reset</a>
    </form>

    <div class="table-wrap mt-6">
        <table>
            <thead><tr><th>Work Order</th><th>Age / Submitted</th><th>Requester</th><th>Category &amp; Location</th><th>Status</th><th>{{ $isScreening ? 'Subject' : 'Recommendation' }}</th><th class="text-right">Action</th></tr></thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td><a class="font-semibold text-blue-800 hover:underline" href="{{ route('work-orders.show', $order) }}">{{ $order->work_order_number }}</a></td>
                        <td><span class="font-medium text-slate-900">{{ $order->submitted_at?->diffForHumans() }}</span><p class="mt-1 text-xs text-slate-500">{{ $order->submitted_at?->timezone('Asia/Manila')->format('F j, Y') }}</p></td>
                        <td>{{ $order->requester->name }}</td>
                        <td>{{ $order->category->name }}<p class="mt-1 text-xs text-slate-500">{{ $order->building->name }}</p></td>
                        <td><x-status-badge :status="$order->status" /></td>
                        <td>{{ $isScreening ? $order->subject : ($order->recommendation ?? 'Not recorded') }}</td>
                        <td class="text-right"><a class="btn btn-primary !min-h-9 !px-3" href="{{ route('work-orders.show', $order) }}"><x-icon name="eye" size="size-4" />Review</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-empty-state :title="$isScreening ? 'No requests need screening' : 'No requests need approval'" description="This queue is currently clear." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-5">{{ $orders->links() }}</div>
@endsection
