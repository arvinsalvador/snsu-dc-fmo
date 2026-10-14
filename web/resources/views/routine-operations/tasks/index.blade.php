@extends('layouts.app')
@section('title', 'My Routine Tasks')
@section('content')
<x-page-header title="My Routine Tasks" description="Planned facilities duties are separate from reactive Work Orders. Overdue tasks stay here until you provide an accountable update.">
    <x-slot:action>@can('routine_tasks.view_all')<a class="btn btn-secondary" href="{{ route('routine-templates.index') }}"><x-icon name="settings" />Recurring templates</a>@endcan</x-slot:action>
</x-page-header>
<form class="filter-bar mt-6" method="GET"><label>Status<select name="status"><option value="">All statuses</option>@foreach(['OVERDUE','DUE','IN_PROGRESS','PARTIALLY_COMPLETED','WAITING_FOR_SUPPLIES','UPCOMING','COMPLETED','COMPLETED_LATE','UNABLE_TO_COMPLETE'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>@endforeach</select></label><button class="btn btn-primary" type="submit"><x-icon name="filter" />Filter</button></form>
@php
    $today = now(config('app.timezone'))->toDateString();
    $taskSections = collect([
        'Overdue — Update Required' => $occurrences->getCollection()->filter(fn($task) => in_array($task->status->value, ['OVERDUE','PARTIALLY_COMPLETED','WAITING_FOR_SUPPLIES'], true)),
        'Due Today' => $occurrences->getCollection()->filter(fn($task) => in_array($task->status->value, ['DUE','IN_PROGRESS'], true) && $task->expected_date->toDateString() === $today),
        'Upcoming' => $occurrences->getCollection()->filter(fn($task) => $task->status->value === 'UPCOMING'),
        'Completed Today' => $occurrences->getCollection()->filter(fn($task) => in_array($task->status->value, ['COMPLETED','COMPLETED_LATE'], true) && $task->completed_at?->timezone(config('app.timezone'))->toDateString() === $today),
        'Recent History' => $occurrences->getCollection()->filter(fn($task) => in_array($task->status->value, ['UNABLE_TO_COMPLETE','COMPLETED','COMPLETED_LATE'], true) && $task->completed_at?->timezone(config('app.timezone'))->toDateString() !== $today),
    ])->filter->isNotEmpty();
@endphp
<div class="mt-6 space-y-8">
@forelse($taskSections as $section => $tasks)
<section><h2 class="text-sm font-bold uppercase tracking-[0.14em] text-slate-500">{{ $section }}</h2><div class="mt-3 space-y-3">
@foreach($tasks as $task)
    @php($overdue = $task->status === \App\Enums\RoutineOccurrenceStatus::Overdue)
    <a href="{{ $task->inspectionRun ? route('inspections.show', $task->inspectionRun) : route('routine-tasks.show', $task) }}" class="block rounded-xl border {{ $overdue ? 'border-amber-300 bg-amber-50/60' : 'border-slate-200 bg-white' }} p-5 shadow-sm transition hover:border-blue-300">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><div class="flex flex-wrap items-center gap-2"><span class="status-badge {{ $overdue ? 'status-warning' : 'status-neutral' }}">{{ str($task->status->value)->replace('_', ' ')->title() }}</span><span class="status-badge status-active">{{ $task->template->frequency->value }}</span>@if($task->template->task_type === \App\Enums\RoutineTaskType::Inspection)<span class="status-badge status-neutral">Inspection</span>@endif</div><h2 class="mt-3 text-lg">{{ $task->template->title }}</h2><p class="mt-1 text-sm text-slate-500">{{ collect([$task->template->campus?->name, $task->template->building?->name, $task->template->location?->name])->filter()->join(' · ') }}</p></div><div class="text-right"><p class="text-sm font-semibold {{ $overdue ? 'text-amber-900' : 'text-slate-800' }}">Due {{ $task->due_at->timezone(config('app.timezone'))->format('M j, Y · g:i A') }}</p>@if($overdue)<p class="mt-1 text-xs font-bold uppercase tracking-wide text-amber-800">Update required</p>@endif</div></div>
        @if($task->status === \App\Enums\RoutineOccurrenceStatus::PartiallyCompleted && $task->remaining_work)<p class="mt-3 rounded-lg bg-amber-100 px-3 py-2 text-sm text-amber-900">Remaining: {{ $task->remaining_work }}</p>@endif
    </a>
@endforeach
</div></section>
@empty <x-empty-state title="No routine tasks in this view" description="Assigned due, upcoming, and unresolved routine work will appear here." /> @endforelse
</div>
<div class="mt-6">{{ $occurrences->links() }}</div>
@endsection
