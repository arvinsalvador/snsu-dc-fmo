@extends('layouts.app')
@section('title', 'Maintenance Plans')
@section('content')
<x-page-header title="Maintenance Plans" description="Calendar and odometer-based preventive maintenance tied to individually maintainable assets."><x-slot:action><a class="btn btn-primary" href="{{ route('preventive-maintenance.plans.create') }}">Create Plan</a></x-slot:action></x-page-header>
<form class="filter-bar mt-6" method="GET">
    <label class="min-w-52 flex-1">Search<input name="q" value="{{ request('q') }}" placeholder="Asset, code, or plan"></label>
    <label>Asset class<select name="asset_class"><option value="">All</option>@foreach (\App\Enums\AssetClass::cases() as $class)<option value="{{ $class->value }}" @selected(request('asset_class') === $class->value)>{{ str($class->value)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
    <label>Asset type<select name="asset_type"><option value="">All</option>@foreach ($assetTypes as $type)<option value="{{ $type->id }}" @selected(request('asset_type') === $type->id)>{{ $type->name }}</option>@endforeach</select></label>
    <label>Trigger<select name="trigger"><option value="">All</option><option value="CALENDAR" @selected(request('trigger') === 'CALENDAR')>Calendar</option><option value="ODOMETER" @selected(request('trigger') === 'ODOMETER')>Odometer</option></select></label>
    <label>Status<select name="status"><option value="">All</option><option value="ACTIVE" @selected(request('status') === 'ACTIVE')>Active</option><option value="INACTIVE" @selected(request('status') === 'INACTIVE')>Inactive</option></select></label>
    <label>Per page<select name="per_page">@foreach ([20, 50, 100] as $size)<option value="{{ $size }}" @selected((int) request('per_page', 20) === $size)>{{ $size }}</option>@endforeach</select></label>
    <button class="btn btn-secondary" type="submit">Filter</button>
</form>
<div class="table-wrap mt-6"><table><thead><tr><th>Plan</th><th>Asset</th><th>Trigger</th><th>Next due</th><th>Assigned team</th><th>Status</th><th class="text-right">Action</th></tr></thead><tbody>
@forelse ($plans as $plan)
    @php($odo = $plan->odometerStatus())
    <tr>
        <td class="font-semibold text-slate-900">{{ $plan->name }}<div class="text-xs font-normal text-slate-500">{{ str($plan->maintenance_type)->replace('_', ' ')->title() }} · {{ $plan->occurrences_count }} {{ \Illuminate\Support\Str::plural('task', $plan->occurrences_count) }}</div></td>
        <td><a class="font-semibold text-blue-800" href="{{ route('assets.show', $plan->asset) }}">{{ $plan->asset->asset_code }}</a><div class="text-xs text-slate-500">{{ $plan->asset->name }}</div></td>
        <td>@if ($plan->isOdometerBased())Every {{ number_format($plan->odometer_interval_km) }} km @else {{ str($plan->frequency_type->value)->title() }} @endif</td>
        <td>
            @if ($odo)
                {{ number_format($plan->next_due_odometer_km) }} km
                <div class="text-xs {{ in_array($odo['state'], ['OVERDUE', 'DUE'], true) ? 'font-semibold text-rose-700' : 'text-slate-500' }}">@if($odo['remaining'] === null) Odometer not recorded @elseif($odo['remaining'] < 0) Overdue by {{ number_format(abs($odo['remaining'])) }} km @else {{ number_format($odo['remaining']) }} km remaining @endif</div>
            @else
                {{ $plan->next_due_at ? \Illuminate\Support\Carbon::parse($plan->next_due_at)->format('M j, Y') : '—' }}
            @endif
        </td>
        <td>{{ $plan->activeAssignees->pluck('user.name')->join(', ') ?: 'Unassigned' }}</td>
        <td><x-status-badge :status="$odo ? $odo['state'] : ($plan->is_active ? 'ACTIVE' : 'INACTIVE')" />@if ($odo && ! $plan->is_active)<x-status-badge status="INACTIVE" />@endif</td>
        <td class="text-right"><a class="btn btn-secondary" href="{{ route('preventive-maintenance.plans.edit', $plan) }}">Edit</a></td>
    </tr>
@empty
    <tr><td colspan="7">No maintenance plans found.</td></tr>
@endforelse
</tbody></table></div>
<div class="mt-6">{{ $plans->links() }}</div>
@endsection
