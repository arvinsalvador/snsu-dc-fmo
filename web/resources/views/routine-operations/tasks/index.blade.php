@extends('layouts.app')
@section('title', 'My Routine Tasks')
@section('content')
<x-page-header title="My Routine Tasks" description="Routine duties and inspections assigned to you.">
    <x-slot:action>@can('routine_tasks.view_all')<a class="btn btn-secondary" href="{{ route('routine-templates.index') }}"><x-icon name="settings" />Recurring Tasks</a>@endcan</x-slot:action>
</x-page-header>

<div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    <a href="{{ route('routine-tasks.index', ['status' => 'OVERDUE']) }}" class="rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-rose-950 transition hover:border-rose-400"><span class="block text-xs font-bold uppercase tracking-[0.12em] text-rose-700">Overdue</span><span class="mt-1 block text-2xl font-semibold">{{ $summaryCounts['overdue'] }}</span></a>
    <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-xs"><span class="block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Due Today</span><span class="mt-1 block text-2xl font-semibold text-slate-900">{{ $summaryCounts['due_today'] }}</span></div>
    <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-xs"><span class="block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Upcoming</span><span class="mt-1 block text-2xl font-semibold text-slate-900">{{ $summaryCounts['upcoming'] }}</span></div>
    <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-xs"><span class="block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Completed Today</span><span class="mt-1 block text-2xl font-semibold text-emerald-700">{{ $summaryCounts['completed_today'] }}</span></div>
</div>

