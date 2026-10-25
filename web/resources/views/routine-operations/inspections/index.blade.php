@extends('layouts.app')
@section('title', 'Inspections')
@section('content')
    @php
        $management = auth()->user()->can('inspections.view_all');
        $tabs = ['due' => 'Due', 'in_progress' => 'In Progress', 'overdue' => 'Overdue', 'upcoming' => 'Upcoming', 'completed' => 'Completed', 'all' => 'All'];
        $query = array_filter($filters, fn ($value) => $value !== '' && $value !== null);
    @endphp
    <x-page-header title="Inspections" :description="$management ? 'Monitor scheduled Asset inspections and team progress.' : 'View and complete Asset inspections assigned to you.'" />

    <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5" aria-label="Inspection summary">
        <x-stat-card label="Due" :value="$summary['due']" icon="clock" />
        <x-stat-card label="In Progress" :value="$summary['in_progress']" icon="play" />
        <x-stat-card label="Overdue" :value="$summary['overdue']" icon="warning" tone="danger" />
        <x-stat-card label="Upcoming" :value="$summary['upcoming']" icon="calendar" />
        <x-stat-card label="Completed" :value="$summary['completed']" icon="check-circle" />
    </section>

    <nav class="mt-6 overflow-x-auto border-b border-slate-200" aria-label="Inspection status"><div class="flex min-w-max gap-1">
        @foreach($tabs as $value => $label)<a class="inline-flex min-h-11 items-center gap-2 border-b-2 px-3 py-2 text-sm font-semibold {{ $filters['status'] === $value ? 'border-blue-700 text-blue-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' }}" href="{{ route('inspections.index', [...$query, 'status' => $value]) }}" @if($filters['status'] === $value) aria-current="page" @endif>{{ $label }}<span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ $summary[$value] }}</span></a>@endforeach
    </div></nav>

    <form class="section-card mt-5" method="GET">
        <input type="hidden" name="status" value="{{ $filters['status'] }}">
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-12">
            <label class="md:col-span-2 xl:col-span-4">Search<input name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="Inspection title, period, asset code or name..."></label>
            <label class="xl:col-span-2">Asset Type<select name="asset_type"><option value="">All asset types</option>@foreach($assetTypes as $type)<option value="{{ $type->id }}" @selected($filters['asset_type'] === $type->id)>{{ $type->name }}</option>@endforeach</select></label>
            <label class="xl:col-span-2">Location<select name="building"><option value="">All locations</option>@foreach($buildings as $building)<option value="{{ $building->id }}" @selected($filters['building'] === $building->id)>{{ $building->name }}</option>@endforeach</select></label>
            <label class="xl:col-span-2">Frequency<select name="frequency"><option value="">All frequencies</option>@foreach(['DAILY', 'WEEKLY', 'MONTHLY'] as $frequency)<option value="{{ $frequency }}" @selected($filters['frequency'] === $frequency)>{{ str($frequency)->title() }}</option>@endforeach</select></label>
            <label class="xl:col-span-2">Per Page<select name="per_page">@foreach([20, 50, 100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>@endforeach</select></label>
            <label class="md:col-span-2 xl:col-span-4">Sort<select name="sort"><option value="operational" @selected($filters['sort'] === 'operational')>Overdue and Due First</option><option value="due_asc" @selected($filters['sort'] === 'due_asc')>Earliest Due</option><option value="newest" @selected($filters['sort'] === 'newest')>Newest Created</option><option value="title" @selected($filters['sort'] === 'title')>Inspection Title</option></select></label>
        </div>
        <div class="mt-5 flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end"><a class="btn btn-secondary" href="{{ route('inspections.index', $filters['status'] === 'all' ? [] : ['status' => $filters['status']]) }}">Clear Filters</a><button class="btn btn-primary" type="submit"><x-icon name="filter" />Apply Filters</button></div>
    </form>

    @if($runs->isEmpty())
        <div class="mt-6"><x-empty-state :title="$hasFilters ? 'No matching records' : ($filters['status'] === 'due' ? 'No inspections due' : 'No inspections in this queue')" :description="$hasFilters ? 'Try changing your search or filters.' : ($filters['status'] === 'due' ? 'You currently have no inspections requiring action.' : 'Assigned inspection schedules will appear here when available.')" icon="search">@if($hasFilters)<a class="btn btn-secondary mt-4" href="{{ route('inspections.index') }}">Clear Filters</a>@endif</x-empty-state></div>
    @else
        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach($runs as $run)
                @php
                    $percent = $run->items_count ? min(100, round($run->inspected_count / $run->items_count * 100)) : 0;
                    $available = $run->occurrence->isAvailable();
                    $completed = $run->status === 'COMPLETED';
                    $overdue = !$completed && $run->occurrence->due_at->isPast();
                    $state = $completed ? 'Completed' : (!$available ? 'Upcoming' : ($overdue ? 'Overdue' : ($run->status === 'IN_PROGRESS' ? 'In Progress' : 'Due')));
                    $stateClass = match($state) { 'Completed' => 'status-success', 'Upcoming' => 'status-neutral', 'Overdue' => 'status-danger', 'In Progress' => 'status-active', default => 'status-pending' };
                    $action = !$available || $completed ? 'View Inspection' : ($run->status === 'OPEN' ? 'Start Inspection' : 'Continue Inspection');
                @endphp
                <article class="app-card {{ $overdue ? 'border-rose-300 bg-rose-50/50' : '' }} flex flex-col p-5">
                    <div class="flex items-start justify-between gap-3"><div class="min-w-0"><span class="text-xs font-semibold uppercase tracking-wider text-blue-700">{{ str($run->template->frequency->value)->title() }} Inspection</span><h2 class="mt-2 text-lg leading-6">{{ $run->template->title }}</h2><p class="mt-1 text-sm text-slate-500">{{ $run->period_key }} · {{ $run->template->assetType?->name }}</p></div><span class="status-badge {{ $stateClass }}">● {{ $state }}</span></div>
                    <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="detail-label">Assets</dt><dd class="mt-1 font-semibold text-slate-800">{{ $run->items_count }} {{ str('asset')->plural($run->items_count) }}{{ $management ? '' : ' assigned to you' }}</dd></div><div><dt class="detail-label">Location</dt><dd class="mt-1 text-slate-700">{{ $run->template->building?->name ?? 'Multiple locations' }}</dd></div><div><dt class="detail-label">Due</dt><dd class="mt-1 text-slate-700">{{ $run->occurrence->due_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</dd></div><div><dt class="detail-label">Attention</dt><dd class="mt-1 text-slate-700">{{ $run->attention_count ?: 'None' }}</dd></div></dl>
                    <div class="mt-5"><div class="flex justify-between text-sm text-slate-600"><span>{{ $run->inspected_count }} inspected</span><span>{{ max(0, $run->items_count - $run->inspected_count) }} remaining</span></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="Inspection progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}"><div class="h-full rounded-full {{ $overdue ? 'bg-rose-600' : 'bg-blue-600' }}" style="width: {{ $percent }}%"></div></div></div>
                    @if(!$available)<p class="mt-4 rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-700">Available {{ $run->occurrence->availableAt()->format('M j, Y') }}</p>@endif
                    <a class="btn {{ !$available || $completed ? 'btn-secondary' : 'btn-primary' }} mt-4 w-full" href="{{ route('inspections.show', $run) }}">{{ $action }}</a>
                </article>
            @endforeach
        </div>
        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-slate-500">Showing {{ $runs->firstItem() }}–{{ $runs->lastItem() }} of {{ $runs->total() }} inspections</p>{{ $runs->onEachSide(1)->links() }}</div>
    @endif
@endsection
