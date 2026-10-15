@extends('layouts.app')
@section('title', $inspection->template->title)
@section('content')
@php
    $inspected = $inspection->items->where('status', 'INSPECTED')->count();
    $total = $inspection->items->count();
    $percent = $total ? round($inspected / $total * 100) : 0;
    $teamProgress = $inspection->items->flatMap(fn ($item) => $item->activeAssignees->map(fn ($person) => ['person' => $person, 'complete' => $item->status === 'INSPECTED']))
        ->groupBy(fn ($row) => $row['person']->id)
        ->map(fn ($rows) => ['person' => $rows->first()['person'], 'total' => $rows->count(), 'complete' => $rows->where('complete', true)->count()]);
@endphp
<x-breadcrumbs :items="['Inspections' => route('inspections.index'), $inspection->period_key]" />
<x-page-header :title="$inspection->template->title" :description="$inspection->period_key.' · '.$inspection->template->assetType?->name">
    <x-slot:action><span class="status-badge {{ $inspection->status === 'COMPLETED' ? 'status-success' : 'status-active' }}">{{ str($inspection->status)->replace('_', ' ')->title() }}</span></x-slot:action>
</x-page-header>

<section class="section-card mt-6">
    <div class="flex items-end justify-between"><div><p class="text-3xl font-bold">{{ $inspected }} / {{ $total }}</p><p class="mt-1 text-sm text-slate-500">{{ auth()->user()->can('inspections.view_all') ? 'assets inspected' : 'assigned assets inspected' }}</p></div><p class="text-2xl font-semibold text-blue-800">{{ $percent }}%</p></div>
    <div class="mt-4 h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full bg-blue-600" style="width:{{ $percent }}%"></div></div>
    @if($total === 0)<x-alert type="warning" class="mt-4">No active assets matched this inspection scope when the run was generated.</x-alert>@endif
    @if($inspection->occurrence->due_at->isPast() && $inspection->items->where('status', '!=', 'INSPECTED')->isNotEmpty())<p class="mt-4 font-semibold text-rose-700">{{ $inspection->items->where('status', '!=', 'INSPECTED')->count() }} unfinished {{ Str::plural('asset', $inspection->items->where('status', '!=', 'INSPECTED')->count()) }} overdue</p>@endif
</section>

@can('inspections.view_all')
<section class="section-card mt-6"><div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg">Assigned Team Progress</h2><p class="mt-1 text-sm text-slate-500">Operational completion for this inspection period.</p></div>@can('inspections.assign')<a class="btn btn-secondary" href="#manage-current-assignments">Manage Current Inspection Assignments</a>@endcan</div><div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@forelse($teamProgress as $progress)<div class="rounded-xl border border-slate-200 p-4"><p class="font-semibold">{{ $progress['person']->user?->name }}</p><p class="mt-1 text-sm text-slate-500">{{ $progress['complete'] }} / {{ $progress['total'] }} completed</p></div>@empty<p class="text-sm text-slate-500">No inspectors are assigned.</p>@endforelse</div></section>
@endcan

@can('inspections.assign')
<section id="manage-current-assignments" class="section-card mt-6 scroll-mt-24"><h2 class="text-lg">Manage Current Inspection Assignments</h2><p class="mt-1 text-sm text-slate-500">This updates unfinished assets in this period only. It does not change the recurring template or completed inspections.</p><form method="POST" action="{{ route('inspections.assignments.run', $inspection) }}" class="mt-4 grid gap-4 lg:grid-cols-[1fr_1fr_auto]">@csrf<label>Assigned inspectors<select name="assignee_ids[]" multiple required class="min-h-40">@foreach($personnel as $person)<option value="{{ $person->id }}" @selected($inspection->items->where('status', '!=', 'INSPECTED')->flatMap->activeAssignees->pluck('id')->unique()->contains($person->id)) @disabled(!$person->isAssignable())>{{ $person->user?->name }} — {{ $person->designation ?: $person->personnel_identifier }}{{ $person->isAssignable() ? ' · '.$person->active_inspection_items_count.' active' : ' · Unavailable' }}</option>@endforeach</select></label><label>Reason <span class="font-normal text-slate-400">— Optional</span><textarea name="reason" rows="3" placeholder="Example: coverage while the previous inspector is on leave"></textarea></label><button class="btn btn-primary self-end" type="submit">Save Current Team</button></form></section>
@endcan

