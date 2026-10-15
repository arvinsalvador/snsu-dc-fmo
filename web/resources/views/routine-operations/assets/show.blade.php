@extends('layouts.app')
@section('title', $asset->asset_code)
@section('content')
<x-breadcrumbs :items="['Assets / Equipment' => route('assets.index'), $asset->asset_code]" />
<x-page-header :title="$asset->asset_code" :description="$asset->assetType->name.' · '.$asset->name"><x-slot:action><span class="status-badge {{ $asset->lifecycle_status->value === 'ACTIVE' ? 'status-success' : 'status-warning' }}">{{ str($asset->lifecycle_status->value)->replace('_', ' ')->title() }}</span><a class="btn btn-secondary" href="#asset-qr-section"><x-icon name="qr-code" />View QR</a>@can('assets.manage')<a class="btn btn-secondary" href="{{ route('assets.edit', $asset) }}">Edit</a>@endcan</x-slot:action></x-page-header>

@if($pendingAction)<section class="mt-6 rounded-xl border {{ $pendingAction->type === 'DISPOSAL' ? 'border-rose-300 bg-rose-50' : 'border-amber-300 bg-amber-50' }} p-5"><p class="text-xs font-bold uppercase tracking-[0.14em] {{ $pendingAction->type === 'DISPOSAL' ? 'text-rose-700' : 'text-amber-700' }}">{{ $pendingAction->type }} review required</p><p class="mt-2 font-semibold text-slate-950">Reported {{ $pendingAction->requested_at->timezone(config('app.timezone'))->format('M j, Y') }} by {{ $pendingAction->requester->name }}</p><p class="mt-2 text-sm text-slate-700">{{ $pendingAction->problem_description }}</p>
@if(($pendingAction->type === 'REPAIR' && auth()->user()->can('assets.review_repair')) || ($pendingAction->type === 'DISPOSAL' && auth()->user()->can('assets.approve_disposal')))<form method="POST" action="{{ route('assets.actions.review', $pendingAction) }}" class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">@csrf<label>Decision<select name="decision" required>@if($pendingAction->type === 'REPAIR')<option value="APPROVE_REPAIR">Repairable — Approve for Repair</option><option value="NOT_REPAIRABLE">Not Repairable — Disposal Review</option>@else<option value="APPROVE_DISPOSAL">Approve Disposal</option><option value="SEND_FOR_REPAIR">Send for Repair Instead</option>@endif<option value="RETURN">Return for Additional Information</option><option value="REJECT">Reject Request</option></select></label><label>Reason / review note<textarea name="reason" rows="2"></textarea></label><button class="btn btn-primary self-end" type="submit">Record Decision</button></form>@endif</section>@endif

<div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]"><section class="section-card"><h2 class="text-lg">Asset Information</h2><dl class="mt-4 grid gap-5 sm:grid-cols-2"><div><dt class="detail-kicker">Physical condition</dt><dd class="mt-1"><span class="status-badge {{ in_array($asset->condition->value, ['NEEDS_REPAIR','DAMAGED','BROKEN','UNUSABLE']) ? 'status-danger' : 'status-success' }}">{{ str($asset->condition->value)->replace('_', ' ')->title() }}</span></dd></div><div><dt class="detail-kicker">Lifecycle</dt><dd class="mt-1">{{ str($asset->lifecycle_status->value)->replace('_', ' ')->title() }}</dd></div><div><dt class="detail-kicker">Identification</dt><dd class="mt-1">{{ collect([$asset->brand, $asset->model, $asset->serial_number])->filter()->join(' · ') ?: 'Not specified' }}</dd></div><div><dt class="detail-kicker">Installed / acquired</dt><dd class="mt-1">{{ $asset->installed_at?->format('F j, Y') ?: 'Not specified' }}</dd></div><div><dt class="detail-kicker">Inspection frequency</dt><dd class="mt-1">{{ str($asset->inspection_frequency->value)->title() }}</dd></div><div><dt class="detail-kicker">Last inspection</dt><dd class="mt-1">{{ $asset->last_inspected_at?->timezone(config('app.timezone'))->format('F j, Y · g:i A') ?: 'Not inspected yet' }}</dd></div></dl>@if($asset->description)<p class="mt-5 border-t border-slate-100 pt-5 text-slate-700">{{ $asset->description }}</p>@endif</section>
<aside class="section-card"><h2 class="text-lg">Quick Actions</h2><div class="mt-4 grid gap-2">@foreach($assignedInspectionItems as $assignedItem)<a class="btn btn-primary justify-center" href="{{ route('assets.inspect', [$asset, 'item' => $assignedItem->id]) }}">Start {{ $assignedItem->run->period_key }} Inspection</a>@endforeach @can('assets.generate_qr')<button class="btn btn-secondary justify-center" type="button" data-asset-qr-open><x-icon name="printer" />Print QR Label</button>@endcan</div>@if($assignedInspectionItems->isEmpty() && auth()->user()->can('assets.inspect'))<p class="mt-3 text-sm text-slate-500">No active asset inspection is assigned to you.</p>@endif<form method="GET" action="{{ route('assets.index') }}" class="mt-4"><label>Open asset code<input name="q" placeholder="FE-DC-001"></label><button class="btn btn-secondary mt-2 w-full" type="submit">Find Asset</button></form></aside></div>

