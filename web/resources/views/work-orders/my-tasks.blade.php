@extends('layouts.app')
@section('title', 'My Tasks')
@section('content')
    <x-page-header title="My Tasks" description="Work Orders currently assigned to you." />

    <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Task summary">
        <x-stat-card label="Assigned" :value="$summary['assigned']" icon="tasks" />
        <x-stat-card label="In Progress" :value="$summary['in_progress']" icon="play" />
        <x-stat-card label="Needs Action" :value="$summary['needs_action']" icon="warning" />
        <x-stat-card label="Awaiting Verification" :value="$summary['awaiting_verification']" icon="shield-check" />
    </section>

    <form class="section-card mt-6" method="GET">
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-12">
            <label class="md:col-span-2 xl:col-span-4">Search<input name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="Work Order, subject, requester or location..."></label>
            <label class="xl:col-span-2">Current Stage<select name="status"><option value="">All stages</option>@foreach($statusOptions as $status)<option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ match($status) { \App\Enums\WorkOrderStatus::Assigned, \App\Enums\WorkOrderStatus::ForAssessment => 'Assessment Required', \App\Enums\WorkOrderStatus::AssessmentReview => 'Assessment Review', \App\Enums\WorkOrderStatus::ReadyForWork => 'Ready to Start', \App\Enums\WorkOrderStatus::InProgress => 'In Progress', \App\Enums\WorkOrderStatus::ForContinuation => 'Needs Continuation', \App\Enums\WorkOrderStatus::ForVerification => 'Awaiting Verification', default => str($status->value)->replace('_', ' ')->title() } }}</option>@endforeach</select></label>
            <label class="xl:col-span-2">Priority<select name="priority"><option value="">All priorities</option><option value="URGENT" @selected($filters['priority'] === 'URGENT')>Urgent</option><option value="NORMAL" @selected($filters['priority'] === 'NORMAL')>Normal</option></select></label>
            <label class="xl:col-span-2">Work Type<select name="category"><option value="">All work types</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($filters['category'] === $category->id)>{{ $category->name }}</option>@endforeach</select></label>
            <label class="xl:col-span-2">Per Page<select name="per_page">@foreach([20, 50, 100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>@endforeach</select></label>
            <label class="md:col-span-2 xl:col-span-4">Sort<select name="sort"><option value="newest_assigned" @selected($filters['sort'] === 'newest_assigned')>Newest Assigned</option><option value="oldest_assigned" @selected($filters['sort'] === 'oldest_assigned')>Oldest Assigned</option><option value="highest_priority" @selected($filters['sort'] === 'highest_priority')>Highest Priority</option><option value="action_needed" @selected($filters['sort'] === 'action_needed')>Longest Waiting for Action</option></select></label>
        </div>
        <div class="mt-5 flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end"><a class="btn btn-secondary" href="{{ route('work-orders.my-tasks') }}">Clear Filters</a><button class="btn btn-primary" type="submit"><x-icon name="filter" />Apply Filters</button></div>
    </form>

    @if($orders->isEmpty())
        <div class="mt-6"><x-empty-state :title="$hasFilters ? 'No matching tasks' : 'No assigned tasks'" :description="$hasFilters ? 'Try changing your search or filters.' : 'You currently have no Work Orders requiring action.'" icon="tasks">@if($hasFilters)<a class="btn btn-secondary mt-4" href="{{ route('work-orders.my-tasks') }}">Clear Filters</a>@endif</x-empty-state></div>
    @else
        <div class="table-wrap mt-6 hidden lg:block">
            <table class="min-w-[70rem]">
                <thead><tr><th>Work Order</th><th>Requester</th><th>Work Type</th><th>Location</th><th>Priority</th><th>Current Stage</th><th>Assigned</th><th class="text-right">Action</th></tr></thead>
                <tbody>
                    @foreach($orders as $order)
                        @php
                            $mySession = $order->activeSessions->first(fn ($session) => $session->personnel?->user_id === auth()->id());
                            $stage = match($order->status) {
                                \App\Enums\WorkOrderStatus::Assigned, \App\Enums\WorkOrderStatus::ForAssessment => 'Assessment Required',
                                \App\Enums\WorkOrderStatus::AssessmentReview => 'Assessment Review',
                                \App\Enums\WorkOrderStatus::ReadyForWork => 'Ready to Start',
                                \App\Enums\WorkOrderStatus::ForContinuation => 'Needs Continuation',
                                \App\Enums\WorkOrderStatus::ForVerification => 'Completion Submitted',
                                default => str($order->status->value)->replace('_', ' ')->title(),
                            };
                            [$action, $anchor] = match(true) {
                                in_array($order->status, [\App\Enums\WorkOrderStatus::Assigned, \App\Enums\WorkOrderStatus::ForAssessment], true) => ['Assess Work', '#assessment'],
                                $order->status === \App\Enums\WorkOrderStatus::ReadyForWork => ['Start Work', '#field-actions'],
                                $order->status === \App\Enums\WorkOrderStatus::InProgress && (bool) $mySession => ['Continue Work', '#field-actions'],
                                in_array($order->status, [\App\Enums\WorkOrderStatus::ForContinuation, \App\Enums\WorkOrderStatus::Paused, \App\Enums\WorkOrderStatus::WaitingForMaterials], true) => ['Resume Work', '#field-actions'],
                                default => ['View', ''],
                            };
                        @endphp
                        <tr>
                            <td><a class="font-semibold text-blue-800 hover:underline" href="{{ route('work-orders.show', $order) }}">{{ $order->work_order_number }}</a><p class="mt-1 max-w-xs text-sm text-slate-600">{{ $order->subject }}</p>@if(($order->unread_messages_count ?? 0) > 0)<span class="mt-2 inline-flex rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">{{ $order->unread_messages_count }} unread {{ str('message')->plural($order->unread_messages_count) }}</span>@endif</td>
                            <td>{{ $order->requester->name }}</td>
                            <td>{{ $order->category->name }}</td>
                            <td><span class="font-medium text-slate-800">{{ $order->building->name }}</span><span class="mt-1 block text-xs text-slate-500">{{ collect([$order->floor?->name, $order->location?->name])->filter()->join(' • ') ?: 'Building-wide' }}</span></td>
                            <td><span class="status-badge {{ $order->urgency === 'URGENT' ? 'status-danger' : 'status-neutral' }}">{{ str($order->urgency)->title() }}</span></td>
                            <td><x-status-badge :status="$order->status" /><span class="mt-1 block text-xs text-slate-500">{{ $stage }}</span></td>
                            <td class="whitespace-nowrap">{{ $order->current_user_assigned_at?->timezone('Asia/Manila')->format('M j, Y') ?? '—' }}</td>
                            <td class="text-right"><a class="btn {{ $action === 'View' ? 'btn-secondary' : 'btn-primary' }} !min-h-9 !px-3" href="{{ route('work-orders.show', $order).$anchor }}">{{ $action }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-6 grid gap-4 lg:hidden">
            @foreach($orders as $order)
                @php
                    $mySession = $order->activeSessions->first(fn ($session) => $session->personnel?->user_id === auth()->id());
                    [$action, $anchor] = match(true) {
                        in_array($order->status, [\App\Enums\WorkOrderStatus::Assigned, \App\Enums\WorkOrderStatus::ForAssessment], true) => ['Assess Work', '#assessment'],
                        $order->status === \App\Enums\WorkOrderStatus::ReadyForWork => ['Start Work', '#field-actions'],
                        $order->status === \App\Enums\WorkOrderStatus::InProgress && (bool) $mySession => ['Continue Work', '#field-actions'],
                        in_array($order->status, [\App\Enums\WorkOrderStatus::ForContinuation, \App\Enums\WorkOrderStatus::Paused, \App\Enums\WorkOrderStatus::WaitingForMaterials], true) => ['Resume Work', '#field-actions'],
                        default => ['View', ''],
                    };
                @endphp
                <article class="app-card p-4"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><a class="font-semibold text-blue-800" href="{{ route('work-orders.show', $order) }}">{{ $order->work_order_number }}</a><h2 class="mt-1 text-sm leading-5">{{ $order->subject }}</h2>@if(($order->unread_messages_count ?? 0) > 0)<span class="mt-2 inline-flex rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">{{ $order->unread_messages_count }} unread {{ str('message')->plural($order->unread_messages_count) }}</span>@endif</div><span class="status-badge {{ $order->urgency === 'URGENT' ? 'status-danger' : 'status-neutral' }}">{{ str($order->urgency)->title() }}</span></div><dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="detail-label">Location</dt><dd class="mt-1">{{ $order->building->name }}{{ $order->location ? ' • '.$order->location->name : '' }}</dd></div><div><dt class="detail-label">Work Type</dt><dd class="mt-1">{{ $order->category->name }}</dd></div><div><dt class="detail-label">Current Stage</dt><dd class="mt-1"><x-status-badge :status="$order->status" /></dd></div><div><dt class="detail-label">Assigned</dt><dd class="mt-1">{{ $order->current_user_assigned_at?->timezone('Asia/Manila')->format('M j, Y') ?? '—' }}</dd></div></dl><a class="btn {{ $action === 'View' ? 'btn-secondary' : 'btn-primary' }} mt-4 w-full" href="{{ route('work-orders.show', $order).$anchor }}">{{ $action }}</a></article>
            @endforeach
        </div>

        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-slate-500">Showing {{ $orders->firstItem() }}–{{ $orders->lastItem() }} of {{ $orders->total() }} tasks</p>{{ $orders->onEachSide(1)->links() }}</div>
    @endif
@endsection