<form class="filter-bar mt-6" method="GET">
    @if(request('template'))<input type="hidden" name="template" value="{{ request('template') }}">@endif
    @if(request()->boolean('history'))<input type="hidden" name="history" value="1">@endif
    <label>Status<select name="status"><option value="">All Current Work</option>@foreach(['OVERDUE','DUE','IN_PROGRESS','PARTIALLY_COMPLETED','WAITING_FOR_SUPPLIES','UPCOMING','COMPLETED','COMPLETED_LATE','UNABLE_TO_COMPLETE'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
    <button class="btn btn-primary" type="submit"><x-icon name="filter" />Apply</button>
    @if(request()->hasAny(['status', 'template', 'history']))<a class="btn btn-secondary" href="{{ route('routine-tasks.index') }}">Clear</a>@endif
</form>

@php
    $timezone = config('app.timezone');
    $today = now($timezone)->toDateString();
    $taskSections = collect([
        'Overdue — Update Required' => $occurrences->getCollection()->filter->requiresOverdueAttention(),
        'Due Today' => $occurrences->getCollection()->filter(fn($task) => !$task->requiresOverdueAttention() && !$task->status->isClosed() && $task->due_at->timezone($timezone)->toDateString() === $today),
        'Upcoming' => $occurrences->getCollection()->filter(fn($task) => !$task->requiresOverdueAttention() && !$task->status->isClosed() && $task->due_at->timezone($timezone)->toDateString() > $today),
        'Completed Today' => $occurrences->getCollection()->filter(fn($task) => in_array($task->status->value, ['COMPLETED','COMPLETED_LATE'], true) && $task->completed_at?->timezone($timezone)->toDateString() === $today),
    ]);
    $groupedTaskIds = $taskSections->flatten()->pluck('id');
    if (request('template') || request()->boolean('history') || in_array(request('status'), ['COMPLETED','COMPLETED_LATE','UNABLE_TO_COMPLETE'], true)) {
        $taskSections->put('Earlier Task History', $occurrences->getCollection()->whereNotIn('id', $groupedTaskIds));
    }
    $taskSections = $taskSections->filter->isNotEmpty();
@endphp

<div class="mt-8 space-y-10">
@forelse($taskSections as $section => $tasks)
    <section aria-labelledby="{{ Str::slug($section) }}">
        <div class="flex items-center justify-between gap-3"><h2 id="{{ Str::slug($section) }}" class="text-sm font-bold uppercase tracking-[0.14em] {{ str_starts_with($section, 'Overdue') ? 'text-rose-700' : 'text-slate-600' }}">{{ $section }}</h2><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $tasks->count() }}</span></div>
        <div class="mt-3 grid gap-4 lg:grid-cols-2">
        @foreach($tasks as $task)
            @php
                $overdue = $task->requiresOverdueAttention();
                $overdueDays = $overdue ? max(1, (int) $task->due_at->timezone($timezone)->startOfDay()->diffInDays(now($timezone)->startOfDay())) : 0;
                $isInspection = $task->template->task_type === \App\Enums\RoutineTaskType::Inspection;
                $isClosed = $task->status->isClosed();
                $destination = $task->inspectionRun ? route('inspections.show', $task->inspectionRun) : route('routine-tasks.show', $task);
                $actionLabel = $isInspection && !$isClosed ? 'Continue Inspection' : ($isClosed ? 'View Completed Task' : ($overdue ? 'Update Overdue Task' : (in_array($task->status->value, ['IN_PROGRESS','PARTIALLY_COMPLETED','WAITING_FOR_SUPPLIES'], true) ? 'Continue Task' : 'Start Task')));
                $teamNames = $task->template->activeAssignees->pluck('user.name')->filter()->values();
                $otherTeamNames = $teamNames->reject(fn($name) => $name === auth()->user()->name)->values();
            @endphp
            <article class="flex h-full flex-col rounded-xl border {{ $overdue ? 'border-rose-300 bg-rose-50/70' : 'border-slate-200 bg-white' }} p-5 shadow-sm sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><span class="status-badge status-active">{{ $task->template->frequency->value }}{{ $isInspection ? ' INSPECTION' : '' }}</span><span class="status-badge {{ $overdue ? 'status-danger' : ($isClosed ? 'status-success' : 'status-neutral') }}">{{ $overdue ? 'Overdue' : str($task->status->value)->replace('_', ' ')->title() }}</span></div><h3 class="mt-3 text-lg font-semibold text-slate-950">{{ $task->template->title }}</h3><p class="mt-1 text-sm text-slate-500">{{ $task->period_key }}</p></div>
                    <div class="text-left sm:text-right"><p class="text-xs font-bold uppercase tracking-wider text-slate-500">Due</p><p class="mt-1 text-sm font-semibold {{ $overdue ? 'text-rose-900' : 'text-slate-800' }}">{{ $task->due_at->timezone($timezone)->isToday() ? 'Today · '.$task->due_at->timezone($timezone)->format('g:i A') : $task->due_at->timezone($timezone)->format('M j, Y · g:i A') }}</p>@if($overdue)<p class="mt-1 text-sm font-bold text-rose-700">{{ $overdueDays }} {{ Str::plural('day', $overdueDays) }} overdue</p>@endif</div>
                </div>

                @if($isInspection && $task->inspectionRun)
                    @php($assignedTotal = $task->inspectionRun->assigned_items_count ?? 0)
                    @php($assignedInspected = $task->inspectionRun->assigned_inspected_count ?? 0)
                    @php($progress = $assignedTotal ? (int) round(($assignedInspected / $assignedTotal) * 100) : 0)
                    <div class="mt-5 flex-1 rounded-xl border border-slate-200/80 bg-white/80 p-4">
                        <p class="font-semibold text-slate-900">{{ $task->template->assetType?->name ?: 'Asset inspection' }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ $assignedTotal }} {{ Str::plural('asset', $assignedTotal) }} {{ auth()->user()->can('routine_tasks.view_all') ? 'in this inspection' : 'assigned to you' }}</p>
                        <div class="mt-4 flex items-center justify-between gap-3 text-sm"><span class="font-semibold text-slate-800">{{ $assignedInspected }} / {{ $assignedTotal }} inspected</span><span class="text-slate-500">{{ max(0, $assignedTotal - $assignedInspected) }} remaining</span></div>
                        <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $assignedTotal }}" aria-valuenow="{{ $assignedInspected }}" aria-label="Inspection progress"><div class="h-full rounded-full {{ $overdue ? 'bg-rose-600' : 'bg-blue-700' }}" style="width: {{ $progress }}%"></div></div>
                    </div>
                @else
                    <dl class="mt-5 grid flex-1 gap-4 rounded-xl border border-slate-200/80 bg-white/80 p-4 sm:grid-cols-2"><div><dt class="detail-kicker">Location</dt><dd class="mt-1 text-sm text-slate-800">{{ collect([$task->template->building?->name, $task->template->location?->name])->filter()->join(' · ') ?: $task->template->campus?->name ?: 'No location set' }}</dd></div><div><dt class="detail-kicker">Assigned team</dt><dd class="mt-1 text-sm text-slate-800">{{ auth()->user()->can('routine_tasks.view_all') ? ($teamNames->join(', ') ?: 'Unassigned') : 'You'.($otherTeamNames->isNotEmpty() ? ' + '.$otherTeamNames->first().($otherTeamNames->count() > 1 ? ' +'.($otherTeamNames->count() - 1).' others' : '') : '') }}</dd></div></dl>
                    @if($overdue)<p class="mt-3 rounded-lg bg-rose-100 px-3 py-2 text-sm font-medium text-rose-900">This task requires an update.</p>@endif
                    @if($task->status === \App\Enums\RoutineOccurrenceStatus::PartiallyCompleted && $task->remaining_work)<p class="mt-3 rounded-lg bg-amber-100 px-3 py-2 text-sm text-amber-950">Remaining: {{ $task->remaining_work }}</p>@endif
                @endif

                <a class="btn {{ $overdue ? 'btn-danger' : 'btn-primary' }} mt-5 w-full" href="{{ $destination }}" @if($overdue && !$isInspection) aria-label="Update Task" @endif>{{ $actionLabel }}</a>
            </article>
        @endforeach
        </div>
    </section>
@empty
    <x-empty-state title="No routine tasks in this view" description="Assigned overdue, due, upcoming, and completed-today work will appear here." />
@endforelse
</div>
<div class="mt-6">{{ $occurrences->links() }}</div>
@endsection