<div class="mt-6 space-y-4">
@foreach($inspection->items as $item)
<details data-collapsible class="rounded-xl border {{ $item->status === 'INSPECTED' ? 'border-emerald-200 bg-emerald-50/30' : ($inspection->occurrence->due_at->isPast() ? 'border-rose-300 bg-white' : 'border-slate-200 bg-white') }} p-4">
    <x-collapsible-summary><span class="flex flex-wrap items-center gap-2"><strong>{{ $item->asset->asset_code }}</strong><span class="text-sm font-normal text-slate-500">{{ $item->asset->building->name }}{{ $item->asset->location ? ' · '.$item->asset->location->name : '' }}</span><span class="status-badge {{ $item->status === 'INSPECTED' ? 'status-success' : 'status-neutral' }}">{{ $item->status === 'INSPECTED' ? 'Inspection Complete' : 'Assigned Inspection' }}</span>@if($item->status !== 'INSPECTED' && $inspection->occurrence->due_at->isPast())<span class="status-badge status-danger">Overdue</span>@endif</span></x-collapsible-summary>
    <div class="mt-4 border-t border-slate-100 pt-4">
        <p class="text-sm text-slate-600"><strong>Assigned inspectors:</strong> {{ $item->activeAssignees->pluck('user.name')->filter()->join(', ') ?: 'No active inspector' }}</p>
        @if($item->status === 'INSPECTED')
            <p class="mt-4 text-sm text-slate-700">{{ $item->generated_summary }}</p>@if($item->remarks)<p class="mt-2 text-sm text-slate-500">{{ $item->remarks }}</p>@endif<p class="mt-2 text-xs text-slate-500">Completed by {{ $item->inspector?->user?->name }} · {{ $item->inspected_at?->timezone(config('app.timezone'))->format('M j, Y · g:i A') }}</p>@if($item->attachments->isNotEmpty())<div class="mt-3 flex gap-2">@foreach($item->attachments as $file)<a href="{{ route('routine-evidence.show', [$file, 'original']) }}" target="_blank"><img class="size-20 rounded-lg border object-cover" src="{{ route('routine-evidence.show', [$file, 'thumbnail']) }}" alt="Inspection evidence"></a>@endforeach</div>@endif
        @elseif(in_array($item->id, $performableItemIds, true))
            <form method="POST" enctype="multipart/form-data" action="{{ route('inspections.items.record', [$inspection, $item]) }}" class="mt-5 space-y-5">@csrf<fieldset><legend class="text-sm font-semibold">Asset checklist</legend><div class="mt-3 space-y-3">@foreach($item->checklist_snapshot ?: $item->asset->assetType->checklist as $check)<div class="grid gap-2 rounded-lg bg-slate-50 p-3 sm:grid-cols-[1fr_auto]"><span class="text-sm font-medium">{{ $check['label'] }}</span><div class="flex gap-3">@foreach(['YES' => 'Yes', 'NO' => 'No', 'NOT_APPLICABLE' => 'N/A'] as $value => $label)<label class="flex items-center gap-1 text-sm"><input type="radio" name="checklist[{{ $check['code'] }}]" value="{{ $value }}" required> {{ $label }}</label>@endforeach</div></div>@endforeach</div></fieldset><label>Inspection result<select name="result" required><option value="">Select result</option>@foreach($item->asset->assetType->result_options as $result)<option value="{{ $result }}">{{ str($result)->replace('_', ' ')->title() }}</option>@endforeach</select></label><label>Remarks <span class="font-normal text-slate-400">— Optional</span><textarea name="remarks"></textarea></label><x-photo-picker :id="'inspection-'.$item->id" title="Inspection evidence" help="Add a clear photo of this specific asset when useful or required." /><button class="btn btn-primary" type="submit">Save Item Inspection</button></form>
        @else
            <p class="mt-4 text-sm font-medium text-slate-700">Inspection not assigned to you</p><p class="mt-1 text-sm text-slate-500">You may review this item if you have oversight access, but only an actively assigned inspector with inspection permission can submit it.</p>
        @endif

        @can('inspections.assign')
            @if($item->status !== 'INSPECTED')<details class="mt-5 rounded-xl border border-slate-200 p-4"><summary class="font-semibold text-blue-800">Reassign this asset</summary><form method="POST" action="{{ route('inspections.assignments.item', [$inspection, $item]) }}" class="mt-4 grid gap-4 lg:grid-cols-[1fr_1fr_auto]">@csrf<label>Assigned inspectors<select name="assignee_ids[]" multiple required class="min-h-32">@foreach($personnel as $person)<option value="{{ $person->id }}" @selected($item->activeAssignees->contains('id', $person->id)) @disabled(!$person->isAssignable())>{{ $person->user?->name }} — {{ $person->designation ?: $person->personnel_identifier }}</option>@endforeach</select></label><label>Reason <span class="font-normal text-slate-400">— Optional</span><textarea name="reason" rows="3"></textarea></label><button class="btn btn-secondary self-end" type="submit">Save Asset Assignment</button></form></details>@endif
            @if($item->assignments->isNotEmpty())<details class="mt-3"><summary class="text-sm font-semibold text-slate-600">Assignment history</summary><div class="mt-3 space-y-2">@foreach($item->assignments->sortByDesc('assigned_at') as $assignment)<p class="text-xs text-slate-500"><strong>{{ $assignment->personnel->user?->name }}</strong> assigned {{ $assignment->assigned_at->timezone(config('app.timezone'))->format('M j, Y · g:i A') }} by {{ $assignment->assigner?->name }}@if($assignment->unassigned_at) · removed {{ $assignment->unassigned_at->timezone(config('app.timezone'))->format('M j, Y · g:i A') }} by {{ $assignment->unassigner?->name }}@endif @if($assignment->change_reason)· {{ $assignment->change_reason }}@endif</p>@endforeach</div></details>@endif
        @endcan
    </div>
</details>
@endforeach
</div>
@endsection
