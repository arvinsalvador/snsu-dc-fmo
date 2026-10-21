@extends('layouts.app')

@section('title', isset($order) ? 'Update Work Order' : 'New Work Order Request')

@section('content')
    <x-breadcrumbs :items="['My Requests' => route('work-orders.mine'), isset($order) ? 'Update request' : 'New request']" />
    <x-page-header :title="isset($order) ? 'Update '.$order->work_order_number : 'New Work Order Request'" description="Report a facilities repair, maintenance or service concern." />

    @if (isset($order) && $order->status === \App\Enums\WorkOrderStatus::NeedsInformation)
        <section class="mt-6 max-w-5xl rounded-xl border border-amber-200 bg-amber-50/50 p-5" aria-labelledby="clarification-title">
            <div class="flex gap-3">
                <span class="mt-0.5 text-amber-800"><x-icon name="mail" /></span>
                <div class="min-w-0">
                    <h2 id="clarification-title" class="text-lg">Additional Information Requested</h2>
                    <p class="mt-1 text-sm text-slate-600">FMO requested additional information. Please review the request before resubmitting.</p>
                    @if ($clarification)
                        <div class="mt-4 rounded-xl border border-amber-200 bg-white p-4">
                            <p class="whitespace-pre-line text-base leading-7 text-slate-800">{{ $clarification->requester_message }}</p>
                            <p class="mt-4 border-t border-amber-100 pt-3 text-xs text-slate-600">Requested by {{ $clarification->actor?->name ?? 'FMO' }} · <time datetime="{{ $clarification->created_at?->toIso8601String() }}">{{ $clarification->created_at?->timezone('Asia/Manila')->format('F j, Y · g:i A') }}</time></p>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif

    <div class="mt-6 max-w-5xl rounded-xl border border-slate-200 bg-white p-3 shadow-xs sm:p-4">
        <ol class="grid gap-2 text-xs font-semibold text-slate-600 sm:grid-cols-5">
            @foreach (['Work Type', 'Location', 'Details', 'Preference', 'Attachments'] as $step => $label)
                <li class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2"><span class="grid size-5 place-items-center rounded-full bg-blue-700 text-[11px] text-white">{{ $step + 1 }}</span>{{ $label }}</li>
            @endforeach
        </ol>
    </div>

    <form class="mt-6 max-w-5xl space-y-6" method="POST" enctype="multipart/form-data" action="{{ isset($order) ? ($order->status === \App\Enums\WorkOrderStatus::NeedsInformation ? route('work-orders.workflow', [$order, 'resubmit']) : route('work-orders.update', $order)) : route('work-orders.store') }}">
        @csrf
        @if (isset($order) && $order->status === \App\Enums\WorkOrderStatus::Submitted) @method('PUT') @endif

        <section class="form-section">
            <h2>1. Work Type</h2><p>Select the type and urgency of the requested facilities work.</p>
            <div class="grid gap-5 sm:grid-cols-2">
                <label>Work Type <span class="text-rose-600">*</span>
                    <select class="@error('work_order_category_id') field-invalid @enderror" name="work_order_category_id" required aria-describedby="category-error"><option value="">Select work type</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected(old('work_order_category_id', $order->work_order_category_id ?? null) == $category->id)>{{ $category->name }}</option>@endforeach</select>
                    @error('work_order_category_id')<p id="category-error" class="field-error">{{ $message }}</p>@enderror
                </label>
                <label>Requester urgency <span class="text-rose-600">*</span>
                    <select name="urgency" required><option value="NORMAL" @selected(old('urgency', $order->urgency ?? 'NORMAL') === 'NORMAL')>Normal</option><option value="URGENT" @selected(old('urgency', $order->urgency ?? 'NORMAL') === 'URGENT')>Urgent</option></select>
                </label>
            </div>
        </section>

        <section
            class="form-section"
            data-work-order-location
            data-buildings-url="{{ route('work-orders.location-buildings') }}"
            data-floors-url="{{ route('work-orders.location-floors') }}"
            data-areas-url="{{ route('work-orders.location-areas') }}"
        >
            <div class="flex flex-wrap items-start justify-between gap-3"><div><h2>2. Location</h2><p>Choose the most specific available location. Building is required; floor and area are optional.</p></div>@can('buildings.view')<a class="text-sm font-semibold text-blue-800 hover:underline" href="{{ route('locations.index') }}">Manage Locations</a>@endcan</div>
            <div class="grid gap-5 sm:grid-cols-2">
                <label>Campus <span class="text-rose-600">*</span>
                    <select class="@error('campus_id') field-invalid @enderror" name="campus_id" id="campus" required><option value="">Select campus</option>@foreach ($campuses as $campus)<option value="{{ $campus->id }}" @selected(old('campus_id', $order->campus_id ?? null) == $campus->id)>{{ $campus->name }}</option>@endforeach</select>
                    @error('campus_id')<p class="field-error">{{ $message }}</p>@enderror
                </label>
                <label>Building <span class="text-rose-600">*</span>
                    <select class="@error('building_id') field-invalid @enderror" name="building_id" id="building" required disabled><option value="">Select a campus first</option>@foreach ($buildings as $building)<option data-campus="{{ $building->campus_id }}" value="{{ $building->id }}" @selected(old('building_id', $order->building_id ?? null) == $building->id)>{{ $building->name }}</option>@endforeach</select>
                    <span class="mt-1 block text-xs font-normal text-slate-500">Select the building where the issue is located.</span>@error('building_id')<p class="field-error">{{ $message }}</p>@enderror
                </label>
                <label>Floor <span class="text-slate-500">(optional)</span>
                    <select class="@error('floor_id') field-invalid @enderror" name="floor_id" id="floor" disabled><option value="">Not specified</option>@foreach ($floors as $floor)<option data-building="{{ $floor->building_id }}" value="{{ $floor->id }}" @selected(old('floor_id', $order->floor_id ?? null) == $floor->id)>{{ $floor->name }}</option>@endforeach</select>
                    <span class="mt-1 block text-xs font-normal text-slate-500">Leave blank for building-wide concerns.</span>@error('floor_id')<p class="field-error">{{ $message }}</p>@enderror
                </label>
                <label>Office / Room / Area <span class="text-slate-500">(optional)</span>
                    <select class="@error('building_location_id') field-invalid @enderror" name="building_location_id" id="location" disabled><option value="">Not specified</option>@foreach ($locations as $location)<option data-building="{{ $location->building_id }}" data-floor="{{ $location->floor_id }}" value="{{ $location->id }}" @selected(old('building_location_id', $order->building_location_id ?? null) == $location->id)>{{ $location->name }}</option>@endforeach</select>
                    <span class="mt-1 block text-xs font-normal text-slate-500">Select the specific location if applicable.</span>@error('building_location_id')<p class="field-error">{{ $message }}</p>@enderror
                </label>
            </div>
            <div id="location-summary" class="mt-5 hidden rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-950"><p class="font-semibold">Selected Location</p><p class="mt-1" data-location-summary></p></div>
        </section>

        <section class="form-section">
            <h2>3. Request Details</h2><p>Describe the concern clearly so FMO can prepare and respond appropriately.</p>
            <div class="space-y-5">
                <label>Subject <span class="text-rose-600">*</span><input class="@error('subject') field-invalid @enderror" name="subject" value="{{ old('subject', $order->subject ?? '') }}" required maxlength="255" placeholder="Briefly describe the concern">@error('subject')<p class="field-error">{{ $message }}</p>@enderror</label>
                <label>Description <span class="text-rose-600">*</span><textarea class="min-h-36 @error('description') field-invalid @enderror" name="description" required maxlength="5000" placeholder="Describe the problem, damage, location details, or requested work.">{{ old('description', $order->description ?? '') }}</textarea>@error('description')<p class="field-error">{{ $message }}</p>@enderror</label>
            </div>
        </section>

        @php
            $initialPreferredPersonnel = collect(old('preferred_fmo_personnel_ids', isset($order) ? $order->preferredPersonnel->modelKeys() : []))->map(fn ($id) => (string) $id)->values();
        @endphp
        <section
            class="form-section"
            x-data="preferredPersonnelSelector({{ Illuminate\Support\Js::from([
                'categoryId' => old('work_order_category_id', $order->work_order_category_id ?? ''),
                'selected' => $initialPreferredPersonnel,
                'maxSelections' => config('work_orders.preferences.max_personnel'),
                'lookupUrl' => route('work-order-categories.eligible-preferred-personnel', '__CATEGORY__'),
            ]) }})"
            @keydown.escape.window="open = false"
        >
            <div class="flex flex-wrap items-start justify-between gap-3"><div><h2>4. Personnel Preference</h2><p>Optional. Available personnel are matched using every assigned skill related to the selected Work Type.</p></div><span class="status-badge status-open">Advisory only</span></div>
            <div class="relative mt-5" @click.outside="open = false">
                <span id="preferred-personnel-label" class="block text-sm font-medium text-slate-700">Preferred FMO Personnel <span class="text-slate-500">(optional)</span></span>
                <template x-for="id in selected" :key="id"><input type="hidden" name="preferred_fmo_personnel_ids[]" :value="id"></template>
                <div class="mt-1 flex min-h-12 w-full items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 shadow-xs transition focus-within:border-blue-600 focus-within:ring-3 focus-within:ring-blue-100 @error('preferred_fmo_personnel_ids') border-rose-500 @enderror">
                    <div class="flex min-w-0 flex-1 flex-wrap gap-2">
                        <template x-for="person in selectedPeople" :key="person.id">
                            <span class="inline-flex max-w-full items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-900">
                                <span class="truncate" x-text="person.name"></span>
                                <button class="grid size-6 shrink-0 place-items-center rounded-full hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-500" type="button" @click.stop="remove(person.id)" :aria-label="`Remove ${person.name}`">×</button>
                            </span>
                        </template>
                        <span class="self-center text-sm text-slate-500" x-show="!categoryId">Select a Work Type first</span>
                        <span class="self-center text-sm text-slate-500" x-show="categoryId && loading">Loading matching FMO personnel...</span>
                        <span class="self-center text-sm text-slate-500" x-show="!loading && lookupState === 'ready' && selectedPeople.length === 0" x-text="availabilitySummary"></span>
                        <span class="self-center text-sm text-amber-700" x-show="!loading && lookupState === 'unmapped'">No skill mapping configured</span>
                        <span class="self-center text-sm text-slate-500" x-show="!loading && lookupState === 'no_matches'">No matching FMO personnel available</span>
                        <span class="self-center text-sm text-rose-700" x-show="!loading && lookupState === 'error'">Unable to load personnel</span>
                    </div>
                    <button class="grid size-9 shrink-0 place-items-center rounded-lg text-slate-600 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50" type="button" @click="open = !open" :disabled="!categoryId || loading || lookupState !== 'ready'" :aria-expanded="open.toString()" aria-controls="preferred-personnel-options" aria-labelledby="preferred-personnel-label"><span aria-hidden="true">⌄</span></button>
                </div>

                <div id="preferred-personnel-options" x-cloak x-show="open" x-transition class="absolute z-30 mt-2 max-h-[min(32rem,70vh)] w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl" role="listbox" aria-multiselectable="true">
                    <div class="sticky top-0 z-10 border-b border-slate-100 bg-white p-3">
                        <label class="sr-only" for="preferred-personnel-search">Search personnel or skill</label>
                        <input id="preferred-personnel-search" class="!mt-0" type="search" x-model.debounce.150ms="search" placeholder="Search personnel or skill...">
                    </div>
                    <div class="space-y-2 p-3">
                        <template x-for="person in filteredOptions" :key="person.id">
                            <label class="flex min-h-14 cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3 transition hover:border-blue-400 focus-within:ring-2 focus-within:ring-blue-500" :class="{ 'border-blue-600 bg-blue-50': isSelected(person.id), 'cursor-not-allowed bg-slate-50 opacity-60': maxReached && !isSelected(person.id) }" role="option" :aria-selected="isSelected(person.id).toString()" :aria-disabled="(maxReached && !isSelected(person.id)).toString()">
                                <input class="mt-1 shrink-0" type="checkbox" :checked="isSelected(person.id)" @change="toggle(person.id)" @keydown.enter.prevent="toggle(person.id)" :disabled="maxReached && !isSelected(person.id)">
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-center gap-2"><span class="font-semibold text-slate-950" x-text="person.name"></span><span class="rounded-full bg-blue-100 px-2 py-0.5 text-[0.6875rem] font-semibold uppercase tracking-wide text-blue-800" x-show="isSelected(person.id)">Selected</span></span>
                                    <span class="mt-0.5 block text-xs text-slate-500" x-show="person.designation" x-text="person.designation"></span>
                                    <span class="mt-2 block text-sm text-emerald-800" x-show="primaryMatches(person)"><span class="font-semibold">Primary &amp; matched skill:</span> <span x-text="person.primary_skill"></span></span>
                                    <span class="mt-1 block text-sm text-emerald-800" x-show="primaryMatches(person) && otherMatchingSkills(person).length"><span class="font-semibold">Also matched:</span> <span x-text="otherMatchingSkills(person).join(', ')"></span></span>
                                    <span class="mt-2 block text-sm text-emerald-800" x-show="!primaryMatches(person)"><span class="font-semibold" x-text="person.matching_skills.length === 1 ? 'Matched skill:' : 'Matched skills:'"></span> <span x-text="person.matching_skills.join(', ')"></span></span>
                                    <span class="mt-1 block text-sm text-slate-700" x-show="person.primary_skill && !primaryMatches(person)"><span class="font-semibold">Primary:</span> <span x-text="person.primary_skill"></span></span>
                                    <span class="mt-1 block break-words text-xs text-slate-500" x-show="person.additional_skills.length"><span class="font-semibold text-slate-600">Also:</span> <span x-text="person.additional_skills.join(' • ')"></span><span x-show="person.additional_skill_count" x-text="` +${person.additional_skill_count} more`"></span></span>
                                </span>
                            </label>
                        </template>
                        <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-600" x-show="lookupState === 'ready' && search && filteredOptions.length === 0">No personnel match your search.</p>
                    </div>
                </div>
                <p id="preferred-personnel-help" class="help-text"><span x-show="!categoryId">Available personnel will be shown based on skills related to the selected Work Type. </span>Selections are suggestions only. Final assignment is made by FMO. You may select up to {{ config('work_orders.preferences.max_personnel') }} preferred personnel.</p>
                <p class="mt-2 text-sm font-medium text-blue-800" x-show="selectionSummary" x-text="selectionSummary" aria-live="polite"></p>
                <p class="mt-2 text-sm text-blue-800" x-show="loading" aria-live="polite">Loading matching FMO personnel...</p>
                <div class="mt-2 rounded-lg bg-amber-50 p-4 text-sm text-amber-900" x-show="!loading && lookupState === 'unmapped'" role="status"><p>No personnel skill mapping is configured for this Work Type.</p><p class="mt-1">You may continue without selecting a preference.</p></div>
                <div class="mt-2 rounded-lg bg-slate-50 p-4 text-sm text-slate-600" x-show="!loading && lookupState === 'no_matches'" role="status"><p>No matching FMO personnel are currently available.</p><p class="mt-1">You may continue without selecting a preference.</p></div>
                <p class="mt-2 text-sm text-amber-700" x-show="removedMessage" x-text="removedMessage" aria-live="polite"></p>
                <p class="mt-2 text-sm text-rose-700" x-show="errorMessage" x-text="errorMessage" aria-live="assertive"></p>
                @error('preferred_fmo_personnel_ids')<p id="preferred-personnel-error" class="field-error">{{ $message }}</p>@enderror
                @error('preferred_fmo_personnel_ids.*')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </section>

        <section class="form-section">
            <h2>5. Photos / Attachments</h2><p>Add photos or video that may help FMO understand the concern. Up to {{ config('work_orders.attachments.max_count') }} files and {{ config('work_orders.attachments.max_size_kb') / 1024 }} MB each.</p>
            @if (isset($order) && $order->attachments->where('purpose', 'REQUEST_INITIAL')->isNotEmpty())
                <div class="mb-5 rounded-xl border border-slate-200 bg-white p-4">
                    <x-evidence-gallery title="Existing Request Attachments" :attachments="$order->attachments->where('purpose', 'REQUEST_INITIAL')" group="existing-request-evidence" description="These files remain attached. Any new files you choose will be added to them." />
                </div>
            @endif
            <x-photo-picker name="attachments" id="request-attachments" title="Request Photos" help="Take a photo on a supported phone, or choose JPEG, PNG, WebP, or MP4 files from your device." :allow-video="true" />
            @error('attachments')<p class="field-error">{{ $message }}</p>@enderror
        </section>

        @if (isset($order) && $order->status === \App\Enums\WorkOrderStatus::NeedsInformation)
            <section class="form-section"><h2>6. Response to FMO</h2><p>Explain what you updated or provide the information requested by FMO.</p><label>Response to FMO <span class="text-rose-600">*</span><textarea class="@error('requester_message') field-invalid @enderror" name="requester_message" required aria-describedby="requester-message-help requester-message-error">{{ old('requester_message') }}</textarea><span id="requester-message-help" class="help-text">Summarize the corrected details and any new photos or attachments.</span>@error('requester_message')<p id="requester-message-error" class="field-error">{{ $message }}</p>@enderror</label></section>
        @endif

        <div class="z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white/95 p-4 shadow-lg backdrop-blur sm:sticky sm:bottom-3"><a class="btn btn-secondary" href="{{ route('work-orders.mine') }}">Cancel</a><div class="flex flex-wrap gap-3"><button class="btn btn-primary" type="submit" @if(isset($order) && $order->status === \App\Enums\WorkOrderStatus::NeedsInformation) data-loading-text="Resubmitting…" @endif><x-icon name="check-circle" />{{ isset($order) ? 'Save and resubmit' : 'Submit Work Order' }}</button>@if (! isset($order)) @can('work_orders.create_direct')<button class="btn btn-secondary" type="submit" formaction="{{ route('work-orders.direct') }}"><x-icon name="shield-check" />Create direct authorized Work Order</button>@endcan @endif</div></div>
    </form>

    @if (isset($order))
        <x-image-lightbox />
    @endif

@endsection
