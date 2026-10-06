@extends('layouts.app')
@section('title', 'My Tasks')
@section('content')
    <x-page-header title="My Tasks" description="View and manage Work Orders currently assigned to you as FMO personnel." />

    <div class="table-wrap mt-6">
        <table>
            <thead>
                <tr>
                    <th>Work Order</th>
                    <th>Category</th>
                    <th>Building</th>
                    <th>Status</th>
                    <th>Assigned team</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td>
                            <a class="font-semibold text-blue-800 hover:underline" href="{{ route('work-orders.show', $order) }}">{{ $order->work_order_number }}</a>
                            <p class="mt-1 text-sm text-slate-600">{{ $order->subject }}</p>
                        </td>
                        <td>{{ $order->category->name }}</td>
                        <td>{{ $order->building->name }}</td>
                        <td><x-status-badge :status="$order->status" /></td>
                        <td>{{ $order->activeAssignments->map(fn ($assignment) => $assignment->personnel->user->name)->join(', ') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <x-empty-state title="No assigned Work Orders" description="Work Orders assigned to you will appear here." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-5">{{ $orders->links() }}</div>
@endsection
