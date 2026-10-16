@extends('layouts.app')
@section('title', 'Recurring Tasks')
@section('content')
<x-page-header title="Recurring Tasks" description="Manage reusable schedules for routine duties and recurring asset inspections.">
    <x-slot:action><div class="flex flex-wrap items-center gap-2"><a class="btn btn-secondary" href="{{ route('routine-tasks.index', ['history' => 1]) }}"><x-icon name="clock" />View Task History</a>@can('routine_tasks.manage')<a class="btn btn-primary" href="{{ route('routine-templates.create') }}"><x-icon name="plus" />New Recurring Task</a>@endcan</div></x-slot:action>
</x-page-header>

<div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    @foreach([['Active Templates', $summaryCounts['active_templates'], 'text-blue-700'], ['Due Today', $summaryCounts['due_today'], 'text-amber-700'], ['Overdue', $summaryCounts['overdue'], 'text-rose-700'], ['Inspection Schedules', $summaryCounts['inspection_schedules'], 'text-indigo-700']] as [$label, $value, $tone])
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-xs"><span class="block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{{ $label }}</span><span class="mt-1 block text-2xl font-semibold {{ $tone }}">{{ $value }}</span></div>
    @endforeach
</div>

<form class="section-card mt-6" method="GET" data-asset-filters>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
        <label class="md:col-span-2 xl:col-span-2">Search<input type="search" name="q" value="{{ request('q') }}" placeholder="Title, location, assignee or asset type"></label>
        <label>Task Type<select name="type"><option value="">All Types</option><option value="GENERAL" @selected(request('type') === 'GENERAL')>General Routine Task</option><option value="INSPECTION" @selected(request('type') === 'INSPECTION')>Asset Inspection</option></select></label>
        <label>Frequency<select name="frequency"><option value="">All Frequencies</option>@foreach(['DAILY', 'WEEKLY', 'MONTHLY'] as $frequency)<option value="{{ $frequency }}" @selected(request('frequency') === $frequency)>{{ str($frequency)->title() }}</option>@endforeach</select></label>
        <label>Status<select name="status"><option value="">All Statuses</option><option value="ACTIVE" @selected(request('status') === 'ACTIVE')>Active</option><option value="INACTIVE" @selected(request('status') === 'INACTIVE')>Inactive</option></select></label>
        <label>Campus<select name="campus" data-asset-campus><option value="">All Campuses</option>@foreach($campuses as $campus)<option value="{{ $campus->id }}" @selected(request('campus') == $campus->id)>{{ $campus->name }}</option>@endforeach</select></label>
        <label>Building<select name="building" data-asset-building><option value="">All Buildings</option>@foreach($buildings as $building)<option value="{{ $building->id }}" data-campus="{{ $building->campus_id }}" @selected(request('building') == $building->id)>{{ $building->name }}</option>@endforeach</select></label>
    </div>
    <div class="mt-5 flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end"><a class="btn btn-secondary" href="{{ route('routine-templates.index') }}">Clear Filters</a><button class="btn btn-primary" type="submit"><x-icon name="filter" />Apply Filters</button></div>
</form>

