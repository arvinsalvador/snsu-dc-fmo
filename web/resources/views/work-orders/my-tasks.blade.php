@extends('layouts.app')
@section('title', 'My Tasks')
@section('content')
    <x-page-header title="My Tasks" description="View and manage Work Orders currently assigned to you as FMO personnel." />

    <div class="table-wrap mobile-card-table mt-6">
        <table>
            <thead>
                <tr>
                    <th>Work Order</th>
                    <th>Category</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Assigned team</th>
                    <th class="text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td>
                            <a class="font-semibold text-blue-800 hover:underline" href="{{ route('work-orders.show', $order) }}">{{ $order->work_order_number }}</a>
                            <p class="mt-1 text-sm text-slate-600">{{ $order->subject }}</p>
                        </td>
                        <td data-label="Work type">{{ $order->category->name }}</td>
                        <td data-label="Location">
                            {{ $order->building->name }}
                            @if ($order->location)
                                <p class="mt-1 text-sm text-slate-600">{{ $order->location->name }}</p>
                            @endif
                        </td>
                        <td data-label="Status"><x-status-badge :status="$order->status" /></td>
                        <td data-label="Assigned team">{{ $order->activeAssignments->map(fn ($assignment) => $assignment->personnel->user->name)->join(', ') }}</td>
                        <td data-label="Action" class="text-right"><a class="btn btn-primary !min-h-9 !px-3" href="{{ route('work-orders.show', $order) }}"><x-icon name="eye" size="size-4" />Open task</a></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-empty-state title="No assigned Work Orders" description="Work Orders assigned to you will appear here." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-5">{{ $orders->links() }}</div>
@endsection
