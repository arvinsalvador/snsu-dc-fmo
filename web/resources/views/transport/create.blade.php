@extends('layouts.app')
@section('title', 'New Transport Request')
@section('content')
<x-page-header title="New Transport Request" description="Request a campus vehicle and driver. The Campus Director or FMO Head reviews every request." />

<div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
<form method="POST" enctype="multipart/form-data" action="{{ route('transport.store') }}" class="space-y-6">@csrf
    <section class="form-section" aria-labelledby="step-trip">
        <div class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-full bg-blue-700 text-sm font-bold text-white">1</span><h2 id="step-trip" class="!mb-0">Where are you going?</h2></div>
        <p class="!mt-2">Describe the purpose and the route. Places are free text, so type them as you would say them.</p>
        <div class="grid gap-5 sm:grid-cols-2">
            <label class="sm:col-span-2">Purpose of travel <span class="text-rose-600">*</span><input name="purpose" required maxlength="500" value="{{ old('purpose') }}" placeholder="Official meeting, training, delivery, site visit..."></label>
            <label>From <span class="text-rose-600">*</span><input name="origin" required maxlength="255" value="{{ old('origin', 'SNSU Del Carmen Campus') }}"></label>
            <label>To <span class="text-rose-600">*</span><input name="destination" required maxlength="255" value="{{ old('destination') }}" placeholder="Dapa, General Luna..."></label>
        </div>
    </section>

    <section class="form-section" aria-labelledby="step-when">
        <div class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-full bg-blue-700 text-sm font-bold text-white">2</span><h2 id="step-when" class="!mb-0">When and who?</h2></div>
        <p class="!mt-2">Expected times help the dispatcher avoid vehicle and driver conflicts.</p>
        <div class="grid gap-5 sm:grid-cols-2">
            <label>Travel date <span class="text-rose-600">*</span><input type="date" name="travel_date" required min="{{ now(config('app.timezone'))->toDateString() }}" value="{{ old('travel_date') }}"></label>
            <label>Number of passengers <span class="text-rose-600">*</span><input type="number" name="passenger_count" required min="1" max="100" inputmode="numeric" value="{{ old('passenger_count', 1) }}"></label>
            <label>Expected departure <span class="font-normal text-slate-400">— Optional</span><input type="datetime-local" name="expected_departure_at" value="{{ old('expected_departure_at') }}"></label>
            <label>Expected return <span class="font-normal text-slate-400">— Optional</span><input type="datetime-local" name="expected_return_at" value="{{ old('expected_return_at') }}"></label>
            <label class="sm:col-span-2">Passenger names / details <span class="font-normal text-slate-400">— Optional</span><textarea name="passenger_details" rows="3" maxlength="3000" placeholder="List the passengers or note special needs">{{ old('passenger_details') }}</textarea></label>
            <label class="sm:col-span-2">Remarks <span class="font-normal text-slate-400">— Optional</span><textarea name="remarks" rows="2" maxlength="3000">{{ old('remarks') }}</textarea></label>
        </div>
    </section>

    <section class="form-section" aria-labelledby="step-doc" x-data="{ names: [] }">
        <div class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-full bg-blue-700 text-sm font-bold text-white">3</span><h2 id="step-doc" class="!mb-0">Supporting Memo / Document (Optional)</h2></div>
        <p class="!mt-2">If a memo, travel order, authority to travel, or related document is available, please upload a copy for reference.</p>
        <label class="flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 px-6 py-8 text-center transition focus-within:border-blue-500 focus-within:ring-3 focus-within:ring-blue-100 hover:border-blue-400 hover:bg-blue-50/50">
            <span class="grid size-11 place-items-center rounded-full bg-white text-blue-700 shadow-xs ring-1 ring-slate-200"><x-icon name="upload" /></span>
            <span class="font-semibold text-slate-900">Choose files to upload</span>
            <span class="text-xs font-normal text-slate-500">PDF, JPG, PNG, or WebP. Stored privately and shown only to people involved in this request.</span>
            <input class="sr-only" type="file" name="documents[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf" @change="names = [...$event.target.files].map(file => file.name)">
        </label>
        <ul class="mt-3 space-y-1 text-sm" x-show="names.length" x-cloak aria-live="polite">
            <template x-for="name in names" :key="name"><li class="flex items-center gap-2 text-slate-700"><x-icon name="paperclip" size="size-4" class="text-slate-400" /><span x-text="name"></span></li></template>
        </ul>
    </section>

    <div class="flex flex-wrap items-center justify-end gap-3">
        <a class="btn btn-secondary" href="{{ route('transport.mine') }}">Cancel</a>
        <button class="btn btn-primary" type="submit"><x-icon name="check-circle" />Submit request</button>
    </div>
</form>

<aside class="h-fit space-y-4 lg:sticky lg:top-24">
    <section class="section-card">
        <h2 class="text-base">What happens next</h2>
        <ol class="mt-4">
            @foreach ([['Submit', 'Your request is sent to the Campus Director and FMO Head.'], ['Approval', 'It is approved or rejected. You are notified either way.'], ['Assignment', 'FMO assigns a driver and an available vehicle.'], ['Trip', 'The driver records the trip, and it is added to the vehicle history.']] as [$label, $text])
                <li class="timeline-item"><p class="text-sm font-semibold text-slate-900">{{ $label }}</p><p class="text-xs leading-5 text-slate-500">{{ $text }}</p></li>
            @endforeach
        </ol>
    </section>
    <section class="rounded-xl border border-blue-100 bg-blue-50/60 p-4 text-sm text-blue-950">
        <p class="flex items-center gap-2 font-semibold"><x-icon name="info" size="size-4" />Tip</p>
        <p class="mt-1 text-xs leading-5">Submit at least a day ahead when you can. Add the expected departure and return so vehicles are not double-booked.</p>
    </section>
</aside>
</div>
@endsection
