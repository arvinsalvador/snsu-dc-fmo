@extends('layouts.app')
@section('title', $transport->request_number)
@section('content')
@php
    use App\Enums\TransportStatus;
    $status = $transport->status;
    $dt = fn ($value) => $value ? $value->timezone(config('app.timezone'))->format('M j, Y · g:i A') : '—';
    $local = fn ($value) => $value ? $value->timezone(config('app.timezone'))->format('Y-m-d\TH:i') : '';
    $requestDocs = $transport->attachments->where('purpose', \App\Models\TransportRequest::DOCUMENT_PURPOSE);
    $approvalDocs = $transport->attachments->where('purpose', \App\Models\TransportRequest::APPROVAL_EVIDENCE_PURPOSE);
    $tripDocs = $transport->attachments->whereIn('purpose', [\App\Models\TransportRequest::TRIP_DOCUMENT_PURPOSE, \App\Models\TransportRequest::ISSUE_PHOTO_PURPOSE]);
    $driverName = $transport->driver?->user?->name;
    $next = match (true) {
        $canApprove => ['Your decision is needed', 'Review the request below, then approve or reject it.', 'info'],
        $status === TransportStatus::PendingApproval => ['Waiting for approval', 'The Campus Director or FMO Head will review this request.', 'wait'],
        $canDispatch && $status === TransportStatus::Approved => ['Assign a driver and vehicle', 'The request is approved. Choose an available driver and vehicle below.', 'info'],
        $status === TransportStatus::Approved => ['Approved — arranging transport', 'FMO is assigning a driver and an available vehicle.', 'wait'],
        $canUpdateTrip => ['Update the trip record', 'Save details any time, even in parts. Choose Complete Trip once the final details are in.', 'info'],
        $status === TransportStatus::Assigned => ['Trip scheduled', ($driverName ?? 'A driver').' will drive the '.($transport->vehicle?->name ?? 'assigned vehicle').' on '.$transport->travel_date->format('F j, Y').'.', 'wait'],
        $status === TransportStatus::Completed => ['Trip completed', $transport->distance_km !== null ? number_format($transport->distance_km).' km travelled. This record is now part of the vehicle travel history.' : 'This record is part of the vehicle travel history.', 'done'],
        $status === TransportStatus::Rejected => ['Request rejected', $transport->rejection_reason, 'stop'],
        default => ['Request cancelled', 'No further action is needed.', 'stop'],
    };
    $panelClass = match ($next[2]) {
        'stop' => 'border-rose-200 bg-rose-50',
        'done' => 'border-emerald-200 bg-emerald-50',
        'wait' => 'border-amber-200 bg-amber-50',
        default => 'border-blue-200 bg-blue-50',
    };
    $panelIcon = match ($next[2]) {
        'stop' => 'warning', 'done' => 'check-circle', 'wait' => 'clock', default => 'info',
    };
@endphp

<nav class="mb-3 text-sm text-slate-500" aria-label="Breadcrumb">
    <a class="hover:text-blue-800 hover:underline" href="{{ route($access->canView($transport, auth()->user()) && auth()->user()->can('transport.view_all') ? 'transport.index' : 'transport.mine') }}">Transportation</a>
    <span aria-hidden="true">/</span> <span class="font-medium text-slate-700">{{ $transport->request_number }}</span>
</nav>
<x-page-header :title="$transport->request_number" :description="$transport->purpose">
    <x-slot:action><x-status-badge :status="$transport->status" /></x-slot:action>
</x-page-header>

<section class="app-card mt-6 p-5 sm:p-6" aria-label="Progress"><x-transport-stepper :transport="$transport" /></section>

<div class="mt-4 flex items-start gap-3 rounded-xl border p-4 {{ $panelClass }}" role="status">
    <x-icon :name="$panelIcon" class="mt-0.5 text-slate-700" />
    <div><p class="font-semibold text-slate-900">{{ $next[0] }}</p><p class="mt-0.5 whitespace-pre-line text-sm text-slate-700">{{ $next[1] }}</p></div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
