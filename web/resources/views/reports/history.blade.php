@extends('layouts.app')
@section('title', 'Work Order History')
@section('content')
<div class="flex flex-wrap items-center justify-between gap-3">
    <div><h1 class="text-2xl font-semibold">Work Order History</h1>
        <p class="mt-1 text-sm text-slate-600">{{ $management ? 'Authorized management history' : 'Only your own requests and assigned work' }}</p></div>
    <a class="rounded bg-slate-700 px-4 py-2 text-white" href="{{ route('reports.export', request()->query()) }}">Export filtered CSV</a>
</div>
<form method="GET" action="{{ route('reports.history') }}" class="mt-5 grid gap-3 rounded border bg-white p-4 md:grid-cols-3">
    <label>Work Order number<input class="mt-1 block w-full rounded border p-2" name="number" value="{{ $filters['number'] ?? '' }}"></label>
    <label>Status<select class="mt-1 block w-full rounded border p-2" name="status"><option value="">All statuses</option>@foreach ($statuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ str_replace('_', ' ', $status->value) }}</option>@endforeach</select></label>
    <label>Category<select class="mt-1 block w-full rounded border p-2" name="category_id"><option value="">All categories</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected(($filters['category_id'] ?? '') == $category->id)>{{ $category->name }}</option>@endforeach</select></label>
    <label>Campus<select class="mt-1 block w-full rounded border p-2" name="campus_id"><option value="">All campuses</option>@foreach ($campuses as $campus)<option value="{{ $campus->id }}" @selected(($filters['campus_id'] ?? '') == $campus->id)>{{ $campus->name }}</option>@endforeach</select></label>
    <label>Building<select class="mt-1 block w-full rounded border p-2" name="building_id"><option value="">All buildings</option>@foreach ($buildings as $building)<option value="{{ $building->id }}" @selected(($filters['building_id'] ?? '') == $building->id)>{{ $building->name }}</option>@endforeach</select></label>
    <label>Office / room / area<select class="mt-1 block w-full rounded border p-2" name="location_id"><option value="">All locations</option>@foreach ($locations as $location)<option value="{{ $location->id }}" @selected(($filters['location_id'] ?? '') == $location->id)>{{ $location->name }}</option>@endforeach</select></label>
    @if ($management)
        <label>Requester name<input class="mt-1 block w-full rounded border p-2" name="requester" value="{{ $filters['requester'] ?? '' }}"></label>
        <label>FMO personnel<select class="mt-1 block w-full rounded border p-2" name="personnel_id"><option value="">All personnel</option>@foreach ($personnel as $person)<option value="{{ $person->id }}" @selected(($filters['personnel_id'] ?? '') === $person->id)>{{ $person->user->name }}</option>@endforeach</select></label>
    @endif
    <label>Submitted from<input class="mt-1 block w-full rounded border p-2" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
    <label>Submitted through<input class="mt-1 block w-full rounded border p-2" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
    <label>Completed from<input class="mt-1 block w-full rounded border p-2" type="date" name="completed_from" value="{{ $filters['completed_from'] ?? '' }}"></label>
    <label>Completed through<input class="mt-1 block w-full rounded border p-2" type="date" name="completed_to" value="{{ $filters['completed_to'] ?? '' }}"></label>
    <div class="flex items-end gap-3"><button class="rounded bg-blue-700 px-4 py-2 text-white">Apply filters</button><a class="text-blue-700 underline" href="{{ route('reports.history') }}">Reset filters</a></div>
</form>
<p class="mt-5 rounded border bg-white p-3"><b>{{ $total }}</b> Work Orders · <b>{{ $completed }}</b> completed · <b>{{ $total - $completed }}</b> other states</p>
<div class="mt-4 overflow-x-auto rounded border bg-white">
    <table class="min-w-full text-left text-sm"><thead class="border-b bg-slate-100"><tr>
        <th class="p-3">Work Order</th><th class="p-3">Requester</th><th class="p-3">Category / Location</th>
        <th class="p-3">Status</th><th class="p-3">Assigned personnel</th><th class="p-3">Submitted</th><th class="p-3">Completed</th><th class="p-3">Actions</th>
    </tr></thead><tbody>
    @forelse ($orders as $order)<tr class="border-b align-top">
        @php($fullDetail = $management || $order->requester_id === auth()->id() || $order->activeAssignments->contains('fmo_personnel_id', auth()->user()->personnelProfile?->id))
        <td class="p-3"><a class="text-blue-700 underline" href="{{ $fullDetail ? route('work-orders.show', $order) : route('reports.print', $order) }}">{{ $order->work_order_number }}</a><br>{{ $order->subject }}</td>
        <td class="p-3">{{ $order->requester->name }}</td>
        <td class="p-3">{{ $order->category->name }}<br>{{ $order->building->name }} @if ($order->location) / {{ $order->location->name }} @endif</td>
        <td class="p-3">{{ str_replace('_', ' ', $order->status->value) }}</td>
        <td class="p-3">{{ ($management ? $order->assignments : $order->activeAssignments)->pluck('personnel.user.name')->filter()->unique()->join(', ') ?: '—' }}</td>
        <td class="p-3">{{ $order->submitted_at?->timezone('Asia/Manila')->format('Y-m-d') }}</td>
        <td class="p-3">{{ $order->verified_at?->timezone('Asia/Manila')->format('Y-m-d') ?? '—' }}</td>
        <td class="p-3"><a class="text-blue-700 underline" href="{{ route('reports.print', $order) }}">Print</a></td>
    </tr>@empty<tr><td colspan="8" class="p-5">No Work Orders match these filters.</td></tr>@endforelse
    </tbody></table>
</div>
<div class="mt-5">{{ $orders->links() }}</div>
@endsection
