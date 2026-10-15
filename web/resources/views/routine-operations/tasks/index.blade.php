@extends('layouts.app')
@section('title', 'My Routine Tasks')
@section('content')
<x-page-header title="My Routine Tasks" description="Planned facilities duties are separate from reactive Work Orders. Overdue tasks stay here until you provide an accountable update.">
    <x-slot:action>@can('routine_tasks.view_all')<a class="btn btn-secondary" href="{{ route('routine-templates.index') }}"><x-icon name="settings" />Recurring templates</a>@endcan</x-slot:action>
</x-page-header>
<div class="mt-6 grid gap-3 sm:grid-cols-3">
    <a href="{{ route('routine-tasks.index', ['status' => 'OVERDUE']) }}" class="rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-rose-950 transition hover:border-rose-400"><span class="block text-xs font-bold uppercase tracking-[0.12em] text-rose-700">Overdue</span><span class="mt-1 block text-2xl font-semibold">{{ $summaryCounts['overdue'] }}</span></a>
    <div class="rounded-xl border border-slate-200 bg-white px-4 py-3"><span class="block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Due today</span><span class="mt-1 block text-2xl font-semibold text-slate-900">{{ $summaryCounts['due_today'] }}</span></div>
    <div class="rounded-xl border border-slate-200 bg-white px-4 py-3"><span class="block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Upcoming</span><span class="mt-1 block text-2xl font-semibold text-slate-900">{{ $summaryCounts['upcoming'] }}</span></div>
</div>
<form class="filter-bar mt-6" method="GET"><label>Status<select name="status"><option value="">All statuses</option>@foreach(['OVERDUE','DUE','IN_PROGRESS','PARTIALLY_COMPLETED','WAITING_FOR_SUPPLIES','UPCOMING','COMPLETED','COMPLETED_LATE','UNABLE_TO_COMPLETE'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>@endforeach</select></label><button class="btn btn-primary" type="submit"><x-icon name="filter" />Filter</button></form>
@php
    $today = now(config('app.timezone'))->toDateString();
    $taskSections = collect([
        'Overdue — Update Required' => $occurrences->getCollection()->filter->requiresOverdueAttention(),
        'Due Today' => $occurrences->getCollection()->filter(fn($task) => !$task->requiresOverdueAttention() && !$task->status->isClosed() && $task->due_at->timezone(config('app.timezone'))->toDateString() === $today),
        'Upcoming' => $occurrences->getCollection()->filter(fn($task) => !$task->requiresOverdueAttention() && $task->status->value === 'UPCOMING'),
        'Completed Today' => $occurrences->getCollection()->filter(fn($task) => in_array($task->status->value, ['COMPLETED','COMPLETED_LATE'], true) && $task->completed_at?->timezone(config('app.timezone'))->toDateString() === $today),
        'Recent History' => $occurrences->getCollection()->filter(fn($task) => in_array($task->status->value, ['UNABLE_TO_COMPLETE','COMPLETED','COMPLETED_LATE'], true) && $task->completed_at?->timezone(config('app.timezone'))->toDateString() !== $today),
    ])->filter->isNotEmpty();
@endphp
<div class="mt-6 space-y-8">
@forelse($taskSections as $section => $tasks)
<section><h2 class="text-sm font-bold uppercase tracking-[0.14em] {{ str_starts_with($section, 'Overdue') ? 'text-rose-700' : 'text-slate-500' }}">{{ $section }}</h2><div class="mt-3 space-y-3">
@foreach($tasks as $task)
    @php($overdue = $task->requiresOverdueAttention())
    @php($overdueDays = $overdue ? max(1, (int) $task->due_at->timezone(config('app.timezone'))->startOfDay()->diffInDays(now(config('app.timezone'))->startOfDay())) : 0)
    <a href="{{ $task->inspectionRun ? route('inspections.show', $task->inspectionRun) : route('routine-tasks.show', $task) }}" class="block rounded-xl border {{ $overdue ? 'border-rose-300 bg-rose-50/70 hover:border-rose-400' : 'border-slate-200 bg-white hover:border-blue-300' }} p-5 shadow-sm transition">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><div class="flex flex-wrap items-center gap-2"><span class="status-badge {{ $overdue ? 'status-danger' : 'status-neutral' }}">{{ $overdue ? 'Overdue' : str($task->status->value)->replace('_', ' ')->title() }}</span><span class="status-badge status-active">{{ $task->template->frequency->value }}</span>@if($task->template->task_type === \App\Enums\RoutineTaskType::Inspection)<span class="status-badge status-neutral">Inspection</span>@endif</div><h2 class="mt-3 text-lg">{{ $task->template->title }}</h2><p class="mt-1 text-sm text-slate-500">{{ collect([$task->template->campus?->name, $task->template->building?->name, $task->template->location?->name])->filter()->join(' · ') }}</p></div><div class="text-left sm:text-right"><p class="text-sm font-semibold {{ $overdue ? 'text-rose-950' : 'text-slate-800' }}">Due {{ $task->due_at->timezone(config('app.timezone'))->format('M j, Y · g:i A') }}</p>@if($overdue)<p class="mt-1 text-sm font-bold text-rose-700">{{ $overdueDays }} {{ Str::plural('day', $overdueDays) }} overdue</p><span class="mt-3 inline-flex rounded-lg bg-rose-700 px-3 py-2 text-sm font-semibold text-white">Update Task</span>@endif</div></div>
        @if($task->status === \App\Enums\RoutineOccurrenceStatus::PartiallyCompleted && $task->remaining_work)<p class="mt-3 rounded-lg bg-rose-100 px-3 py-2 text-sm text-rose-900">Remaining: {{ $task->remaining_work }}</p>@endif
    </a>
@endforeach
</div></section>
@empty <x-empty-state title="No routine tasks in this view" description="Assigned due, upcoming, and unresolved routine work will appear here." /> @endforelse
</div>
<div class="mt-6">{{ $occurrences->links() }}</div>
@endsection
