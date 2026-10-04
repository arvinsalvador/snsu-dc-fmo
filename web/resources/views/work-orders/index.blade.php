@extends('layouts.app')
@section('title', $all ? 'All Work Orders' : 'My Work Orders')
@section('content')
    <x-page-header
        :title="$all ? 'All Work Orders' : 'My Work Orders'"
        :description="$all ? 'Review authorized facilities requests across the campus.' : 'Track the current status of your submitted facilities requests.'"
    >
        @can('work_orders.create')
            <x-slot:action>
                <a class="btn btn-primary" href="{{ route('work-orders.create') }}">+ New Work Order</a>
            </x-slot:action>
        @endcan
    </x-page-header>

    <form class="filter-bar mt-6" method="GET">
        <label class="min-w-56 flex-1">
            Search
            <input name="search" value="{{ request('search') }}" placeholder="Work Order number or subject">
        </label>
        <label>
            Status
            <select name="status">
                <option value="">All statuses</option>
                @foreach (\App\Enums\WorkOrderStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>
                        {{ \Illuminate\Support\Str::of($status->value)->replace('_', ' ')->title() }}
                    </option>
                @endforeach
            </select>
        </label>
        <button class="btn btn-primary" type="submit">Apply filters</button>
        <a class="btn btn-ghost" href="{{ route('work-orders.index') }}">Reset</a>
    </form>

    <div class="table-wrap mt-6">
        <table>
            <thead>
                <tr>
                    <th>Work Order</th>
                    <th>Submitted</th>
                    @if ($all)
                        <th>Requester</th>
                    @endif
                    <th>Category &amp; Location</th>
                    <th>Status</th>
                    <th class="text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td>
                            <a class="font-semibold text-blue-800 hover:underline" href="{{ route('work-orders.show', $order) }}">{{ $order->work_order_number }}</a>
                            <p class="mt-0.5 text-sm text-slate-600">{{ $order->subject }}</p>
                        </td>
                        <td>{{ $order->submitted_at?->timezone('Asia/Manila')->format('M j, Y') }}</td>
                        @if ($all)
                            <td>{{ $order->requester?->name }}</td>
                        @endif
                        <td>
                            <p>{{ $order->category->name }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $order->building->name }}</p>
                        </td>
                        <td><x-status-badge :status="$order->status" /></td>
                        <td class="text-right">
                            <a class="btn btn-secondary !min-h-9 !px-3" href="{{ route('work-orders.show', $order) }}">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $all ? 6 : 5 }}">
                            <x-empty-state title="No Work Orders found" description="There are no Work Orders matching your current filters." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-5">{{ $orders->links() }}</div>
@endsection