<section class="section-card mt-6"><h2 class="text-lg">Location</h2><p class="mt-3 text-slate-800">{{ collect([$asset->campus->name, $asset->building->name, $asset->floor?->name, $asset->location?->name])->filter()->join(' · ') }}</p><p class="mt-1 text-sm text-slate-500">{{ $asset->exact_placement ?: 'Exact placement not specified' }}</p></section>

@php
    $qrLocationPrimary = $asset->building->name;
    $qrLocationSecondary = collect([$asset->floor?->name, $asset->location?->name ?: $asset->exact_placement])->filter()->join(' • ');
@endphp
<section id="asset-qr-section" class="section-card mt-6 scroll-mt-24" aria-labelledby="asset-qr-heading">
    <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 id="asset-qr-heading" class="text-lg">QR Code</h2>
            <p class="mt-1 text-sm text-slate-500">Scan this code to open this asset for inspection or maintenance.</p>
        </div>
        @can('assets.generate_qr')
            <button class="btn btn-primary w-full sm:w-auto" type="button" data-asset-qr-open><x-icon name="printer" />Print QR Label</button>
        @endcan
    </div>
    <div class="mt-6 flex flex-col items-center gap-5 rounded-xl border border-slate-200 bg-slate-50 p-5 text-center sm:flex-row sm:text-left">
        <div class="asset-qr-svg w-[220px] max-w-full shrink-0 rounded-xl bg-white p-2 shadow-sm ring-1 ring-slate-200" aria-label="QR code for asset {{ $asset->asset_code }}">{!! $qrSvg !!}</div>
        <div>
            <p class="text-xl font-bold tracking-tight text-slate-950">{{ $asset->asset_code }}</p>
            <p class="mt-1 font-semibold text-slate-700">{{ $asset->assetType->name }}</p>
            <p class="mt-3 text-sm text-slate-600">{{ $qrLocationPrimary }}</p>
            @if($qrLocationSecondary)<p class="mt-0.5 text-sm text-slate-500">{{ $qrLocationSecondary }}</p>@endif
        </div>
    </div>
</section>

@php($assetPhotos = $asset->attachments->where('purpose', 'ASSET_PHOTO')) @php($placementPhotos = $asset->attachments->where('purpose', 'PLACEMENT_PHOTO'))
<section class="section-card mt-6"><h2 class="text-lg">Photos</h2><div class="mt-4 grid gap-6 lg:grid-cols-2">@foreach(['Asset Photos' => $assetPhotos, 'Placement Photos' => $placementPhotos] as $title => $photos)<div><h3 class="font-semibold">{{ $title }}</h3><div class="mt-3 grid grid-cols-2 gap-3">@forelse($photos as $photo)<button type="button" data-evidence-item data-gallery="asset-{{ Str::slug($title) }}" data-full="{{ route('routine-evidence.show', [$photo, 'original']) }}" data-label="{{ $title }}" data-meta="{{ $photo->created_at->timezone(config('app.timezone'))->format('M j, Y · g:i A') }}" class="overflow-hidden rounded-xl border border-slate-200"><img class="aspect-[4/3] w-full object-cover" src="{{ route('routine-evidence.show', [$photo, 'thumbnail']) }}" alt="{{ $title }}" loading="lazy"></button>@empty<p class="col-span-2 text-sm text-slate-500">No {{ str($title)->lower() }} added.</p>@endforelse</div></div>@endforeach</div></section>

@if(auth()->user()->can('assets.request_repair') || auth()->user()->can('assets.request_disposal'))<section class="section-card mt-6"><h2 class="text-lg">Report a Problem</h2><form method="POST" enctype="multipart/form-data" action="{{ route('assets.actions.request', $asset) }}" class="mt-4 space-y-4">@csrf<div class="grid gap-4 sm:grid-cols-2"><label>Request type<select name="type" required>@can('assets.request_repair')<option value="REPAIR">Submit for Repair Review</option>@endcan @can('assets.request_disposal')<option value="DISPOSAL">Recommend for Disposal</option>@endcan</select></label><label>Problem description<textarea name="problem_description" required></textarea></label></div><x-photo-picker id="asset-action-evidence" title="Problem Evidence" help="Add current photos so reviewers can assess the asset." /><button class="btn btn-primary" type="submit">Submit Review Request</button></form></section>@endif

