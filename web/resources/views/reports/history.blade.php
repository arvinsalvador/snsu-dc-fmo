@extends('layouts.app')
@section('title', 'Work Order History')
@section('content')
    <x-page-header
        title="Work Order History"
        :description="$management ? 'Review authorized operational history and export the current result set.' : 'Review your own requests and assigned Work Orders.'"
    >
        <x-slot:action>
            <a class="btn btn-secondary" href="{{ route('reports.export', request()->query()) }}">
                <x-icon name="download" />Export CSV
            </a>
        </x-slot:action>
    </x-page-header>

    <form method="GET" action="{{ route('reports.history') }}" class="section-card mt-6">
        <div class="flex items-center gap-2">
            <x-icon name="filter" class="text-blue-700" />
            <div>
                <h2 class="text-lg">Report Filters</h2>
                <p class="mt-0.5 text-sm text-slate-500">Narrow the history by Work Order, location, people, status, or date.</p>
            </div>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
            <label>Work Order number<input name="number" value="{{ $filters['number'] ?? '' }}" placeholder="WO-2026-..."></label>
            <label>
                Status
                <select name="status">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ str($status->value)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                Category
                <select name="category_id">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(($filters['category_id'] ?? '') == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                Campus
                <select name="campus_id">
                    <option value="">All campuses</option>
                    @foreach ($campuses as $campus)
                        <option value="{{ $campus->id }}" @selected(($filters['campus_id'] ?? '') == $campus->id)>{{ $campus->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                Building
                <select name="building_id">
                    <option value="">All buildings</option>
                    @foreach ($buildings as $building)
                        <option value="{{ $building->id }}" @selected(($filters['building_id'] ?? '') == $building->id)>{{ $building->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                Office / Room / Area
                <select name="location_id">
                    <option value="">All locations</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected(($filters['location_id'] ?? '') == $location->id)>{{ $location->name }}</option>
                    @endforeach
                </select>
            </label>
            @if ($management)
                <label>Requester name<input name="requester" value="{{ $filters['requester'] ?? '' }}" placeholder="Search requester"></label>
                <label>
                    FMO personnel
                    <select name="personnel_id">
                        <option value="">All personnel</option>
                        @foreach ($personnel as $person)
                            <option value="{{ $person->id }}" @selected(($filters['personnel_id'] ?? '') === $person->id)>{{ $person->user->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label>Submitted from<input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
            <label>Submitted through<input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
            <label>Completed from<input type="date" name="completed_from" value="{{ $filters['completed_from'] ?? '' }}"></label>
            <label>Completed through<input type="date" name="completed_to" value="{{ $filters['completed_to'] ?? '' }}"></label>
        </div>

        <div class="mt-5 flex flex-wrap gap-3">
            <button class="btn btn-primary" type="submit"><x-icon name="filter" />Apply filters</button>
            <a class="btn btn-ghost" href="{{ route('reports.history') }}">Reset filters</a>
        </div>
    </form>

    <div class="mt-6 grid items-stretch gap-4 sm:grid-cols-3">
        <x-stat-card label="Matching Work Orders" :value="$total" icon="clipboard" />
        <x-stat-card label="Completed" :value="$completed" icon="check-circle" />
        <x-stat-card label="Not Completed" :value="$total - $completed" icon="clock" />
    </div>

    @if ($orders->isEmpty())
        <div class="mt-6">
            <x-empty-state title="No Work Orders found" description="No Work Orders match the current report filters." icon="chart">
                <a class="btn btn-secondary mt-4" href="{{ route('reports.history') }}">Clear filters</a>
            </x-empty-state>
        </div>
    @else
        <div class="table-wrap mt-6 hidden md:block">
            <table class="min-w-[1180px] table-fixed">
                <colgroup>
                    <col class="w-[15%]">
                    <col class="w-[12%]">
                    <col class="w-[17%]">
                    <col class="w-[10%]">
                    <col class="w-[13%]">
                    <col class="w-[9%]">
                    <col class="w-[9%]">
                    <col class="w-[15%]">
                </colgroup>
                <thead>
                    <tr>
                        <th>Work Order</th>
                        <th>Requester</th>
                        <th>Category / Location</th>
                        <th>Status</th>
                        <th>Assigned To</th>
                        <th>Submitted</th>
                        <th>Completed</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        @php
                            $fullDetail = $management
                                || $order->requester_id === auth()->id()
                                || $order->activeAssignments->contains('fmo_personnel_id', auth()->user()->personnelProfile?->id);
                            $detailUrl = $fullDetail ? route('work-orders.show', $order) : route('reports.print', $order);
                            $assignedNames = ($management ? $order->assignments : $order->activeAssignments)
                                ->pluck('personnel.user.name')->filter()->unique()->values();
                        @endphp
                        <tr>
                            <td class="align-middle">
                                <a class="whitespace-nowrap font-semibold text-blue-800 hover:underline" href="{{ $detailUrl }}">{{ $order->work_order_number }}</a>
                                <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500" title="{{ $order->subject }}">{{ $order->subject }}</p>
                            </td>
                            <td class="align-middle">
                                <p class="truncate whitespace-nowrap font-medium text-slate-800" title="{{ $order->requester->name }}">{{ $order->requester->name }}</p>
                                @if ($order->requester->user_type)<p class="mt-1 text-xs text-slate-500">{{ str($order->requester->user_type)->headline() }}</p>@endif
                            </td>
                            <td class="align-middle">
                                <p class="truncate font-medium text-slate-800" title="{{ $order->category->name }}">{{ $order->category->name }}</p>
                                <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">
                                    {{ $order->building->name }}@if ($order->location) <span aria-hidden="true">•</span> {{ $order->location->name }}@endif
                                </p>
                            </td>
                            <td class="align-middle"><x-status-badge :status="$order->status" /></td>
                            <td class="align-middle">
                                @if ($assignedNames->isEmpty())
                                    <span class="text-slate-400">—</span>
                                @else
                                    <p class="truncate whitespace-nowrap font-medium text-slate-800" title="{{ $assignedNames->join(', ') }}">{{ $assignedNames->first() }}</p>
                                    @if ($assignedNames->count() > 1)<p class="mt-1 text-xs font-medium text-blue-700">+{{ $assignedNames->count() - 1 }} {{ str('other')->plural($assignedNames->count() - 1) }}</p>@endif
                                @endif
                            </td>
                            <td class="whitespace-nowrap align-middle">{{ $order->submitted_at?->timezone('Asia/Manila')->format('M j, Y') }}</td>
                            <td class="whitespace-nowrap align-middle">{{ $order->verified_at?->timezone('Asia/Manila')->format('M j, Y') ?? '—' }}</td>
                            <td class="whitespace-nowrap text-right align-middle">
                                <span class="inline-flex items-center gap-1.5">
                                    <a class="btn btn-secondary !min-h-8 !px-2.5 !py-1.5" href="{{ $detailUrl }}"><x-icon name="eye" size="size-4" />View</a>
                                    <a class="btn btn-ghost !min-h-8 !px-2.5 !py-1.5" href="{{ route('reports.print', $order) }}" target="_blank" rel="noopener"><x-icon name="printer" size="size-4" />Print</a>
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-6 space-y-3 md:hidden">
            @foreach ($orders as $order)
                @php
                    $fullDetail = $management
                        || $order->requester_id === auth()->id()
                        || $order->activeAssignments->contains('fmo_personnel_id', auth()->user()->personnelProfile?->id);
                    $detailUrl = $fullDetail ? route('work-orders.show', $order) : route('reports.print', $order);
                    $assignedNames = ($management ? $order->assignments : $order->activeAssignments)
                        ->pluck('personnel.user.name')->filter()->unique()->values();
                @endphp
                <article class="app-card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a class="whitespace-nowrap font-semibold text-blue-800 hover:underline" href="{{ $detailUrl }}">{{ $order->work_order_number }}</a>
                            <h2 class="mt-1 text-sm leading-5 text-slate-800">{{ $order->subject }}</h2>
                        </div>
                        <x-status-badge :status="$order->status" class="shrink-0" />
                    </div>

                    <dl class="mt-4 grid gap-x-4 gap-y-3 sm:grid-cols-2">
                        <div>
                            <dt class="detail-label">Requester</dt>
                            <dd class="mt-1 text-sm font-medium text-slate-800">{{ $order->requester->name }}</dd>
                            @if ($order->requester->user_type)<dd class="text-xs text-slate-500">{{ str($order->requester->user_type)->headline() }}</dd>@endif
                        </div>
                        <div>
                            <dt class="detail-label">Category</dt>
                            <dd class="mt-1 text-sm font-medium text-slate-800">{{ $order->category->name }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="detail-label">Location</dt>
                            <dd class="mt-1 flex items-start gap-1.5 text-sm text-slate-700"><x-icon name="map-pin" size="size-4" class="mt-0.5" />{{ $order->building->name }}@if ($order->location) • {{ $order->location->name }}@endif</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="detail-label">Assigned</dt>
                            <dd class="mt-1 text-sm text-slate-700">{{ $assignedNames->join(', ') ?: 'Unassigned' }}</dd>
                        </div>
                        <div>
                            <dt class="detail-label">Submitted</dt>
                            <dd class="mt-1 whitespace-nowrap text-sm text-slate-700">{{ $order->submitted_at?->timezone('Asia/Manila')->format('M j, Y') }}</dd>
                        </div>
                        <div>
                            <dt class="detail-label">Completed</dt>
                            <dd class="mt-1 whitespace-nowrap text-sm text-slate-700">{{ $order->verified_at?->timezone('Asia/Manila')->format('M j, Y') ?? '—' }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                        <a class="btn btn-secondary !min-h-9 !px-3" href="{{ $detailUrl }}"><x-icon name="eye" size="size-4" />View</a>
                        <a class="btn btn-ghost !min-h-9 !px-3" href="{{ route('reports.print', $order) }}" target="_blank" rel="noopener"><x-icon name="printer" size="size-4" />Print</a>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-slate-500">Showing {{ $orders->firstItem() }}–{{ $orders->lastItem() }} of {{ $orders->total() }} Work Orders</p>
            {{ $orders->links() }}
        </div>
    @endif
@endsection
