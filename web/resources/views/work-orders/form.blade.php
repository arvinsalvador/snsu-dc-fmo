@extends('layouts.app')

@section('title', isset($order) ? 'Update Work Order' : 'New Work Order Request')

@section('content')
    <div class="mb-4 text-sm text-slate-500"><a class="text-blue-800 hover:underline" href="{{ route('work-orders.mine') }}">My Work Orders</a> <span class="px-1">/</span> {{ isset($order) ? 'Update request' : 'New request' }}</div>
    <x-page-header :title="isset($order) ? 'Update '.$order->work_order_number : 'New Work Order Request'" description="Report a facilities repair, maintenance or service concern." />

    @if (isset($order) && $order->status === \App\Enums\WorkOrderStatus::NeedsInformation)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p class="font-semibold">FMO requested additional information</p><p class="mt-1">Update the request and include your response before resubmitting.</p></div>
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

        <section class="form-section">
            <h2>4. Personnel Preference</h2><p>Optional. You may indicate a preferred FMO personnel member. This does not guarantee assignment.</p>
            <label>Preferred FMO Personnel<select name="preferred_fmo_personnel_id"><option value="">No preference</option>@foreach ($personnel as $person)<option value="{{ $person->id }}" @selected(old('preferred_fmo_personnel_id', $order->preferred_fmo_personnel_id ?? null) == $person->id)>{{ $person->user->name }} — {{ $person->designation }}</option>@endforeach</select></label>
        </section>

        <section class="form-section">
            <h2>5. Photos / Attachments</h2><p>Add photos or files that may help FMO understand the concern. JPEG, PNG, WebP, or MP4; up to {{ config('work_orders.attachments.max_count') }} files and {{ config('work_orders.attachments.max_size_kb') / 1024 }} MB each.</p>
            <label class="block rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-6 text-center hover:border-blue-400 hover:bg-blue-50"><span class="font-semibold text-slate-800">Choose photos or video</span><input class="mt-3" type="file" name="attachments[]" multiple accept="image/jpeg,image/png,image/webp,video/mp4"><span id="attachment-summary" class="mt-2 block text-xs font-normal text-slate-500">No files selected</span></label>
            @error('attachments')<p class="field-error">{{ $message }}</p>@enderror
        </section>

        @if (isset($order) && $order->status === \App\Enums\WorkOrderStatus::NeedsInformation)
            <section class="form-section"><h2>6. Response to FMO</h2><p>Provide the requested clarification before resubmitting.</p><label>Additional information <span class="text-rose-600">*</span><textarea name="requester_message" required>{{ old('requester_message') }}</textarea></label></section>
        @endif

        <div class="sticky bottom-3 z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white/95 p-4 shadow-lg backdrop-blur"><a class="btn btn-secondary" href="{{ route('work-orders.mine') }}">Cancel</a><div class="flex flex-wrap gap-3"><button class="btn btn-primary" type="submit">{{ isset($order) ? 'Save and resubmit' : 'Submit Work Order' }}</button>@if (! isset($order)) @can('work_orders.create_direct')<button class="btn btn-success" type="submit" formaction="{{ route('work-orders.direct') }}">Create direct authorized Work Order</button>@endcan @endif</div></div>
    </form>

@endsection
