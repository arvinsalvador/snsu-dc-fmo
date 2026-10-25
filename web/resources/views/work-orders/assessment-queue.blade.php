@extends('layouts.app')
@section('title', 'Assessment Review')
@section('content')
    <x-page-header title="Assessment Review" description="Review assigned Work Orders that require inspection or technical assessment before work begins." />

    <section class="mt-6 grid gap-4 sm:grid-cols-2" aria-label="Assessment summary">
        <x-stat-card label="Awaiting Assessment Review" :value="$summary['awaiting']" icon="assessment" />
        <x-stat-card label="Assessed Today" :value="$summary['assessed_today']" icon="check-circle" />
    </section>

    <form class="section-card mt-6" method="GET">
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-12">
            <label class="md:col-span-2 xl:col-span-4">Search<input name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="Work Order, requester, subject or location..."></label>
            <label class="xl:col-span-2">Work Type<select name="category"><option value="">All work types</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($filters['category'] === $category->id)>{{ $category->name }}</option>@endforeach</select></label>
            <label class="xl:col-span-2">Priority<select name="priority"><option value="">All priorities</option><option value="URGENT" @selected($filters['priority'] === 'URGENT')>Urgent</option><option value="NORMAL" @selected($filters['priority'] === 'NORMAL')>Normal</option></select></label>
            <label class="xl:col-span-2">Assigned<select name="assigned"><option value="">Any date</option><option value="7" @selected($filters['assigned'] === '7')>Last 7 days</option><option value="30" @selected($filters['assigned'] === '30')>Last 30 days</option><option value="90" @selected($filters['assigned'] === '90')>Last 90 days</option></select></label>
            <label class="xl:col-span-2">Per Page<select name="per_page">@foreach([20, 50, 100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>@endforeach</select></label>
            <label class="md:col-span-2 xl:col-span-4">Sort<select name="sort"><option value="oldest_waiting" @selected($filters['sort'] === 'oldest_waiting')>Oldest Waiting</option><option value="newest_waiting" @selected($filters['sort'] === 'newest_waiting')>Newest Assessment</option><option value="highest_priority" @selected($filters['sort'] === 'highest_priority')>Highest Priority</option></select></label>
        </div>
        <div class="mt-5 flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end"><a class="btn btn-secondary" href="{{ route('work-orders.assessment-queue') }}">Clear Filters</a><button class="btn btn-primary" type="submit"><x-icon name="filter" />Apply Filters</button></div>
    </form>

    @if($orders->isEmpty())
        <div class="mt-6"><x-empty-state :title="$hasFilters ? 'No matching records' : 'No assessments waiting'" :description="$hasFilters ? 'Try changing your search or filters.' : 'There are no assigned Work Orders requiring assessment review.'" icon="assessment">@if($hasFilters)<a class="btn btn-secondary mt-4" href="{{ route('work-orders.assessment-queue') }}">Clear Filters</a>@endif</x-empty-state></div>
    @else
        <div class="table-wrap mt-6 hidden lg:block"><table class="min-w-[68rem]"><thead><tr><th>Work Order</th><th>Requester</th><th>Work Type</th><th>Location</th><th>Priority</th><th>Assessment Status</th><th>Assigned Personnel</th><th class="text-right">Action</th></tr></thead><tbody>
            @foreach($orders as $order)
                <tr class="{{ $order->urgency === 'URGENT' ? 'bg-amber-50/40' : '' }}">
                    <td><a class="font-semibold text-blue-800 hover:underline" href="{{ route('work-orders.show', $order).'#assessment-review' }}">{{ $order->work_order_number }}</a><p class="mt-1 max-w-xs text-sm text-slate-600">{{ $order->subject }}</p></td>
                    <td>{{ $order->requester->name }}</td><td>{{ $order->category->name }}</td>
                    <td><span class="font-medium text-slate-800">{{ $order->building->name }}</span><span class="mt-1 block text-xs text-slate-500">{{ collect([$order->floor?->name, $order->location?->name])->filter()->join(' • ') ?: 'Building-wide' }}</span></td>
                    <td><span class="status-badge {{ $order->urgency === 'URGENT' ? 'status-danger' : 'status-neutral' }}">{{ str($order->urgency)->title() }}</span></td>
                    <td><span class="status-badge status-pending">● Awaiting Review</span>@if($order->assessments_max_assessed_at)<span class="mt-1 block whitespace-nowrap text-xs text-slate-500">Assessed {{ $order->assessments_max_assessed_at->timezone('Asia/Manila')->format('M j, Y') }}</span>@endif</td>
                    <td><span class="block max-w-52 text-sm">{{ $order->activeAssignments->pluck('personnel.user.name')->filter()->join(', ') ?: 'Unassigned' }}</span></td>
                    <td class="text-right"><a class="btn btn-primary !min-h-9 !px-3" href="{{ route('work-orders.show', $order).'#assessment-review' }}">Review Assessment</a></td>
                </tr>
            @endforeach
        </tbody></table></div>

        <div class="mt-6 grid gap-4 lg:hidden">@foreach($orders as $order)<article class="app-card {{ $order->urgency === 'URGENT' ? 'border-amber-300 bg-amber-50/40' : '' }} p-4"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><a class="font-semibold text-blue-800" href="{{ route('work-orders.show', $order).'#assessment-review' }}">{{ $order->work_order_number }}</a><h2 class="mt-1 text-sm leading-5">{{ $order->subject }}</h2></div><span class="status-badge {{ $order->urgency === 'URGENT' ? 'status-danger' : 'status-neutral' }}">{{ str($order->urgency)->title() }}</span></div><dl class="mt-4 grid gap-3 sm:grid-cols-2"><div><dt class="detail-label">Requester</dt><dd class="mt-1 text-sm">{{ $order->requester->name }}</dd></div><div><dt class="detail-label">Work Type</dt><dd class="mt-1 text-sm">{{ $order->category->name }}</dd></div><div><dt class="detail-label">Location</dt><dd class="mt-1 text-sm">{{ $order->building->name }}{{ $order->location ? ' • '.$order->location->name : '' }}</dd></div><div><dt class="detail-label">Assessment Status</dt><dd class="mt-1"><span class="status-badge status-pending">● Awaiting Review</span></dd></div></dl><a class="btn btn-primary mt-4 w-full" href="{{ route('work-orders.show', $order).'#assessment-review' }}">Review Assessment</a></article>@endforeach</div>
        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-slate-500">Showing {{ $orders->firstItem() }}–{{ $orders->lastItem() }} of {{ $orders->total() }} assessments</p>{{ $orders->onEachSide(1)->links() }}</div>
    @endif
@endsection
