<section id="vehicle-details" class="section-card mt-6 scroll-mt-24">
    <p class="detail-kicker">Vehicle</p>
    <h2 class="mt-1 text-lg">Vehicle Details</h2>
    <dl class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="detail-label">Make / Model</dt><dd class="detail-value">{{ collect([$asset->brand, $asset->model])->filter()->join(' ') ?: 'Not specified' }}</dd></div>
        <div><dt class="detail-label">Plate number</dt><dd class="detail-value">{{ $asset->specification('plate_number') ?: 'Not specified' }}</dd></div>
        <div><dt class="detail-label">Year · Capacity</dt><dd class="detail-value">{{ $asset->specification('year') ?: '—' }} · {{ $asset->specification('passenger_capacity') ? $asset->specification('passenger_capacity').' passengers' : '—' }}</dd></div>
        <div><dt class="detail-label">Current odometer</dt><dd class="detail-value">{{ $asset->current_odometer_km !== null ? number_format($asset->current_odometer_km).' km' : 'Not recorded' }}@if($asset->odometer_updated_at)<span class="block text-xs font-normal text-slate-500">Updated {{ $asset->odometer_updated_at->format('M j, Y') }}</span>@endif</dd></div>
    </dl>
    @php($odometerPlans = $asset->preventiveMaintenancePlans->where('trigger_type', 'ODOMETER')->where('is_active', true))
    @if ($odometerPlans->isNotEmpty())
        <div class="mt-5 border-t border-slate-100 pt-4"><h3 class="text-sm font-semibold text-slate-800">Mileage-based maintenance</h3>
            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                @foreach ($odometerPlans as $plan)
                    @php($odo = $plan->odometerStatus($asset->current_odometer_km))
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2"><span>{{ $plan->name }} <span class="text-slate-500">· every {{ number_format($plan->odometer_interval_km) }} km · next at {{ number_format($plan->next_due_odometer_km) }} km</span></span>
                        <span class="flex items-center gap-2"><x-status-badge :status="$odo['state']" /><span class="text-slate-600">@if($odo['remaining'] === null) Odometer not recorded @elseif($odo['remaining'] < 0) Overdue by {{ number_format(abs($odo['remaining'])) }} km @else {{ number_format($odo['remaining']) }} km remaining @endif</span></span></li>
                @endforeach
            </ul></div>
    @endif
</section>
