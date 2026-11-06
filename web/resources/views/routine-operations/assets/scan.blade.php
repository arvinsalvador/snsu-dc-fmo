@extends('layouts.app')
@section('title', $asset->asset_code)
@section('content')
<x-page-header :title="$asset->asset_code" :description="$asset->assetType->name">
    <x-slot:action><x-asset-lifecycle-badge :status="$asset->lifecycle_status" /></x-slot:action>
</x-page-header>

@if($scopeWarning)<x-alert type="warning" class="mt-6">{{ $scopeWarning }}</x-alert>@endif

<div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
    <section class="section-card">
        <div class="grid gap-5 sm:grid-cols-[8rem_1fr]">
            @if($asset->attachments->first())
                <img class="aspect-square w-full rounded-xl border border-slate-200 object-cover" src="{{ route('routine-evidence.show', [$asset->attachments->first(), 'thumbnail']) }}" alt="{{ $asset->asset_code }}">
            @endif
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="detail-kicker">Current condition</dt><dd class="mt-1"><x-asset-condition-badge :condition="$asset->condition" /></dd></div>
                <div><dt class="detail-kicker">Last inspection</dt><dd class="mt-1">{{ $asset->last_inspected_at?->timezone(config('app.timezone'))->format('F j, Y') ?: 'Not inspected yet' }}</dd></div>
                <div class="sm:col-span-2"><dt class="detail-kicker">Location</dt><dd class="mt-1">{{ collect([$asset->building->name, $asset->floor?->name, $asset->location?->name, $asset->exact_placement])->filter()->join(' · ') }}</dd></div>
            </dl>
        </div>
    </section>
    <aside class="section-card"><h2 class="text-lg">Asset record</h2><p class="mt-2 text-sm text-slate-500">View inspection, maintenance, and lifecycle history.</p><a class="btn btn-secondary mt-4 w-full justify-center" href="{{ route('assets.show', $asset) }}">Open Asset Record</a></aside>
</div>

<section class="section-card mt-6">
    <h2 class="text-lg">Current Inspection Assignment</h2>
    <div class="mt-4 space-y-3">
        @forelse($inspectionOptions as $option)
            @php($item = $option['item'])
            <article class="rounded-xl border {{ $option['can_inspect'] ? 'border-blue-300 bg-blue-50/50' : 'border-slate-200' }} p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><p class="text-xs font-bold uppercase tracking-[0.14em] {{ $item->run->occurrence->due_at->isPast() ? 'text-rose-700' : 'text-blue-700' }}">{{ !$item->run->occurrence->isAvailable() ? 'Upcoming inspection' : ($item->run->occurrence->due_at->isPast() ? 'Inspection overdue' : 'Inspection due') }}</p><h3 class="mt-1 font-semibold text-slate-950">{{ $item->run->template->title }}</h3><p class="mt-1 text-sm text-slate-500">{{ $item->run->period_key }} · Due {{ $item->run->occurrence->due_at->timezone(config('app.timezone'))->format('M j, Y · g:i A') }}</p></div>
                    @if($option['is_assigned'])<span class="status-badge status-success">Assigned to you</span>@else<span class="status-badge status-neutral">Assigned to another team</span>@endif
                </div>
                @if(auth()->user()->can('inspections.view_all'))<p class="mt-3 text-sm text-slate-600"><strong>Assigned:</strong> {{ $item->activeAssignees->pluck('user.name')->filter()->join(', ') ?: 'No active inspector' }}</p>@endif
                @if($option['can_inspect'])
                    <a class="btn btn-primary mt-4 w-full justify-center sm:w-auto" href="{{ route('assets.inspect', [$asset, 'item' => $item->id]) }}">Start Inspection</a>
                @elseif($option['is_assigned'] && !$item->run->occurrence->isAvailable())
                    <p class="mt-3 text-sm font-medium text-slate-700">Available {{ $item->run->occurrence->availableAt()->format('M j, Y') }}</p><p class="mt-1 text-sm text-slate-500">This inspection cannot be started yet.</p>
                @elseif(!$option['is_assigned'])
                    <p class="mt-3 text-sm font-medium text-slate-700">Inspection not assigned to you</p><p class="mt-1 text-sm text-slate-500">You can view this asset, but the current inspection is assigned to another FMO personnel member.</p>
                @else
                    <p class="mt-3 text-sm text-slate-500">You are assigned, but your account does not currently have permission to submit inspections.</p>
                @endif
            </article>
        @empty
            <x-empty-state title="No current inspection" description="This asset has no open scheduled inspection at this time." />
        @endforelse
    </div>
</section>

<section class="section-card mt-6">
    <h2 class="text-lg">Preventive Maintenance</h2>
    <div class="mt-4 space-y-3">
        @forelse($pmOptions as $option)
            @php($pm = $option['occurrence'])
            <article class="rounded-xl border {{ $option['is_assigned'] ? 'border-cyan-300 bg-cyan-50/50' : 'border-slate-200' }} p-4">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-[0.14em] text-cyan-800">{{ $pm->status->value === 'UPCOMING' ? 'Upcoming Preventive Maintenance' : ($pm->is_overdue ? 'Maintenance Overdue' : 'Preventive Maintenance Due') }}</p><h3 class="mt-1 font-semibold">{{ $pm->plan_name_snapshot }}</h3><p class="mt-1 text-sm text-slate-500">Due {{ $pm->due_odometer_km !== null ? 'at '.number_format($pm->due_odometer_km).' km' : $pm->due_at->timezone(config('app.timezone'))->format('M j, Y · g:i A') }}</p></div>@if($option['is_assigned'])<span class="status-badge status-success">Assigned to you</span>@else<span class="status-badge status-neutral">Not assigned</span>@endif</div>
                @if($option['can_start'])<form method="POST" action="{{ route('preventive-maintenance.tasks.start',$pm) }}" class="mt-4">@csrf<button class="btn btn-primary w-full sm:w-auto" type="submit">Start Preventive Maintenance</button></form>@elseif($pm->status->value === 'IN_PROGRESS' && $option['is_assigned'])<a class="btn btn-primary mt-4 w-full justify-center sm:w-auto" href="{{ route('preventive-maintenance.tasks.show',$pm) }}">Continue Preventive Maintenance</a>@elseif($pm->status->value === 'UPCOMING')<p class="mt-3 text-sm font-medium">Available {{ $pm->available_at->timezone(config('app.timezone'))->format('M j, Y') }}</p><p class="mt-1 text-sm text-slate-500">This maintenance cannot be started yet.</p>@elseif(!$option['is_assigned'])<p class="mt-3 text-sm text-slate-500">You may view the asset, but only assigned personnel can perform this maintenance.</p>@endif
            </article>
        @empty<x-empty-state title="No current PM" description="This asset has no open preventive-maintenance task." />@endforelse
    </div>
</section>

@if($repairAction)
<section class="section-card mt-6"><h2 class="text-lg">Current Repair Assignment</h2>@if($repairAssigned)<div class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-4"><p class="font-semibold text-amber-950">Repair assigned to you</p><p class="mt-1 text-sm text-amber-900">Open the asset record to document the repair work.</p><a class="btn btn-primary mt-4" href="{{ route('assets.show', $asset) }}">Open Repair Action</a></div>@elseif(auth()->user()->can('assets.maintain'))<p class="mt-3 text-sm text-slate-500">Repair is assigned to another FMO personnel member.</p>@endif</section>
@endif
@endsection
