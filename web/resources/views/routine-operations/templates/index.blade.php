@extends('layouts.app')
@section('title', 'Recurring Tasks')
@section('content')
<x-page-header title="Recurring Tasks" description="Reusable schedules generate dated occurrences. Inspection team changes apply to future periods; current inspection assignments are managed from the generated run.">
    <x-slot:action>@can('routine_tasks.manage')<a class="btn btn-primary" href="{{ route('routine-templates.create') }}"><x-icon name="plus" />New template</a>@endcan</x-slot:action>
</x-page-header>

<div class="mt-6 hidden overflow-hidden rounded-xl border border-slate-200 bg-white lg:block">
    <div class="overflow-x-auto">
        <table class="min-w-full">
            <thead><tr><th>Task</th><th>Schedule</th><th>Location</th><th>Assigned team</th><th>Activity</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($templates as $template)
                @php($location = collect([$template->campus?->name, $template->building?->name, $template->location?->name])->filter()->join(' · '))
                <tr>
                    <td><p class="font-semibold text-slate-900">{{ $template->title }}</p><p class="mt-1 text-xs text-slate-500">{{ str($template->task_type->value)->title() }}</p></td>
                    <td><span class="status-badge status-active">{{ str($template->frequency->value)->title() }}</span><p class="mt-2 text-xs text-slate-500">Due {{ \Carbon\Carbon::parse($template->due_time)->format('g:i A') }}</p></td>
                    <td class="max-w-64 text-sm text-slate-700">{{ $location ?: 'No location set' }}</td>
                    <td class="max-w-64 text-sm"><p class="font-medium text-slate-800">{{ $template->activeAssignees->pluck('user.name')->filter()->join(', ') ?: 'Unassigned' }}</p><p class="mt-1 text-xs text-slate-500">{{ $template->activeAssignees->count() }} {{ Str::plural('person', $template->activeAssignees->count()) }}</p></td>
                    <td><p class="text-sm text-slate-700">{{ $template->occurrences_count }} {{ Str::plural('occurrence', $template->occurrences_count) }}</p>@if($template->overdue_occurrences_count)<p class="mt-1 text-sm font-bold text-rose-700">{{ $template->overdue_occurrences_count }} overdue</p>@endif</td>
                    <td><span class="status-badge {{ $template->is_active ? 'status-active' : 'status-neutral' }}">{{ $template->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td><div class="flex flex-wrap gap-2">
                        @can('routine_tasks.assign')<button type="button" class="btn btn-secondary !min-h-9" data-assignment-open data-template-id="{{ $template->id }}" data-action="{{ route('routine-templates.assign', $template) }}" data-title="{{ $template->title }}" data-meta="{{ str($template->frequency->value)->title() }} · {{ $location ?: 'No location set' }}" data-assignment-ids="{{ $template->activeAssignees->pluck('id')->values()->toJson() }}" data-note="{{ $template->assignment_note }}">Assign Team</button>@endcan
                        @can('routine_tasks.manage')<a class="btn btn-secondary !min-h-9" href="{{ route('routine-templates.edit', $template) }}">Edit</a>@if($template->is_active)<form method="POST" action="{{ route('routine-templates.deactivate', $template) }}" data-confirm="Deactivate this template? Existing occurrences and history will remain.">@csrf @method('DELETE')<button class="btn btn-danger !min-h-9" type="submit">Deactivate</button></form>@endif @endcan
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty-state title="No recurring templates" description="Create the first planned routine duty or inspection schedule." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-6 space-y-4 lg:hidden">
    @forelse($templates as $template)
        @php($location = collect([$template->campus?->name, $template->building?->name, $template->location?->name])->filter()->join(' · '))
        <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3"><div><p class="font-semibold text-slate-900">{{ $template->title }}</p><p class="mt-1 text-sm text-slate-500">{{ str($template->task_type->value)->title() }} · {{ str($template->frequency->value)->title() }}</p></div><span class="status-badge {{ $template->is_active ? 'status-active' : 'status-neutral' }}">{{ $template->is_active ? 'Active' : 'Inactive' }}</span></div>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="detail-kicker">Location</dt><dd class="mt-1">{{ $location ?: 'No location set' }}</dd></div><div><dt class="detail-kicker">Assigned team</dt><dd class="mt-1">{{ $template->activeAssignees->pluck('user.name')->filter()->join(', ') ?: 'Unassigned' }}</dd></div><div><dt class="detail-kicker">Activity</dt><dd class="mt-1">{{ $template->occurrences_count }} {{ Str::plural('occurrence', $template->occurrences_count) }} @if($template->overdue_occurrences_count)<span class="font-bold text-rose-700">· {{ $template->overdue_occurrences_count }} overdue</span>@endif</dd></div></dl>
            <div class="mt-5 flex flex-wrap gap-2">
                @can('routine_tasks.assign')<button type="button" class="btn btn-secondary" data-assignment-open data-template-id="{{ $template->id }}" data-action="{{ route('routine-templates.assign', $template) }}" data-title="{{ $template->title }}" data-meta="{{ str($template->frequency->value)->title() }} · {{ $location ?: 'No location set' }}" data-assignment-ids="{{ $template->activeAssignees->pluck('id')->values()->toJson() }}" data-note="{{ $template->assignment_note }}">Assign Team</button>@endcan
                @can('routine_tasks.manage')<a class="btn btn-secondary" href="{{ route('routine-templates.edit', $template) }}">Edit</a>@if($template->is_active)<form method="POST" action="{{ route('routine-templates.deactivate', $template) }}" data-confirm="Deactivate this template? Existing occurrences and history will remain.">@csrf @method('DELETE')<button class="btn btn-danger" type="submit">Deactivate</button></form>@endif @endcan
            </div>
        </article>
    @empty
        <x-empty-state title="No recurring templates" description="Create the first planned routine duty or inspection schedule." />
    @endforelse
</div>
<div class="mt-6">{{ $templates->links() }}</div>

@can('routine_tasks.assign')
<div class="fixed inset-0 z-[70] hidden items-center justify-center p-4 sm:p-6" data-assignment-modal data-reopen-template="{{ old('assignment_template_id') }}" data-server-errors="{{ $errors->hasAny(['assignment_template_id', 'assignee_ids', 'assignee_ids.*', 'assignment_note']) ? 'true' : 'false' }}">
    <button type="button" class="absolute inset-0 bg-slate-950/60 backdrop-blur-[1px]" aria-label="Close Assign Team dialog" data-assignment-close></button>
    <section class="relative flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl sm:max-h-[calc(100vh-3rem)]" role="dialog" aria-modal="true" aria-labelledby="assignment-modal-title" tabindex="-1" data-assignment-panel>
        <header class="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
            <div><p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-700">Routine task assignment</p><h2 id="assignment-modal-title" class="mt-1 text-xl font-semibold text-slate-950" data-assignment-title>Assign Team</h2><p class="mt-1 text-sm text-slate-500" data-assignment-meta></p></div>
            <button type="button" class="grid size-10 shrink-0 place-items-center rounded-lg text-2xl text-slate-500 hover:bg-slate-100 hover:text-slate-900" aria-label="Close dialog" data-assignment-close>&times;</button>
        </header>
        <form method="POST" action="" class="flex min-h-0 flex-1 flex-col" data-assignment-form>
            @csrf
            <input type="hidden" name="assignment_template_id" value="{{ old('assignment_template_id') }}" data-assignment-template-id>
            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 sm:px-6">
                <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950"><p class="font-semibold">This is the schedule’s default team.</p><p class="mt-1">For asset inspections, changes apply to future generated periods. Existing inspection runs keep their current assignments unless they are manually reassigned from the inspection run.</p></div>
                <label>Search personnel<input type="search" placeholder="Search by name, designation, or skill" autocomplete="off" data-assignment-search></label>
                <fieldset><legend class="text-sm font-semibold text-slate-900">Responsible personnel</legend><p class="mt-1 text-xs text-slate-500">Select one or more active, approved FMO personnel.</p>
                    <div class="mt-3 space-y-2" data-personnel-list>
                    @foreach($personnel as $person)
                        @php($assignable = $person->isAssignable())
                        @php($primarySkill = $person->skills->first(fn($skill) => (bool) $skill->pivot->is_primary))
                        <label class="flex items-start gap-3 rounded-xl border p-4 transition {{ $assignable ? 'cursor-pointer border-slate-200 hover:border-blue-300 has-checked:border-blue-500 has-checked:bg-blue-50' : 'cursor-not-allowed border-slate-200 bg-slate-50 opacity-70' }}" data-personnel-card data-search="{{ str(collect([$person->user?->name, $person->designation, $person->skills->pluck('name')->join(' ')])->filter()->join(' '))->lower() }}">
                            <input class="mt-1" type="checkbox" name="assignee_ids[]" value="{{ $person->id }}" @checked(in_array($person->id, old('assignee_ids', []), true)) @disabled(!$assignable)>
                            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-slate-200 text-sm font-bold text-slate-700">{{ str($person->user?->name ?: '?')->substr(0, 1)->upper() }}</span>
                            <span class="min-w-0 flex-1"><span class="flex flex-wrap items-center gap-2"><span class="font-semibold text-slate-900">{{ $person->user?->name ?: 'User account unavailable' }}</span>@if(!$assignable)<span class="status-badge status-neutral">Unavailable</span>@endif</span><span class="mt-0.5 block text-sm text-slate-500">{{ $person->designation ?: 'FMO personnel' }}</span><span class="mt-2 flex flex-wrap gap-1.5">@if($primarySkill)<span class="rounded-full bg-blue-100 px-2 py-1 text-xs font-semibold text-blue-800">Primary: {{ $primarySkill->name }}</span>@endif @foreach($person->skills->reject(fn($skill) => (bool) $skill->pivot->is_primary)->take(3) as $skill)<span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-700">{{ $skill->name }}</span>@endforeach</span><span class="mt-2 block text-xs font-medium text-slate-500">{{ $person->active_routine_tasks_count }} active routine {{ Str::plural('task', $person->active_routine_tasks_count) }}</span></span>
                        </label>
                    @endforeach
                    </div>
                    <p class="mt-3 hidden rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900" data-personnel-empty>No personnel match your search.</p>
                </fieldset>
                <label>Internal assignment note <span class="font-normal text-slate-400">— Optional</span><textarea name="assignment_note" maxlength="2000" rows="3" data-assignment-note>{{ old('assignment_note') }}</textarea><span class="mt-1 block text-xs font-normal text-slate-500">Visible to personnel who manage assignments; it is not shown in the staff task view.</span></label>
                <div class="{{ $errors->hasAny(['assignment_template_id', 'assignee_ids', 'assignee_ids.*', 'assignment_note']) ? '' : 'hidden' }} rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-sm text-rose-900" role="alert" aria-live="polite" data-assignment-error>@foreach($errors->get('assignee_ids') as $message)<p>{{ $message }}</p>@endforeach @foreach($errors->get('assignee_ids.*') as $messages)@foreach($messages as $message)<p>{{ $message }}</p>@endforeach @endforeach @error('assignment_note')<p>{{ $message }}</p>@enderror @error('assignment_template_id')<p>{{ $message }}</p>@enderror</div>
            </div>
            <footer class="flex shrink-0 flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" class="btn btn-secondary" data-assignment-close>Cancel</button><button type="submit" class="btn btn-primary" data-loading-text="Saving assignment…">Save Assignment</button></footer>
        </form>
    </section>
</div>
@endcan
@endsection