<div class="space-y-6">
    <section class="section-card" aria-labelledby="overview-heading">
        <h2 id="overview-heading" class="text-lg">Trip overview</h2>
        <div class="mt-5 grid gap-6 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <x-transport-route :from="$transport->origin" :to="$transport->destination" />
            <dl class="grid grid-cols-2 gap-4">
                <div><dt class="detail-label">Travel date</dt><dd class="detail-value">{{ $transport->travel_date->format('M j, Y') }}</dd></div>
                <div><dt class="detail-label">Passengers</dt><dd class="detail-value">{{ $transport->passenger_count }}</dd></div>
                <div><dt class="detail-label">Expected departure</dt><dd class="detail-value">{{ $dt($transport->expected_departure_at) }}</dd></div>
                <div><dt class="detail-label">Expected return</dt><dd class="detail-value">{{ $dt($transport->expected_return_at) }}</dd></div>
            </dl>
        </div>
        @if ($transport->passenger_details || $transport->remarks)
            <dl class="mt-5 grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-2">
                @if ($transport->passenger_details)<div><dt class="detail-label">Passengers / details</dt><dd class="detail-value whitespace-pre-line font-normal">{{ $transport->passenger_details }}</dd></div>@endif
                @if ($transport->remarks)<div><dt class="detail-label">Remarks</dt><dd class="detail-value whitespace-pre-line font-normal">{{ $transport->remarks }}</dd></div>@endif
            </dl>
        @endif
    </section>

    @if ($transport->approved_at)
        <section class="section-card" aria-labelledby="approval-heading">
            <h2 id="approval-heading" class="text-lg">Approval record</h2>
            <div class="mt-4 flex items-start gap-3">
                <x-avatar :name="$transport->approver->name" />
                <div class="min-w-0">
                    <p class="font-semibold text-slate-900">{{ $transport->approver->name }}</p>
                    <p class="text-xs text-slate-500">{{ $transport->approver->getRoleNames()->join(', ') }} · {{ $dt($transport->approved_at) }}</p>
                </div>
            </div>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><dt class="detail-label">Approval authority</dt><dd class="detail-value">{{ $transport->approval_authority?->label() }}</dd></div>
                @if ($transport->approval_instructed_by)<div><dt class="detail-label">Instruction from</dt><dd class="detail-value">{{ $transport->approval_instructed_by }}</dd></div>@endif
                @if ($transport->approval_note)<div class="sm:col-span-2"><dt class="detail-label">Approval note</dt><dd class="detail-value whitespace-pre-line font-normal">{{ $transport->approval_note }}</dd></div>@endif
                @if ($canSeeApprovalEvidence)<div class="sm:col-span-2"><dt class="detail-label">Supporting evidence</dt><dd class="detail-value">{{ $approvalDocs->count() }} {{ \Illuminate\Support\Str::plural('attachment', $approvalDocs->count()) }}</dd></div>@endif
            </dl>
        </section>
    @endif

    @if ($status === TransportStatus::Rejected)
        <section class="section-card border-rose-200"><h2 class="text-lg">Rejected</h2><p class="mt-3 text-sm text-slate-600">By {{ $transport->rejecter?->name }} on {{ $dt($transport->rejected_at) }}.</p><p class="mt-2 whitespace-pre-line text-slate-800">{{ $transport->rejection_reason }}</p></section>
    @endif

    @if ($canApprove)
        <section class="section-card" aria-labelledby="approve-heading">
            <h2 id="approve-heading" class="text-lg">Decision</h2>
            <form method="POST" enctype="multipart/form-data" action="{{ route('transport.approve', $transport) }}" class="mt-4 space-y-4">@csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <label>Approval authority<select name="approval_authority">@foreach ($authorities as $authority)<option value="{{ $authority->value }}" @selected(old('approval_authority') === $authority->value)>{{ $authority->label() }}</option>@endforeach</select></label>
                    <label>Instruction from <span class="font-normal text-slate-400">— Optional</span><input name="approval_instructed_by" maxlength="150" value="{{ old('approval_instructed_by') }}" placeholder="Name of the person who instructed you"></label>
                </div>
                <label>Approval note <span class="font-normal text-slate-400">— Required when approving as instructed</span><textarea name="approval_note" rows="3" maxlength="2000" placeholder="Approved as instructed by the Campus Director via Messenger.">{{ old('approval_note') }}</textarea></label>
                <div>
                    <label>Supporting evidence <span class="font-normal text-slate-400">— Optional</span><input type="file" name="approval_evidence[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf"></label>
                    <p class="help-text">Supporting proof is optional. If a screenshot or written instruction is available, upload it for the approval record.</p>
                </div>
                <button class="btn btn-success" type="submit"><x-icon name="check-circle" />Approve request</button>
            </form>
            <form method="POST" action="{{ route('transport.reject', $transport) }}" class="mt-6 space-y-3 rounded-xl border border-rose-100 bg-rose-50/50 p-4">@csrf
                <label>Reason for rejection <span class="text-rose-600">*</span><textarea name="rejection_reason" rows="2" required maxlength="2000" placeholder="Vehicle unavailable on requested date."></textarea></label>
                <button class="btn btn-danger" type="submit">Reject request</button>
            </form>
        </section>
    @endif

    @if ($canDispatch)
        <section class="section-card" aria-labelledby="assign-heading">
            <h2 id="assign-heading" class="text-lg">{{ $status === TransportStatus::Assigned ? 'Change driver or vehicle' : 'Assign driver and vehicle' }}</h2>
            <form method="POST" action="{{ route('transport.assign', $transport) }}" class="mt-4 grid gap-4 sm:grid-cols-2">@csrf
                <label>Driver <span class="text-rose-600">*</span><select name="driver_personnel_id" required><option value="">Select driver</option>@foreach ($drivers as $driver)<option value="{{ $driver->id }}" @selected(old('driver_personnel_id', $transport->driver_personnel_id) === $driver->id)>{{ $driver->user->name }}</option>@endforeach</select></label>
                <label>Vehicle <span class="text-rose-600">*</span><select name="inspectable_asset_id" required><option value="">Select vehicle</option>@foreach ($vehicles as $vehicle)<option value="{{ $vehicle->id }}" @selected(old('inspectable_asset_id', $transport->inspectable_asset_id) === $vehicle->id)>{{ $vehicle->asset_code }} — {{ $vehicle->name }}@if ($vehicle->specification('passenger_capacity')) ({{ $vehicle->specification('passenger_capacity') }} seats)@endif</option>@endforeach</select></label>
                <div class="sm:col-span-2"><button class="btn btn-primary" type="submit"><x-icon name="assignment" />Assign</button></div>
            </form>
            @if ($vehicles->isEmpty())<p class="mt-3 text-sm text-amber-700">No eligible vehicles. Register a Vehicle asset that is active and not under repair.</p>@endif
        </section>
    @endif

    @if ($transport->driver || $status === TransportStatus::Completed)
        <section class="section-card" aria-labelledby="trip-heading">
            <h2 id="trip-heading" class="text-lg">Trip record</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="person-row"><x-avatar :name="$driverName ?? '—'" size="sm" /><div><p class="detail-label">Driver</p><p class="text-sm font-semibold text-slate-900">{{ $driverName ?? '—' }}</p></div></div>
                <div class="person-row"><span class="grid size-9 shrink-0 place-items-center rounded-full bg-blue-100 text-blue-800"><x-icon name="truck" size="size-4" /></span><div class="min-w-0"><p class="detail-label">Vehicle</p>
                    @if ($transport->vehicle)<a class="text-sm font-semibold text-blue-800 hover:underline" href="{{ route('assets.show', $transport->vehicle) }}">{{ $transport->vehicle->name }}</a><p class="text-xs text-slate-500">{{ $transport->vehicle->asset_code }}@if($transport->vehicle->specification('plate_number')) · {{ $transport->vehicle->specification('plate_number') }}@endif</p>@else<p class="text-sm">—</p>@endif</div></div>
            </div>

            @if ($canUpdateTrip)
                <form method="POST" enctype="multipart/form-data" action="{{ route('transport.trip.update', $transport) }}" class="mt-6 space-y-6"
                      x-data="{ before: '{{ old('odometer_before', $transport->odometer_before ?? $transport->vehicle?->current_odometer_km) }}', after: '{{ old('odometer_after', $transport->odometer_after) }}', get distance() { return this.before !== '' && this.after !== '' ? Number(this.after) - Number(this.before) : null; } }">@csrf
                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-900">Departure and arrival</legend>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <label>Actual departure<input type="datetime-local" name="actual_departed_at" value="{{ old('actual_departed_at', $local($transport->actual_departed_at)) }}"></label>
                            <label>Actual arrival<input type="datetime-local" name="actual_arrived_at" value="{{ old('actual_arrived_at', $local($transport->actual_arrived_at)) }}"></label>
                        </div>
                    </fieldset>
                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-900">Odometer and fuel</legend>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <label>Odometer before travel (km)<input type="number" min="0" name="odometer_before" inputmode="numeric" x-model="before"></label>
                            <label>Odometer after travel (km)<input type="number" min="0" name="odometer_after" inputmode="numeric" x-model="after"></label>
                            <label>Fuel before<select name="fuel_before"><option value="">Select</option>@foreach ($fuelLevels as $level)<option value="{{ $level->value }}" @selected(old('fuel_before', $transport->fuel_before?->value) === $level->value)>{{ $level->label() }}</option>@endforeach</select></label>
                            <label>Fuel after<select name="fuel_after"><option value="">Select</option>@foreach ($fuelLevels as $level)<option value="{{ $level->value }}" @selected(old('fuel_after', $transport->fuel_after?->value) === $level->value)>{{ $level->label() }}</option>@endforeach</select></label>
                        </div>
                        <p class="mt-3 flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700" aria-live="polite"><x-icon name="gauge" size="size-4" class="text-blue-700" />
                            <span>Distance travelled: <strong class="text-slate-900" x-text="distance === null ? 'calculated automatically' : (distance < 0 ? 'check the odometer readings' : distance.toLocaleString() + ' km')"></strong></span></p>
                    </fieldset>
                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-900">Notes and documents</legend>
                        <div class="mt-3 space-y-4">
                            <label>Trip remarks <span class="font-normal text-slate-400">— Optional</span><textarea name="trip_remarks" rows="2" maxlength="2000" placeholder="Travel completed without incident.">{{ old('trip_remarks', $transport->trip_remarks) }}</textarea></label>
                            <div class="rounded-xl border border-slate-200 p-4" x-data="{ issue: {{ old('vehicle_issue_observed', $transport->vehicle_issue_observed) ? 'true' : 'false' }} }">
                                <label class="flex items-center gap-2"><input type="hidden" name="vehicle_issue_observed" value="0"><input type="checkbox" name="vehicle_issue_observed" value="1" x-model="issue" @checked(old('vehicle_issue_observed', $transport->vehicle_issue_observed))> Vehicle issue observed</label>
                                <div class="mt-3 space-y-3" x-show="issue" x-cloak>
                                    <label>What was observed?<textarea name="vehicle_issue_remarks" rows="2" maxlength="2000" placeholder="Abnormal brake noise observed.">{{ old('vehicle_issue_remarks', $transport->vehicle_issue_remarks) }}</textarea></label>
                                    <label>Photo <span class="font-normal text-slate-400">— Optional</span><input type="file" name="issue_photos[]" multiple accept="image/jpeg,image/png,image/webp"></label>
                                </div>
                            </div>
                            <div>
                                <label>Trip Ticket / Supporting Document (Optional)<input type="file" name="trip_documents[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf"></label>
                                <p class="help-text">If the accomplished trip ticket, memo, travel order, or related document is available, upload a copy for the trip record.</p>
                            </div>
                        </div>
                    </fieldset>
                    <div class="flex flex-wrap justify-end gap-3 border-t border-slate-100 pt-5">
                        <button class="btn btn-secondary" type="submit"><x-icon name="pencil" />Save Update</button>
                        @can('transport.complete_assigned_trip')<button class="btn btn-primary" type="submit" formaction="{{ route('transport.trip.complete', $transport) }}"><x-icon name="check-circle" />Complete Trip</button>@endcan
                    </div>
                </form>
            @else
                @php
                    $minutes = $transport->actual_departed_at && $transport->actual_arrived_at ? max(0, (int) $transport->actual_departed_at->diffInMinutes($transport->actual_arrived_at)) : null;
                    $duration = $minutes === null ? null : ($minutes >= 60 ? intdiv($minutes, 60).' h '.($minutes % 60).' min' : $minutes.' min');
                    $speed = $minutes && $transport->distance_km !== null ? round($transport->distance_km / ($minutes / 60)) : null;
                    $fuelChange = $transport->fuel_before && $transport->fuel_after ? $transport->fuel_before->percent() - $transport->fuel_after->percent() : null;
                @endphp
                <div class="mt-5 space-y-4">
                    <div class="grid gap-4 rounded-2xl border border-blue-100 bg-gradient-to-br from-blue-50 to-white p-5 sm:grid-cols-[auto_1fr] sm:items-center sm:gap-8">
                        <div class="flex items-center gap-4">
                            <span class="grid size-14 shrink-0 place-items-center rounded-2xl bg-blue-700 text-white shadow-xs"><x-icon name="gauge" size="size-7" /></span>
                            <div><p class="detail-label">Distance travelled</p><p class="text-4xl font-semibold tracking-tight text-slate-900">{{ $transport->distance_km !== null ? number_format($transport->distance_km) : '—' }}<span class="ml-1 text-base font-medium text-slate-500">km</span></p></div>
                        </div>
                        <div>
                            <div class="flex items-end justify-between gap-3 text-sm">
                                <div><p class="detail-label">Odometer start</p><p class="font-semibold text-slate-900">{{ $transport->odometer_before !== null ? number_format($transport->odometer_before) : '—' }} km</p></div>
                                <div class="text-right"><p class="detail-label">Odometer end</p><p class="font-semibold text-slate-900">{{ $transport->odometer_after !== null ? number_format($transport->odometer_after) : '—' }} km</p></div>
                            </div>
                            <div class="relative mt-3 h-6" aria-hidden="true">
                                <span class="absolute inset-x-1.5 top-1/2 h-1 -translate-y-1/2 rounded-full bg-blue-200"></span>
                                <span class="absolute left-0 top-1/2 size-3 -translate-y-1/2 rounded-full border-2 border-blue-600 bg-white"></span>
                                <span class="absolute right-0 top-1/2 size-3 -translate-y-1/2 rounded-full bg-blue-700"></span>
                                <span class="absolute left-1/2 top-1/2 grid size-6 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full bg-white text-blue-700 shadow-xs ring-1 ring-blue-200"><x-icon name="truck" size="size-3.5" /></span>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-xl border border-slate-200 p-4">
                            <p class="flex items-center gap-2 text-sm font-semibold text-slate-900"><x-icon name="clock" size="size-4" class="text-blue-700" />Time on the road</p>
                            <div class="mt-3 grid grid-cols-[1fr_auto_1fr] items-center gap-3">
                                <div><p class="detail-label">Departed</p><p class="text-sm font-semibold text-slate-900">{{ $transport->actual_departed_at?->timezone(config('app.timezone'))->format('g:i A') ?? '—' }}</p><p class="text-xs text-slate-500">{{ $transport->actual_departed_at?->timezone(config('app.timezone'))->format('M j, Y') }}</p></div>
                                <div class="flex flex-col items-center gap-1 text-slate-400"><span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-700">{{ $duration ?? '—' }}</span><x-icon name="arrow-right" size="size-4" /></div>
                                <div class="text-right"><p class="detail-label">Arrived</p><p class="text-sm font-semibold text-slate-900">{{ $transport->actual_arrived_at?->timezone(config('app.timezone'))->format('g:i A') ?? '—' }}</p><p class="text-xs text-slate-500">{{ $transport->actual_arrived_at?->timezone(config('app.timezone'))->format('M j, Y') }}</p></div>
                            </div>
                            @if ($speed)<p class="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500">Average pace about <strong class="text-slate-800">{{ number_format($speed) }} km/h</strong> including stops.</p>@endif
                        </div>

                        <div class="rounded-xl border border-slate-200 p-4">
                            <p class="flex items-center gap-2 text-sm font-semibold text-slate-900"><x-icon name="gauge" size="size-4" class="text-blue-700" />Fuel level</p>
                            <div class="mt-3 grid grid-cols-[1fr_auto_1fr] items-center gap-3">
                                <x-transport-fuel-gauge :level="$transport->fuel_before" label="Before" />
                                <x-icon name="arrow-right" size="size-4" class="text-slate-400" />
                                <x-transport-fuel-gauge :level="$transport->fuel_after" label="After" />
                            </div>
                            @if ($fuelChange !== null)
                                <p class="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500">
                                    @if ($fuelChange > 0)About <strong class="text-slate-800">{{ $fuelChange }}%</strong> of a tank used.@elseif ($fuelChange < 0)Refuelled during the trip (<strong class="text-slate-800">+{{ abs($fuelChange) }}%</strong>).@else No change in fuel level.@endif
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
                @if ($transport->trip_remarks)<p class="mt-4 text-sm text-slate-700"><span class="detail-label block">Trip remarks</span><span class="whitespace-pre-line">{{ $transport->trip_remarks }}</span></p>@endif
                @if ($transport->vehicle_issue_observed && $canSeeTripDocuments)
                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4"><p class="flex items-center gap-2 text-sm font-semibold text-amber-900"><x-icon name="warning" size="size-4" />Vehicle issue observed</p><p class="mt-1 whitespace-pre-line text-sm text-amber-950">{{ $transport->vehicle_issue_remarks }}</p></div>
                @endif
            @endif

            @if ($canCreateWorkOrder)
                <div class="mt-5 border-t border-slate-100 pt-5">
                    @if ($transport->correctiveWorkOrders->isNotEmpty())
                        <p class="text-sm">Corrective Work Order: @foreach ($transport->correctiveWorkOrders as $order)<a class="font-semibold text-blue-800 hover:underline" href="{{ route('work-orders.show', $order) }}">{{ $order->work_order_number }}</a>@endforeach</p>
                    @else
                        <form method="POST" action="{{ route('transport.corrective-work-order', $transport) }}" class="grid gap-3 sm:grid-cols-[1fr_auto]">@csrf
                            <label>Corrective Work Order subject<input name="subject" required maxlength="255" value="{{ old('subject', 'Vehicle issue: '.$transport->vehicle?->name) }}"></label>
                            <button class="btn btn-warning self-end" type="submit"><x-icon name="wrench" />Create Corrective Work Order</button>
                        </form>
                    @endif
                </div>
            @endif
        </section>
    @endif
