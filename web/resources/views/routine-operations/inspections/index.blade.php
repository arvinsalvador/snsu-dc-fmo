@extends('layouts.app')
@section('title', 'Inspections')
@section('content')
<x-page-header :title="auth()->user()->can('inspections.view_all') ? 'Inspections' : 'My Inspections'" :description="auth()->user()->can('inspections.view_all') ? 'Scheduled, item-by-item checks with current team progress.' : 'Only inspection assets currently assigned to you are shown.'" />
@php
    $inspectionSections = collect([
        'Overdue' => $runs->getCollection()->filter(fn ($run) => $run->overdue_count > 0),
        'Due / Current' => $runs->getCollection()->filter(fn ($run) => !$run->overdue_count && !in_array($run->status, ['COMPLETED'], true) && $run->occurrence->due_at->isToday()),
        'Upcoming' => $runs->getCollection()->filter(fn ($run) => !$run->overdue_count && !in_array($run->status, ['COMPLETED'], true) && $run->occurrence->due_at->isFuture() && !$run->occurrence->due_at->isToday()),
        'Completed' => $runs->getCollection()->filter(fn ($run) => $run->status === 'COMPLETED'),
    ])->filter->isNotEmpty();
@endphp
<div class="mt-6 space-y-8">
@forelse($inspectionSections as $section => $sectionRuns)
<section><h2 class="text-sm font-bold uppercase tracking-[0.14em] {{ $section === 'Overdue' ? 'text-rose-700' : 'text-slate-500' }}">{{ $section }}</h2><div class="mt-3 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
@foreach($sectionRuns as $run)
    @php($percent = $run->items_count ? round($run->inspected_count / $run->items_count * 100) : 0)
    <a href="{{ route('inspections.show', $run) }}" class="rounded-xl border {{ $run->overdue_count ? 'border-rose-300 bg-rose-50/50' : 'border-slate-200 bg-white' }} p-5 shadow-sm transition hover:border-blue-300"><div class="flex items-start justify-between gap-3"><div><span class="status-badge status-active">{{ $run->template->frequency->value }} Inspection</span><h3 class="mt-3 text-lg font-semibold text-slate-950">{{ $run->template->title }}</h3><p class="mt-1 text-sm text-slate-500">{{ $run->period_key }} · {{ $run->template->assetType?->name }}</p></div>@if($run->overdue_count)<span class="status-badge status-danger">Overdue</span>@elseif($run->attention_count)<span class="status-badge status-danger">{{ $run->attention_count }} attention</span>@endif</div><div class="mt-5"><p class="text-sm font-semibold text-slate-800">{{ $run->items_count }} {{ Str::plural('asset', $run->items_count) }} {{ auth()->user()->can('inspections.view_all') ? 'in this inspection' : 'assigned to you' }}</p><div class="mt-3 flex justify-between text-sm text-slate-600"><span>{{ $run->inspected_count }} inspected</span><span>{{ max(0, $run->items_count - $run->inspected_count) }} remaining</span></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $run->overdue_count ? 'bg-rose-600' : 'bg-blue-600' }}" style="width: {{ $percent }}%"></div></div><span class="btn btn-primary mt-4 w-full justify-center">{{ $run->status === 'COMPLETED' ? 'View Inspection' : 'Continue Inspection' }}</span></div></a>
@endforeach
</div></section>
@empty
<x-empty-state title="No assigned inspections" description="Current and upcoming inspection schedules appear here when assets are assigned to you." />
@endforelse
</div>
<div class="mt-6">{{ $runs->links() }}</div>
@endsection