<div class="mt-6 hidden rounded-xl border border-slate-200 bg-white shadow-xs xl:block">
    <div class="overflow-x-auto">
        <table class="min-w-[78rem]">
            <thead><tr><th>Task</th><th>Type</th><th>Frequency</th><th>Location / Scope</th><th>Assigned Team</th><th>Next Due</th><th>Overdue</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
            @forelse($templates as $template)
                @php($location = collect([$template->campus?->name, $template->building?->name, $template->floor?->name, $template->location?->name, $template->exact_placement])->filter()->join(' · '))
                @php($teamNames = $template->activeAssignees->pluck('user.name')->filter()->values())
                @php($nextDue = $template->next_due_at ? \Carbon\Carbon::parse($template->next_due_at)->timezone(config('app.timezone')) : null)
                <tr>
                    <td class="max-w-64"><p class="font-semibold text-slate-950">{{ $template->title }}</p><p class="mt-1 text-xs text-slate-500">{{ $template->occurrences_count }} {{ Str::plural('occurrence', $template->occurrences_count) }} generated</p></td>
                    <td><span class="text-sm font-medium text-slate-800">{{ $template->task_type->value === 'INSPECTION' ? 'Asset Inspection' : 'General Routine Task' }}</span></td>
                    <td><span class="status-badge status-active">{{ $template->frequency->value }}</span><p class="mt-1.5 text-xs text-slate-500">Due {{ \Carbon\Carbon::parse($template->due_time)->format('g:i A') }}</p></td>
                    <td class="max-w-64">@if($template->task_type->value === 'INSPECTION')<p class="font-medium text-slate-800">{{ $template->assetType?->name ?: 'Asset type unavailable' }}</p><p class="mt-1 text-xs text-slate-500">{{ match($template->inspection_asset_scope) { 'SPECIFIC' => 'Selected active assets', 'TYPE_LOCATION' => 'Active assets in '.($location ?: 'configured location'), default => 'All active assets'.($template->building ? ' · '.$template->building->name : '') } }}</p>@else<p class="font-medium text-slate-800">{{ $template->building?->name ?: $template->campus?->name ?: 'No location set' }}</p><p class="mt-1 text-xs text-slate-500">{{ collect([$template->location?->name, $template->exact_placement])->filter()->join(' · ') ?: 'Campus-wide routine' }}</p>@endif</td>
                    <td class="max-w-48"><p class="truncate font-medium text-slate-800" title="{{ $teamNames->join(', ') }}">{{ $teamNames->first() ?: 'Unassigned' }}</p>@if($teamNames->count() > 1)<p class="mt-1 text-xs font-semibold text-blue-700">+{{ $teamNames->count() - 1 }} {{ Str::plural('other', $teamNames->count() - 1) }}</p>@endif</td>
                    <td class="whitespace-nowrap">@if($nextDue)<p class="font-medium text-slate-800">{{ $nextDue->isToday() ? 'Today' : $nextDue->format('M j, Y') }}</p><p class="mt-1 text-xs text-slate-500">{{ $nextDue->format('g:i A') }}</p>@else<span class="text-slate-400">—</span>@endif</td>
                    <td>@if($template->overdue_occurrences_count)<span class="status-badge status-danger">{{ $template->overdue_occurrences_count }} overdue</span>@else<span class="text-slate-400">—</span>@endif</td>
                    <td><span class="status-badge {{ $template->is_active ? 'status-active' : 'status-neutral' }}">{{ $template->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td><div class="flex items-center justify-end gap-2"><a class="btn btn-secondary !min-h-9 px-3" href="{{ route('routine-tasks.index', ['template' => $template->id]) }}"><x-icon name="eye" size="size-4" />View</a><x-action-menu label="More actions for {{ $template->title }}">
                        @can('routine_tasks.manage')<a class="action-menu-item" href="{{ route('routine-templates.edit', $template) }}" role="menuitem"><x-icon name="pencil" size="size-4" />Edit Template</a>@endcan
                        @can('routine_tasks.assign')<button type="button" class="action-menu-item" role="menuitem" data-assignment-open data-template-id="{{ $template->id }}" data-action="{{ route('routine-templates.assign', $template) }}" data-title="{{ $template->title }}" data-meta="{{ $template->frequency->value }} · {{ $location ?: 'No location set' }}" data-assignment-ids="{{ $template->activeAssignees->pluck('id')->values()->toJson() }}" data-note="{{ $template->assignment_note }}"><x-icon name="users" size="size-4" />Assign Team</button>@endcan
                        @can('inspections.assign')@if($template->currentInspectionRun)<a class="action-menu-item" href="{{ route('inspections.show', $template->currentInspectionRun) }}#manage-current-assignments" role="menuitem"><x-icon name="assignment" size="size-4" />Manage Current Assignments</a>@endif @endcan
                        <a class="action-menu-item" href="{{ route('routine-tasks.index', ['template' => $template->id]) }}" role="menuitem"><x-icon name="clock" size="size-4" />View Occurrences</a>
                        @can('routine_tasks.manage')@if($template->is_active)<form method="POST" action="{{ route('routine-templates.deactivate', $template) }}" data-confirm="Deactivate this template? Existing occurrences and history will remain.">@csrf @method('DELETE')<button class="action-menu-item action-menu-item-danger" type="submit" role="menuitem"><x-icon name="warning" size="size-4" />Deactivate</button></form>@endif @endcan
                    </x-action-menu></div></td>
                </tr>
            @empty
                <tr><td colspan="9"><x-empty-state title="No recurring tasks match these filters" description="Clear the filters or create a new recurring duty or inspection schedule." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-6 grid gap-4 md:grid-cols-2 xl:hidden">
    @forelse($templates as $template)
        @php($location = collect([$template->campus?->name, $template->building?->name, $template->floor?->name, $template->location?->name, $template->exact_placement])->filter()->join(' · '))
        @php($teamNames = $template->activeAssignees->pluck('user.name')->filter()->values())
        @php($nextDue = $template->next_due_at ? \Carbon\Carbon::parse($template->next_due_at)->timezone(config('app.timezone')) : null)
        <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3"><div><div class="flex flex-wrap gap-2"><span class="status-badge status-active">{{ $template->frequency->value }}</span><span class="status-badge status-neutral">{{ $template->task_type->value === 'INSPECTION' ? 'Asset Inspection' : 'General Task' }}</span></div><h2 class="mt-3 text-lg">{{ $template->title }}</h2></div><span class="status-badge {{ $template->is_active ? 'status-active' : 'status-neutral' }}">{{ $template->is_active ? 'Active' : 'Inactive' }}</span></div>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2"><div><dt class="detail-kicker">Location / scope</dt><dd class="mt-1">{{ $template->task_type->value === 'INSPECTION' ? collect([$template->assetType?->name, $template->building?->name])->filter()->join(' · ') : ($location ?: 'No location set') }}</dd></div><div><dt class="detail-kicker">Assigned team</dt><dd class="mt-1">{{ $teamNames->first() ?: 'Unassigned' }}@if($teamNames->count() > 1) <span class="font-semibold text-blue-700">+{{ $teamNames->count() - 1 }} others</span>@endif</dd></div><div><dt class="detail-kicker">Next due</dt><dd class="mt-1">{{ $nextDue ? ($nextDue->isToday() ? 'Today · '.$nextDue->format('g:i A') : $nextDue->format('M j, Y · g:i A')) : '—' }}</dd></div><div><dt class="detail-kicker">Overdue</dt><dd class="mt-1">@if($template->overdue_occurrences_count)<span class="status-badge status-danger">{{ $template->overdue_occurrences_count }} overdue</span>@else<span class="text-slate-400">—</span>@endif</dd></div></dl>
            <div class="mt-5 flex items-center gap-2 border-t border-slate-100 pt-4"><a class="btn btn-secondary flex-1" href="{{ route('routine-tasks.index', ['template' => $template->id]) }}"><x-icon name="eye" />View</a><x-action-menu label="More actions for {{ $template->title }}">
                @can('routine_tasks.manage')<a class="action-menu-item" href="{{ route('routine-templates.edit', $template) }}" role="menuitem"><x-icon name="pencil" size="size-4" />Edit Template</a>@endcan
                @can('routine_tasks.assign')<button type="button" class="action-menu-item" role="menuitem" data-assignment-open data-template-id="{{ $template->id }}" data-action="{{ route('routine-templates.assign', $template) }}" data-title="{{ $template->title }}" data-meta="{{ $template->frequency->value }} · {{ $location ?: 'No location set' }}" data-assignment-ids="{{ $template->activeAssignees->pluck('id')->values()->toJson() }}" data-note="{{ $template->assignment_note }}"><x-icon name="users" size="size-4" />Assign Team</button>@endcan
                @can('inspections.assign')@if($template->currentInspectionRun)<a class="action-menu-item" href="{{ route('inspections.show', $template->currentInspectionRun) }}#manage-current-assignments" role="menuitem"><x-icon name="assignment" size="size-4" />Manage Current Assignments</a>@endif @endcan
                <a class="action-menu-item" href="{{ route('routine-tasks.index', ['template' => $template->id]) }}" role="menuitem"><x-icon name="clock" size="size-4" />View Occurrences</a>
                @can('routine_tasks.manage')@if($template->is_active)<form method="POST" action="{{ route('routine-templates.deactivate', $template) }}" data-confirm="Deactivate this template? Existing occurrences and history will remain.">@csrf @method('DELETE')<button class="action-menu-item action-menu-item-danger" type="submit" role="menuitem"><x-icon name="warning" size="size-4" />Deactivate</button></form>@endif @endcan
            </x-action-menu></div>
        </article>
    @empty
        <div class="md:col-span-2"><x-empty-state title="No recurring tasks match these filters" description="Clear the filters or create a new recurring duty or inspection schedule." /></div>
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