@if($asset->lifecycle_status->value === 'UNDER_REPAIR' && $repairAction && auth()->user()->can('assets.assign_maintenance'))
<section class="section-card mt-6"><h2 class="text-lg">Repair Assignment</h2><p class="mt-1 text-sm text-slate-500">Repair work requires both maintenance permission and an active assignment.</p><form method="POST" action="{{ route('assets.actions.assign-maintenance', $repairAction) }}" class="mt-4 grid gap-4 lg:grid-cols-[1fr_1fr_auto]">@csrf<label>Assigned repair personnel<select name="assignee_ids[]" multiple required class="min-h-32">@foreach($maintenancePersonnel as $person)<option value="{{ $person->id }}" @selected($repairAction->activeAssignees->contains('id', $person->id))>{{ $person->user->name }} — {{ $person->designation ?: $person->personnel_identifier }}</option>@endforeach</select></label><label>Reason / instruction <span class="font-normal text-slate-400">— Optional</span><textarea name="reason" rows="3"></textarea></label><button class="btn btn-primary self-end" type="submit">Save Repair Assignment</button></form></section>
@endif

@if($asset->lifecycle_status->value === 'UNDER_REPAIR' && $canMaintainAsset)<section class="section-card mt-6"><h2 class="text-lg">Record Repair</h2><form method="POST" enctype="multipart/form-data" action="{{ route('assets.maintenance.store', $asset) }}" class="mt-4 space-y-4">@csrf<label>Work performed<textarea name="work_performed" required></textarea></label><label>Parts / materials used <span class="font-normal text-slate-400">— Optional</span><textarea name="parts_materials"></textarea></label><label>Repair result<select name="result" required><option value="REPAIRED_SERVICEABLE">Repaired / Serviceable</option><option value="PARTIALLY_REPAIRED">Partially Repaired</option><option value="NOT_REPAIRABLE">Not Repairable</option></select></label><label>Remarks<textarea name="remarks"></textarea></label><x-photo-picker id="repair-evidence" title="Repair Evidence" help="Take a photo showing the asset after the repair work." /><button class="btn btn-primary" type="submit">Save Repair Update</button></form></section>@endif

@if($asset->lifecycle_status->value === 'APPROVED_FOR_DISPOSAL' && auth()->user()->can('assets.mark_disposed'))<section class="section-card mt-6 border-rose-200"><h2 class="text-lg text-rose-900">Record Physical Disposal</h2><p class="mt-1 text-sm text-slate-500">Approval alone does not dispose the asset. Use this only after physical disposal occurs.</p><form method="POST" enctype="multipart/form-data" action="{{ route('assets.dispose', $asset) }}" class="mt-4 space-y-4">@csrf<label>Disposed date<input type="date" name="disposed_at" required max="{{ now()->toDateString() }}"></label><label>Disposal remarks<textarea name="remarks" required></textarea></label><x-photo-picker id="disposal-evidence" title="Disposal Evidence" help="Optional photos documenting physical disposal." /><button class="btn btn-danger" type="submit">Mark as Disposed</button></form></section>@endif

<section class="section-card mt-6"><h2 class="text-lg">Inspection history</h2><div class="mt-4 space-y-3">@forelse($history as $item)<article class="rounded-lg border border-slate-200 p-4"><div class="flex flex-wrap justify-between gap-2"><p class="font-semibold">{{ str($item->condition ?: $item->result)->replace('_', ' ')->title() }}</p><time class="text-xs text-slate-500">{{ $item->inspected_at->timezone(config('app.timezone'))->format('M j, Y · g:i A') }}</time></div><p class="mt-2 text-sm text-slate-700">{{ $item->generated_summary }}</p><p class="mt-2 text-xs text-slate-500">{{ $item->inspector?->user?->name }}{{ $item->run?->template ? ' · '.$item->run->template->title : ' · Direct asset inspection' }}</p>@if($item->attachments->isNotEmpty())<div class="mt-3 flex flex-wrap gap-2">@foreach($item->attachments as $file)<a href="{{ route('routine-evidence.show', [$file, 'original']) }}" target="_blank"><img class="size-20 rounded-lg border object-cover" src="{{ route('routine-evidence.show', [$file, 'thumbnail']) }}" alt="Inspection evidence"></a>@endforeach</div>@endif</article>@empty<x-empty-state title="No inspection history yet" description="Each inspection creates a permanent asset-specific record." />@endforelse</div><div class="mt-5">{{ $history->links() }}</div></section>

