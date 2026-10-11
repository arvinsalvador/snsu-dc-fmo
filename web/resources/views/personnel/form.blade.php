@extends('layouts.app')
@section('title', $personnel ? 'Edit FMO Staff' : 'Add FMO Staff')
@section('content')
    <x-breadcrumbs :items="['FMO Staff' => route('personnel.index'), $personnel ? 'Edit profile' : 'Add profile']" />
    <x-page-header :title="$personnel ? 'Edit FMO Staff' : 'Add FMO Staff'" description="Maintain personnel, employment, skill and availability information." />

    <form method="POST" action="{{ $personnel ? route('personnel.update', $personnel) : route('personnel.store') }}" class="mt-6 max-w-4xl space-y-6">
        @csrf
        @if ($personnel) @method('PUT') @endif
        <section class="form-section">
            <h2>Personal &amp; Employment Information</h2>
            <p>Link an approved account and record the staff member's operational details.</p>
            <div class="grid gap-5 md:grid-cols-2">
                @if (! $personnel)
                    <label class="md:col-span-2">Approved user <span class="text-rose-600">*</span><select name="user_id" required><option value="">Select approved user</option>@foreach ($users as $user)<option value="{{ $user->id }}" @selected(old('user_id') == $user->id)>{{ $user->name }} — {{ $user->email }}</option>@endforeach</select></label>
                @endif
                <label>Personnel / Employee ID <span class="text-slate-500">(optional)</span><input name="personnel_identifier" value="{{ old('personnel_identifier', $personnel?->personnel_identifier) }}"></label>
                <label>Designation <span class="text-slate-500">(optional)</span><input name="designation" value="{{ old('designation', $personnel?->designation) }}"></label>
                <label>Employment type <span class="text-slate-500">(optional)</span><select name="employment_type"><option value="">Not specified</option>@foreach ($employmentTypes as $type)<option value="{{ $type->value }}" @selected(old('employment_type', $personnel?->employment_type?->value) === $type->value)>{{ str($type->value)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
                <label>Start date <span class="text-slate-500">(optional)</span><input type="date" name="start_date" value="{{ old('start_date', $personnel?->start_date?->format('Y-m-d')) }}"></label>
                <label>Contact number <span class="text-slate-500">(optional)</span><input name="contact_number" value="{{ old('contact_number', $personnel?->contact_number) }}"></label>
                <label>Personnel status <span class="text-rose-600">*</span><select name="personnel_status" required>@foreach ($personnelStatuses as $status)<option value="{{ $status->value }}" @selected(old('personnel_status', $personnel?->personnel_status?->value ?? 'ACTIVE') === $status->value)>{{ str($status->value)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
            </div>
        </section>
        <section class="form-section">
            <h2>Skills &amp; Availability</h2>
            <p>Select every applicable skill and identify one primary specialization.</p>
            <fieldset><legend class="text-sm font-semibold text-slate-800">Assigned skills</legend><div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">@foreach ($skills as $skill)<label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"><input type="checkbox" name="skill_ids[]" value="{{ $skill->id }}" @checked(in_array($skill->id, old('skill_ids', $personnel?->skills->pluck('id')->all() ?? [])))><span>{{ $skill->name }}</span></label>@endforeach</div></fieldset>
            <label class="mt-5 block">Primary skill <span class="text-slate-500">(optional)</span><select name="primary_skill_id"><option value="">No primary skill</option>@foreach ($skills as $skill)<option value="{{ $skill->id }}" @selected(old('primary_skill_id', $personnel?->skills->firstWhere('pivot.is_primary', true)?->id) == $skill->id)>{{ $skill->name }}</option>@endforeach</select><span class="help-text">The primary skill must also be selected above.</span></label>
        </section>
        <section class="form-section"><h2>Administrative Notes</h2><p>Visible only to authorized staff.</p><label>Notes <span class="text-slate-500">(optional)</span><textarea name="notes">{{ old('notes', $personnel?->notes) }}</textarea></label></section>
        <div class="flex flex-wrap gap-3"><button class="btn btn-primary" type="submit"><x-icon name="check-circle" />Save personnel</button><a class="btn btn-secondary" href="{{ route('personnel.index') }}">Cancel</a></div>
    </form>
@endsection
