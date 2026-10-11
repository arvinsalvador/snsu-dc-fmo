@extends('layouts.app')
@section('title', 'For Assignment')
@section('content')
    <x-page-header title="For Assignment" description="Assign approved Work Orders to available FMO personnel." />
    <form class="filter-bar mt-6" method="GET">
        <label class="min-w-64 flex-1">Search Work Orders<input name="search" value="{{ request('search') }}" placeholder="Work Order number or subject"></label>
        <button class="btn btn-primary" type="submit"><x-icon name="filter" />Filter</button>
        <a class="btn btn-ghost" href="{{ route('work-orders.assignment-queue') }}">Reset</a>
    </form>
    <div class="table-wrap mt-6"><table><thead><tr><th>Work Order</th><th>Requester</th><th>Category &amp; Location</th><th>Subject</th><th>Requester preference</th><th class="text-right">Action</th></tr></thead><tbody>
        @forelse ($orders as $order)
            <tr><td class="font-semibold text-blue-800">{{ $order->work_order_number }}</td><td>{{ $order->requester->name }}</td><td>{{ $order->category->name }}<p class="mt-1 text-xs text-slate-500">{{ $order->building->name }}</p></td><td>{{ $order->subject }}</td><td>{{ $order->preferredPersonnel?->user?->name ?? 'No preference' }}<p class="mt-1 text-xs text-slate-500">Preference only; final assignment is determined by FMO.</p></td><td class="text-right"><a class="btn btn-primary !min-h-9 !px-3" href="{{ route('work-orders.show', $order) }}"><x-icon name="assignment" size="size-4" />Assign</a></td></tr>
        @empty
            <tr><td colspan="6"><x-empty-state title="No Work Orders await assignment" description="Approved requests will appear here when personnel assignment is needed." icon="assignment" /></td></tr>
        @endforelse
    </tbody></table></div>
    <div class="mt-5">{{ $orders->links() }}</div>
@endsection