<section class="section-card mt-6"><h2 class="text-lg">Maintenance / Repair History</h2><div class="mt-4 space-y-3">@forelse($asset->maintenanceRecords->sortByDesc('recorded_at') as $record)<article class="rounded-lg border border-slate-200 p-4"><div class="flex justify-between gap-3"><p class="font-semibold">{{ str($record->result)->replace('_', ' ')->title() }}</p><time class="text-xs text-slate-500">{{ $record->recorded_at->timezone(config('app.timezone'))->format('M j, Y') }}</time></div><p class="mt-2 text-sm">{{ $record->work_performed }}</p><p class="mt-2 text-xs text-slate-500">Recorded by {{ $record->recorder->name }}</p></article>@empty<p class="text-sm text-slate-500">No maintenance records yet.</p>@endforelse</div></section>

<section class="section-card mt-6"><h2 class="text-lg">Lifecycle History</h2><div class="mt-4 divide-y divide-slate-100">@forelse($asset->lifecycleEvents->sortByDesc('created_at') as $event)<div class="py-3"><div class="flex justify-between gap-3"><p class="font-semibold">{{ str($event->event)->replace('_', ' ')->title() }}</p><time class="text-xs text-slate-500">{{ $event->created_at->timezone(config('app.timezone'))->format('M j, Y · g:i A') }}</time></div><p class="mt-1 text-sm text-slate-600">{{ $event->actor->name }}@if($event->remarks) · {{ $event->remarks }}@endif</p></div>@empty<p class="text-sm text-slate-500">No lifecycle events recorded.</p>@endforelse</div></section>
@can('assets.generate_qr')
<div class="fixed inset-0 z-[70] hidden items-center justify-center p-0 sm:p-6" data-asset-qr-modal role="dialog" aria-modal="true" aria-labelledby="asset-qr-modal-title" aria-describedby="asset-qr-modal-description" aria-hidden="true">
    <div class="absolute inset-0 bg-slate-950/65 backdrop-blur-[1px]" data-asset-qr-close></div>
    <section class="relative flex h-full max-h-full w-full flex-col overflow-hidden bg-white shadow-2xl sm:h-auto sm:max-h-[calc(100vh-3rem)] sm:max-w-xl sm:rounded-2xl" data-asset-qr-panel tabindex="-1">
        <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
            <div>
                <h2 id="asset-qr-modal-title" class="text-lg">Print Asset QR Label</h2>
                <p id="asset-qr-modal-description" class="mt-1 text-sm text-slate-500">Preview the compact label before opening your browser's print dialog.</p>
            </div>
            <button class="icon-btn" type="button" data-asset-qr-close aria-label="Close print label preview"><x-icon name="close" /></button>
        </header>
        <div class="min-h-0 flex-1 overflow-y-auto bg-slate-100 p-4 sm:p-6">
            <div id="asset-qr-label" class="asset-qr-label mx-auto w-full max-w-[22rem] rounded-xl border-2 border-slate-900 bg-white px-5 py-6 text-center text-slate-950">
                <p class="text-xs font-bold tracking-[0.16em]">SNSU-DC FMO</p>
                <p class="mt-2 text-2xl font-extrabold tracking-tight">{{ $asset->asset_code }}</p>
                <p class="mt-1 text-base font-bold">{{ $asset->assetType->name }}</p>
                <div class="asset-qr-svg mx-auto mt-4 w-[230px] max-w-full" aria-hidden="true">{!! $qrSvg !!}</div>
                <div class="mt-3 text-xs leading-5">
                    <p class="font-semibold">{{ $qrLocationPrimary }}</p>
                    @if($qrLocationSecondary)<p>{{ $qrLocationSecondary }}</p>@endif
                </div>
                <p class="mt-3 text-[10px] font-medium uppercase tracking-[0.08em] text-slate-600">Scan for inspection / maintenance</p>
            </div>
        </div>
        <footer class="grid gap-3 border-t border-slate-200 px-5 py-4 sm:flex sm:justify-end sm:px-6">
            <button class="btn btn-secondary w-full sm:w-auto" type="button" data-asset-qr-close>Cancel</button>
            <button class="btn btn-primary w-full sm:w-auto" type="button" data-asset-qr-print><x-icon name="printer" />Print Label</button>
        </footer>
    </section>
</div>
@endcan
<x-image-lightbox />
@endsection