</div>

<aside class="space-y-6 lg:sticky lg:top-24 lg:self-start">
    <section class="section-card">
        <h2 class="text-base">Requested by</h2>
        <div class="mt-3 flex items-center gap-3"><x-avatar :name="$transport->requester->name" /><div class="min-w-0"><p class="truncate font-semibold text-slate-900">{{ $transport->requester->name }}</p><p class="text-xs text-slate-500">Submitted {{ $dt($transport->created_at) }}</p></div></div>
    </section>
    <section class="section-card space-y-5">
        <h2 class="flex items-center gap-2 text-base"><x-icon name="paperclip" size="size-4" />Documents</h2>
        @include('transport.partials.attachments', ['title' => 'Request Documents', 'attachments' => $requestDocs, 'empty' => 'No supporting document was uploaded.'])
        @if ($canSeeApprovalEvidence)
            @include('transport.partials.attachments', ['title' => 'Approval Evidence', 'attachments' => $approvalDocs, 'empty' => 'No approval evidence was uploaded.'])
        @endif
        @if ($canSeeTripDocuments)
            @include('transport.partials.attachments', ['title' => 'Trip Documents', 'attachments' => $tripDocs, 'empty' => 'No trip document was uploaded.'])
        @endif
    </section>
    <section class="section-card">
        <h2 class="text-base">Activity</h2>
        <ol class="mt-4">
            @foreach ($transport->events as $event)
                <li class="timeline-item">
                    <p class="text-sm font-semibold text-slate-900">{{ str($event->event)->replace('_', ' ')->title() }}</p>
                    <p class="text-xs text-slate-500">{{ $event->actor?->name }} · {{ $dt($event->created_at) }}</p>
                    @if ($event->note && ($event->event !== 'APPROVED' || $canSeeApprovalEvidence))<p class="mt-1 text-xs text-slate-600">{{ $event->note }}</p>@endif
                </li>
            @endforeach
        </ol>
    </section>
    @if ($canCancel)
        <section class="section-card">
            <form method="POST" action="{{ route('transport.cancel', $transport) }}" class="space-y-3" data-confirm="Cancel this transport request?">@csrf
                <label>Cancellation reason <span class="font-normal text-slate-400">— Optional</span><input name="reason" maxlength="1000"></label>
                <button class="btn btn-secondary w-full" type="submit">Cancel request</button>
            </form>
        </section>
    @endif
</aside>
</div>
@endsection
