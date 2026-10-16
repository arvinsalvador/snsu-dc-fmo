@extends('layouts.app')
@section('title', 'Assets / Equipment')
@section('content')
<x-page-header title="Assets / Equipment" description="Individually coded facilities equipment with condition, lifecycle, QR access and permanent inspection history.">
    <x-slot:action>
        @can('asset_types.manage')<a class="btn btn-secondary" href="{{ route('asset-types.index') }}"><x-icon name="tag" />Manage Types</a>@endcan
        @can('assets.manage')<a class="btn btn-primary" href="{{ route('assets.create') }}"><x-icon name="plus" />Add Asset</a>@endcan
    </x-slot:action>
</x-page-header>

<form class="section-card mt-6" method="GET" data-asset-filters>
    <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
        <label class="md:col-span-2 xl:col-span-2">Search<input name="q" value="{{ request('q') }}" placeholder="Search code, type, name or location..."></label>
        <label>Type<select name="type"><option value="">All Types</option>@foreach($assetTypes as $type)<option value="{{ $type->id }}" @selected(request('type') === $type->id)>{{ $type->name }}</option>@endforeach</select></label>
        <label>Campus<select name="campus" data-asset-campus><option value="">All Campuses</option>@foreach($campuses as $campus)<option value="{{ $campus->id }}" @selected(request('campus') == $campus->id)>{{ $campus->name }}</option>@endforeach</select></label>
        <label>Building<select name="building" data-asset-building><option value="">All Buildings</option>@foreach($buildings as $building)<option value="{{ $building->id }}" data-campus="{{ $building->campus_id }}" @selected(request('building') == $building->id)>{{ $building->name }}</option>@endforeach</select></label>
        <label>Condition<select name="condition"><option value="">All Conditions</option>@foreach(['PRISTINE','GOOD','FAIR','NEEDS_REPAIR','DAMAGED','BROKEN','UNUSABLE'] as $condition)<option value="{{ $condition }}" @selected(request('condition') === $condition)>{{ str($condition)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
        <label>Lifecycle<select name="lifecycle"><option value="">All Lifecycle States</option>@foreach(['ACTIVE','UNDER_REPAIR','FOR_DISPOSAL_REVIEW','APPROVED_FOR_DISPOSAL','DISPOSED','RETIRED'] as $state)<option value="{{ $state }}" @selected(request('lifecycle') === $state)>{{ str($state)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
        <label class="flex-row items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 md:col-span-2 xl:col-span-1"><input class="size-4" type="checkbox" name="pending" value="1" @checked(request()->boolean('pending'))><span><strong class="block text-sm">Pending Review</strong><span class="block text-xs font-normal text-slate-500">Repair or disposal review only</span></span></label>
    </div>
    <div class="mt-5 flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
        <a class="btn btn-secondary justify-center" href="{{ route('assets.index') }}">Clear Filters</a>
        <button class="btn btn-primary justify-center" type="submit"><x-icon name="filter" />Apply Filters</button>
    </div>
</form>

<div class="mt-6 hidden overflow-hidden rounded-xl border border-slate-200 bg-white md:block"><div class="overflow-x-auto"><table class="min-w-[70rem] w-full"><thead><tr><th>Asset</th><th>Type</th><th>Location</th><th>Condition</th><th>Lifecycle</th><th>Last Inspection</th><th>Pending Action</th><th class="text-right">Actions</th></tr></thead><tbody>
@forelse($assets as $asset)<tr class="align-top">
    <td><a class="font-semibold text-blue-800 hover:underline" href="{{ route('assets.show', $asset) }}">{{ $asset->asset_code }}</a><p class="mt-1 max-w-xs text-xs text-slate-500">{{ $asset->name }}{{ $asset->exact_placement ? ' near '.$asset->exact_placement : '' }}</p></td>
    <td class="text-sm">{{ $asset->assetType->name }}</td>
    <td class="text-sm"><span class="block font-medium text-slate-800">{{ $asset->building->name }}</span><span class="mt-1 block text-xs text-slate-500">{{ collect([$asset->floor?->name, $asset->location?->name])->filter()->join(' • ') ?: $asset->campus->name }}</span>@if($asset->exact_placement)<span class="mt-1 block max-w-xs text-xs text-slate-400">{{ $asset->exact_placement }}</span>@endif</td>
    <td><x-asset-condition-badge :condition="$asset->condition" /></td>
    <td><x-asset-lifecycle-badge :status="$asset->lifecycle_status" /></td>
    <td class="text-sm text-slate-700">@if($asset->last_inspected_at)<span class="block font-medium">{{ $asset->last_inspected_at->timezone(config('app.timezone'))->format('M j, Y') }}</span><span class="mt-1 block text-xs text-slate-500">{{ str($asset->condition->value)->replace('_', ' ')->title() }}</span>@else<span class="text-slate-500">Not yet inspected</span>@endif</td>
    <td>@if($asset->pending_actions_count)<span class="status-badge status-warning">{{ $asset->pending_actions_count }} pending</span>@else<span class="text-sm text-slate-400">None</span>@endif</td>
    <td><div class="flex items-start justify-end gap-2"><a class="btn btn-secondary !min-h-9 px-3" href="{{ route('assets.show', $asset) }}"><x-icon name="eye" size="size-4" />View</a><x-action-menu label="More actions for {{ $asset->asset_code }}">@can('assets.manage')<a class="action-menu-item" href="{{ route('assets.edit', $asset) }}" role="menuitem"><x-icon name="pencil" size="size-4" />Edit Asset</a>@endcan @can('assets.generate_qr')<a class="action-menu-item" href="{{ route('assets.show', [$asset, 'print_qr' => 1]) }}" role="menuitem"><x-icon name="printer" size="size-4" />Print QR Label</a>@endcan @can('inspections.assign')<a class="action-menu-item" href="{{ route('assets.show', $asset) }}#inspection-actions" role="menuitem"><x-icon name="assignment" size="size-4" />Manage Inspection Assignment</a>@endcan</x-action-menu></div></td>
</tr>@empty<tr><td colspan="8"><x-empty-state title="No assets match these filters" description="Clear or adjust the filters, or add individually coded equipment." /></td></tr>@endforelse
</tbody></table></div></div>

<div class="mt-6 grid gap-4 md:hidden">
@forelse($assets as $asset)<article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div class="flex items-start justify-between gap-3"><div><a class="font-bold text-blue-800" href="{{ route('assets.show', $asset) }}">{{ $asset->asset_code }}</a><p class="mt-1 text-sm text-slate-600">{{ $asset->name }}</p></div><x-asset-lifecycle-badge :status="$asset->lifecycle_status" /></div><dl class="mt-4 grid gap-3 text-sm"><div><dt class="detail-kicker">Type</dt><dd class="mt-1">{{ $asset->assetType->name }}</dd></div><div><dt class="detail-kicker">Location</dt><dd class="mt-1">{{ $asset->building->name }} · {{ collect([$asset->floor?->name, $asset->location?->name])->filter()->join(' • ') ?: $asset->campus->name }}</dd></div><div class="flex items-center justify-between gap-3"><div><dt class="detail-kicker">Condition</dt><dd class="mt-1"><x-asset-condition-badge :condition="$asset->condition" /></dd></div><div class="text-right"><dt class="detail-kicker">Last inspection</dt><dd class="mt-1">{{ $asset->last_inspected_at?->timezone(config('app.timezone'))->format('M j, Y') ?: 'Not yet inspected' }}</dd></div></div></dl><div class="mt-4 flex items-center gap-2"><a class="btn btn-secondary flex-1 justify-center" href="{{ route('assets.show', $asset) }}"><x-icon name="eye" />View Asset</a><x-action-menu label="More actions for {{ $asset->asset_code }}">@can('assets.manage')<a class="action-menu-item" href="{{ route('assets.edit', $asset) }}" role="menuitem"><x-icon name="pencil" size="size-4" />Edit Asset</a>@endcan @can('assets.generate_qr')<a class="action-menu-item" href="{{ route('assets.show', [$asset, 'print_qr' => 1]) }}" role="menuitem"><x-icon name="printer" size="size-4" />Print QR Label</a>@endcan @can('inspections.assign')<a class="action-menu-item" href="{{ route('assets.show', $asset) }}#inspection-actions" role="menuitem"><x-icon name="assignment" size="size-4" />Manage Inspection Assignment</a>@endcan</x-action-menu></div></article>@empty<x-empty-state title="No assets match these filters" description="Clear or adjust the filters, or add individually coded equipment." />@endforelse
</div>
<div class="mt-6">{{ $assets->links() }}</div>
@endsection
