@extends('layouts.app')
@section('title', $order->work_order_number)
@section('content')
@php
    $user = auth()->user();
    $assignmentService = app(\App\Services\WorkOrderAssignmentService::class);
    $management = $user->can('work_orders.view_all') || $user->can('work_orders.screen') || $user->can('work_orders.approve') || $user->can('work_orders.view_assessments');
    $assigned = $user->can('work_orders.view_assigned') && $assignmentService->assignedTo($order, $user);
    $canAssign = $assignmentService->mayManage($order, $user, $order->status !== \App\Enums\WorkOrderStatus::Approved);
    $canViewAssessmentMedia = $user->can('work_orders.view_assessments') || $assigned;
    $canViewExecutionMedia = $user->can('work_orders.view_execution') || $assigned;
    $mySession = $assigned ? $order->sessions->first(fn ($session) => $session->ended_at === null && $session->personnel?->user_id === $user->id) : null;
    $requesterMedia = $order->attachments->where('purpose', 'REQUEST_INITIAL')->values();
    $assessmentMedia = $canViewAssessmentMedia ? $order->attachments->where('purpose', 'ASSESSMENT')->values() : collect();
    $executionMedia = $order->attachments->where('purpose', 'EXECUTION')->filter(fn ($file) => $canViewExecutionMedia || ($order->requester_id === $user->id && $file->requester_visible))->values();
    $completionMedia = $executionMedia->where('evidence_type', 'COMPLETION')->values();
    $workMedia = $executionMedia->where('evidence_type', '!=', 'COMPLETION')->values();
    $statusCopy = match ($order->status) {
        \App\Enums\WorkOrderStatus::Submitted => ['Submitted', 'Your request is recorded and waiting for the Facilities Management Office to begin screening.'],
        \App\Enums\WorkOrderStatus::ForScreening => ['For Screening', 'FMO is reviewing the request details and deciding whether more information is needed.'],
        \App\Enums\WorkOrderStatus::NeedsInformation => ['Additional Information Required', 'FMO needs additional information before review can continue.'],
        \App\Enums\WorkOrderStatus::ForApproval => ['For Approval', 'Screening is complete. An authorized approver must make the final decision.'],
        \App\Enums\WorkOrderStatus::Approved => ['Approved', 'The Work Order is approved and awaiting FMO personnel assignment.'],
        \App\Enums\WorkOrderStatus::Disapproved => ['Disapproved', 'This request was reviewed and not approved for FMO work.'],
        \App\Enums\WorkOrderStatus::Assigned => ['Assigned', 'The FMO team is assigned. An assigned staff member may now begin assessment.'],
        \App\Enums\WorkOrderStatus::ForAssessment => ['For Assessment', 'Assigned personnel are inspecting the concern and recording findings.'],
        \App\Enums\WorkOrderStatus::AssessmentReview => ['Assessment Review', 'Assessment findings require management review before work proceeds.'],
        \App\Enums\WorkOrderStatus::ReadyForWork => ['Ready for Work', 'Assessment is complete. Assigned FMO personnel may begin the actual work.'],
        \App\Enums\WorkOrderStatus::InProgress => ['In Progress', 'The assigned FMO team is actively working on this request.'],
        \App\Enums\WorkOrderStatus::ForContinuation => ['For Continuation', 'Work has paused at a safe point and requires another work session.'],
        \App\Enums\WorkOrderStatus::Paused => ['Paused', 'Work is temporarily paused pending the next operational step.'],
        \App\Enums\WorkOrderStatus::WaitingForMaterials => ['Waiting for Materials', 'Work will continue when the required materials or resources are available.'],
        \App\Enums\WorkOrderStatus::NeedsInvestigation => ['Needs Investigation', 'A field issue needs management review or further investigation.'],
        \App\Enums\WorkOrderStatus::ForVerification => ['For Verification', 'The assigned team submitted completed work for management verification.'],
        \App\Enums\WorkOrderStatus::Completed => ['Completed', 'The work was verified and this Work Order is complete.'],
        \App\Enums\WorkOrderStatus::Cancelled => ['Cancelled', 'This Work Order was cancelled and no further action is expected.'],
    };
    $operational = in_array($order->status, [\App\Enums\WorkOrderStatus::Assigned, \App\Enums\WorkOrderStatus::ForAssessment, \App\Enums\WorkOrderStatus::AssessmentReview, \App\Enums\WorkOrderStatus::ReadyForWork, \App\Enums\WorkOrderStatus::InProgress, \App\Enums\WorkOrderStatus::ForContinuation, \App\Enums\WorkOrderStatus::Paused, \App\Enums\WorkOrderStatus::WaitingForMaterials, \App\Enums\WorkOrderStatus::NeedsInvestigation, \App\Enums\WorkOrderStatus::ForVerification, \App\Enums\WorkOrderStatus::Completed], true);
    $privateEvents = ['RECOMMEND_APPROVAL', 'RECOMMEND_DISAPPROVAL', 'RETURN_TO_SCREENING', 'ASSIGNEE_ADDED', 'ASSIGNEE_REMOVED', 'ASSESSMENT_EXCEPTION', 'ASSESSMENT_HOLD_MATERIALS', 'ASSESSMENT_REFER_EXTERNAL', 'ASSESSMENT_BEYOND_SCOPE'];
    $backRoute = $user->can('work_orders.view_all') ? route('work-orders.index') : ($assigned ? route('work-orders.my-tasks') : route('work-orders.mine'));
@endphp

<a class="inline-flex min-h-10 items-center text-sm font-semibold text-blue-800 hover:underline" href="{{ $backRoute }}">← Back to {{ $assigned && ! $user->can('work_orders.view_all') ? 'My Tasks' : 'Work Orders' }}</a>

<header class="mt-4 border-b border-slate-200 pb-6">
    <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
        <div class="min-w-0">
            <p class="detail-kicker">{{ $order->work_order_number }}</p>
            <h1 class="mt-2 text-balance">{{ $order->subject }}</h1>
            <p class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-600"><span>{{ $order->category->name }}</span><span aria-hidden="true">•</span><span>{{ $order->building->name }}</span><span aria-hidden="true">•</span><span>Submitted {{ $order->submitted_at?->timezone('Asia/Manila')->format('M j, Y') }}</span></p>
            @if ($order->floor || $order->location)<p class="mt-2 text-sm font-medium text-slate-700">{{ $order->location?->name ?? 'Building-wide concern' }}@if($order->floor) · {{ $order->floor->name }}@endif</p>@endif
        </div>
        <x-status-badge :status="$order->status" />
    </div>
</header>

<section class="status-panel mt-6">
    <p class="detail-kicker">Current status</p>
    <div class="mt-2 flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
        <div class="max-w-3xl"><h2 class="text-2xl">{{ $statusCopy[0] }}</h2><p class="mt-2 leading-7 text-slate-700">{{ $statusCopy[1] }}</p></div>
        <div class="shrink-0">
            @if ($order->requester_id === $user->id && $order->status === \App\Enums\WorkOrderStatus::NeedsInformation && $user->can('work_orders.resubmit_own'))
                <p class="detail-label mb-2">Next action</p><a class="btn btn-primary min-h-12 w-full sm:w-auto" href="{{ route('work-orders.edit', $order) }}">Update and Resubmit</a>
            @elseif ($order->requester_id === $user->id && $order->status === \App\Enums\WorkOrderStatus::Submitted && $user->can('work_orders.update_own_submitted'))
                <p class="detail-label mb-2">Available action</p><a class="btn btn-secondary w-full sm:w-auto" href="{{ route('work-orders.edit', $order) }}">Edit Request</a>
            @elseif ($assigned && $order->status === \App\Enums\WorkOrderStatus::Assigned && $user->can('work_orders.assess_assigned'))
                <p class="detail-label mb-2">Next action</p><form method="POST" action="{{ route('work-orders.assessment.acknowledge', $order) }}">@csrf<button class="btn btn-primary min-h-12 w-full sm:w-auto" type="submit">Acknowledge and begin assessment</button></form>
            @elseif ($assigned && $order->status === \App\Enums\WorkOrderStatus::ForAssessment)
                <p class="detail-label mb-2">Next action</p><a class="btn btn-primary min-h-12 w-full sm:w-auto" href="#assessment">Record Assessment</a>
            @elseif ($assigned && $mySession)
                <p class="detail-label mb-2">Current task</p><a class="btn btn-primary min-h-12 w-full sm:w-auto" href="#field-actions"><x-icon name="pencil" />Add Update or End Session</a>
            @elseif ($assigned && in_array($order->status, [\App\Enums\WorkOrderStatus::ReadyForWork, \App\Enums\WorkOrderStatus::ForContinuation, \App\Enums\WorkOrderStatus::Paused, \App\Enums\WorkOrderStatus::WaitingForMaterials], true) && $user->can('work_orders.start_assigned'))
                <p class="detail-label mb-2">Next action</p><form method="POST" action="{{ route('work-orders.start-work', $order) }}">@csrf<button class="btn btn-success min-h-12 w-full sm:w-auto" type="submit"><x-icon name="play" />Start Work</button></form>
            @elseif ($canAssign && $order->status === \App\Enums\WorkOrderStatus::Approved)
                <p class="detail-label mb-2">Next action</p><a class="btn btn-primary min-h-12 w-full sm:w-auto" href="#assignment-tools">Assign FMO Personnel</a>
            @elseif ($order->status === \App\Enums\WorkOrderStatus::AssessmentReview && $user->can('work_orders.resolve_assessment'))
                <p class="detail-label mb-2">Next action</p><a class="btn btn-primary min-h-12 w-full sm:w-auto" href="#assessment-review">Review Assessment</a>
            @elseif ($order->status === \App\Enums\WorkOrderStatus::ForVerification && $user->can('work_orders.verify_completion'))
                <p class="detail-label mb-2">Next action</p><a class="btn btn-success min-h-12 w-full sm:w-auto" href="#completion">Review Completion</a>
            @elseif ($management && in_array($order->status, [\App\Enums\WorkOrderStatus::Submitted, \App\Enums\WorkOrderStatus::ForScreening, \App\Enums\WorkOrderStatus::ForApproval], true))
                <p class="detail-label mb-2">Next action</p><a class="btn btn-primary min-h-12 w-full sm:w-auto" href="#management-actions">Review Workflow Action</a>
            @endif
        </div>
    </div>
    @if ($order->status === \App\Enums\WorkOrderStatus::NeedsInformation && $clarification)
        <div class="mt-6 rounded-xl border border-amber-200 bg-white p-4 sm:p-5">
            <p class="detail-kicker !text-amber-800">Requested information</p>
            <p class="mt-3 whitespace-pre-line text-base leading-7 text-slate-800">{{ $clarification->requester_message }}</p>
            <div class="mt-4 flex flex-wrap gap-x-2 gap-y-1 border-t border-amber-100 pt-3 text-xs text-slate-600">
                <span>Requested by {{ $clarification->actor?->name ?? 'FMO' }}</span>
                <span aria-hidden="true">•</span>
                <time datetime="{{ $clarification->created_at?->toIso8601String() }}">{{ $clarification->created_at?->timezone('Asia/Manila')->format('F j, Y · g:i A') }}</time>
            </div>
        </div>
    @endif
</section>

<div class="mt-6 grid items-start gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
    <main class="min-w-0 space-y-6">
        <section class="section-card">
            <p class="detail-kicker">Request overview</p><h2 class="mt-1 text-xl">Request Details</h2>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2"><div><dt class="detail-label">Work type</dt><dd class="detail-value">{{ $order->category->name }}</dd></div><div><dt class="detail-label">Priority</dt><dd class="detail-value">{{ ucfirst(strtolower($order->urgency)) }}</dd></div><div><dt class="detail-label">Requester</dt><dd class="detail-value">{{ $order->requester->name }}</dd></div><div><dt class="detail-label">Date submitted</dt><dd class="detail-value">{{ $order->submitted_at?->timezone('Asia/Manila')->format('F j, Y · g:i A') }}</dd></div></dl>
            <div class="mt-6 border-t border-slate-100 pt-5"><h3 class="detail-label">Problem description</h3><p class="mt-3 whitespace-pre-line text-base leading-7 text-slate-700">{{ $order->description }}</p></div>
        </section>

        @if ($operational)
            @include('work-orders.execution')
        @endif

        @if ($canViewAssessmentMedia)
            <section id="assessment" class="section-card scroll-mt-24">
                <div><p class="detail-kicker">Inspection</p><h2 class="mt-1 text-xl">Assessment</h2></div>
                @if ($assigned && in_array($order->status, [\App\Enums\WorkOrderStatus::ForAssessment, \App\Enums\WorkOrderStatus::ReadyForWork], true))
                    <details data-collapsible class="mt-5 rounded-xl border border-blue-200 bg-blue-50/50 p-4" @if($order->status === \App\Enums\WorkOrderStatus::ForAssessment) open @endif><x-collapsible-summary :expanded="$order->status === \App\Enums\WorkOrderStatus::ForAssessment" class="-mx-2">Record assessment</x-collapsible-summary>
                        @php($assessmentDescriptions = ['READY_FOR_WORK' => 'The request can proceed to field work.', 'NEEDS_INFORMATION' => 'The requester must clarify key details.', 'NEEDS_FURTHER_INVESTIGATION' => 'More diagnosis is required before work begins.', 'NEEDS_MATERIALS' => 'Materials or resources must be prepared.', 'MATERIALS_UNAVAILABLE' => 'Required materials are currently unavailable.', 'BEYOND_FMO_SCOPE' => 'The work is outside normal FMO capability.', 'EXTERNAL_ASSISTANCE_REQUIRED' => 'An external provider or specialist is needed.', 'RECOMMEND_CANCELLATION' => 'Recommend ending the Work Order without field work.'])
                        <form method="POST" enctype="multipart/form-data" action="{{ route('work-orders.assessments.store', $order) }}" class="mt-4 space-y-4">@csrf<fieldset><legend class="text-sm font-semibold text-slate-800">Assessment outcome <span class="text-rose-600">*</span></legend><div class="mt-3 grid gap-2 md:grid-cols-2">@foreach (\App\Enums\AssessmentOutcome::cases() as $outcome)<label class="flex cursor-pointer gap-3 rounded-xl border border-slate-200 bg-white p-3 transition hover:border-blue-300 has-checked:border-blue-600 has-checked:bg-blue-50"><input class="mt-1" type="radio" name="outcome" value="{{ $outcome->value }}" required @checked(old('outcome') === $outcome->value)><span><span class="block font-semibold text-slate-900">{{ \Illuminate\Support\Str::of($outcome->value)->replace('_', ' ')->title() }}</span><span class="mt-1 block text-xs leading-5 text-slate-500">{{ $assessmentDescriptions[$outcome->value] }}</span></span></label>@endforeach</div></fieldset><label>Assessment findings<textarea name="findings" required></textarea></label><label>Resources or materials needed <span class="font-normal text-slate-400">(optional)</span><textarea name="resource_notes"></textarea></label><x-photo-picker id="assessment-{{ $order->id }}" title="Assessment Evidence" help="Add photos supporting the inspection findings." /><button class="btn btn-primary w-full sm:w-auto" type="submit"><x-icon name="assessment" />Submit Assessment</button></form>
                    </details>
                @endif
                <div class="mt-5 space-y-4">
                    @forelse ($order->assessments->sortByDesc('assessed_at') as $assessment)
                        <article class="rounded-xl border border-slate-200 p-4"><div class="flex flex-wrap items-start justify-between gap-2"><div><p class="font-semibold">{{ $assessment->personnel->user->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $assessment->assessed_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</p></div><span class="status-badge status-neutral">{{ \Illuminate\Support\Str::of($assessment->outcome->value)->replace('_', ' ')->title() }}</span></div><p class="mt-4 leading-6 text-slate-700">{{ $assessment->findings }}</p>@if ($assessment->resource_notes)<p class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900"><strong>Resources:</strong> {{ $assessment->resource_notes }}</p>@endif</article>
                    @empty
                        <p class="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-500">No assessment has been recorded yet.</p>
                    @endforelse
                </div>
            </section>
        @endif

        @if ($order->status === \App\Enums\WorkOrderStatus::AssessmentReview && $user->can('work_orders.resolve_assessment'))
            <details id="assessment-review" data-collapsible class="section-card scroll-mt-24" open><x-collapsible-summary :expanded="true" class="-mx-2 text-lg">Management assessment review</x-collapsible-summary><div class="mt-4 grid gap-4 lg:grid-cols-2">
                @foreach (['proceed' => 'Proceed to ready for work', 'investigate' => 'Further investigation', 'request-information' => 'Request requester information', 'hold-materials' => 'Hold for materials', 'refer-external' => 'Refer externally', 'beyond-scope' => 'Beyond FMO scope', 'cancel' => 'Cancel'] as $decision => $label)
                    @if (($decision !== 'refer-external' || $user->can('work_orders.refer_external')) && ($decision !== 'beyond-scope' || $user->can('work_orders.mark_beyond_scope')) && ($decision !== 'cancel' || $user->can('work_orders.cancel')))
                        <form method="POST" action="{{ route('work-orders.assessment.resolve', [$order, $decision]) }}" class="rounded-xl border border-slate-200 p-4" data-confirm="Apply the assessment decision: {{ $label }}? This decision will be recorded in the Work Order history.">@csrf<strong>{{ $label }}</strong><label class="mt-3 block">Internal note <span class="text-slate-500">(optional)</span><textarea name="internal_note"></textarea></label><label class="mt-3 block">Message to requester <span class="text-slate-500">(when applicable)</span><textarea name="requester_message"></textarea></label><button class="btn btn-secondary mt-3" type="submit">Confirm decision</button></form>
                    @endif
                @endforeach
            </div></details>
        @endif

        <section class="section-card">
            <p class="detail-kicker">Protected media</p><h2 class="mt-1 text-xl">Evidence & Photos</h2><p class="mt-2 text-sm text-slate-500">Select a thumbnail to preview the full image without leaving this page.</p>
            <div class="mt-6 space-y-7">
                <x-evidence-gallery title="Requester Photos" :attachments="$requesterMedia" group="requester-evidence" description="Photos supplied with the original request." />
                @if ($canViewAssessmentMedia)<x-evidence-gallery title="Assessment Evidence" :attachments="$assessmentMedia" group="assessment-evidence" description="Inspection photos recorded by assigned personnel." />@endif
                @if ($workMedia->isNotEmpty() || $canViewExecutionMedia)<x-evidence-gallery title="Work Evidence" :attachments="$workMedia" group="work-evidence" description="Before, progress, issue, and continuation evidence." />@endif
                @if ($completionMedia->isNotEmpty() || $canViewExecutionMedia)<x-evidence-gallery title="Completion Evidence" :attachments="$completionMedia" group="completion-evidence" description="Photos showing the final result." />@endif
            </div>
        </section>

        @if ($order->completion_summary || $order->status === \App\Enums\WorkOrderStatus::ForVerification || $order->status === \App\Enums\WorkOrderStatus::Completed)
            <section id="completion" class="section-card scroll-mt-24 border-emerald-200">
                <p class="detail-kicker !text-emerald-700">Outcome</p><h2 class="mt-1 text-xl">Completion</h2>
                @if ($order->completion_summary)<dl class="mt-5 space-y-4"><div><dt class="detail-label">Completion summary</dt><dd class="mt-1 leading-6 text-slate-700">{{ $order->completion_summary }}</dd></div><div><dt class="detail-label">Work performed</dt><dd class="mt-1 leading-6 text-slate-700">{{ $order->work_performed_summary }}</dd></div>@if($order->completionSubmitter)<div><dt class="detail-label">Submitted by</dt><dd class="detail-value">{{ $order->completionSubmitter->name }} · {{ $order->completion_submitted_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</dd></div>@endif @if($order->verifier)<div><dt class="detail-label">Verified by</dt><dd class="detail-value">{{ $order->verifier->name }} · {{ $order->verified_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</dd></div>@endif @if($order->verification_note)<div><dt class="detail-label">Verification note</dt><dd class="mt-1 text-sm text-slate-700">{{ $order->verification_note }}</dd></div>@endif</dl>@else<p class="mt-4 text-sm text-slate-500">Completion details have not been submitted yet.</p>@endif
                @if ($order->status === \App\Enums\WorkOrderStatus::ForVerification)
                <div class="mt-6 grid gap-4 border-t border-slate-100 pt-5 lg:grid-cols-2">@can('work_orders.verify_completion')<form method="POST" action="{{ route('work-orders.verify', $order) }}" class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4" data-confirm="Verify this completion and close the Work Order? This confirms that the submitted work and evidence are acceptable.">@csrf<h3 class="font-semibold text-emerald-950">Verify completion</h3><label class="mt-3 block">Verification note <span class="text-slate-500">(optional)</span><textarea name="verification_note"></textarea></label><button class="btn btn-success mt-3" type="submit"><x-icon name="shield-check" />Verify Completion</button></form>@endcan @can('work_orders.return_for_work')<form method="POST" action="{{ route('work-orders.return-for-work', $order) }}" class="rounded-xl border border-amber-200 bg-amber-50/40 p-4" data-confirm="Return this Work Order for additional work? Assigned personnel will need to continue field activity.">@csrf<h3 class="font-semibold text-amber-950">Additional work required</h3><label class="mt-3 block">Reason for additional work <span class="text-rose-600">*</span><textarea name="reason" required></textarea></label><button class="btn btn-warning mt-3" type="submit"><x-icon name="chevron-left" />Return for Additional Work</button></form>@endcan</div>
                @endif
            </section>
        @endif

        <section class="section-card"><p class="detail-kicker">Lifecycle</p><h2 class="mt-1 text-xl">Activity Timeline</h2><ol class="mt-6">
            @forelse ($order->workflowEvents as $event)
                @if ($management || ! in_array($event->action, $privateEvents, true))
                    @php($eventLabel = match ($event->action) {
                        'REQUEST_INFORMATION', 'ASSESSMENT_REQUEST_INFORMATION' => 'Additional Information Requested',
                        'RESUBMIT' => 'Requester Resubmitted',
                        default => \Illuminate\Support\Str::of($event->action)->replace('_', ' ')->title(),
                    })
                    <li class="timeline-item"><div class="flex flex-wrap items-baseline justify-between gap-2"><p class="font-semibold">{{ $eventLabel }}</p><p class="text-xs text-slate-500">{{ $event->created_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</p></div>@if($event->actor)<p class="mt-1 text-sm text-slate-500">by {{ $event->actor->name }}</p>@endif @if ($event->requester_message)<p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $event->requester_message }}</p>@endif @if ($management && $event->internal_note)<p class="mt-2 rounded-lg bg-slate-50 p-3 text-sm text-slate-600"><strong>Internal:</strong> {{ $event->internal_note }}</p>@endif</li>
                @endif
            @empty
                <li class="text-sm text-slate-500">No activity has been recorded yet.</li>
            @endforelse
        </ol></section>

        @include('work-orders.partials.assignment-tools')
        @include('work-orders.partials.management-actions')
    </main>

    <aside class="space-y-6 xl:sticky xl:top-24">
        <section class="section-card"><p class="detail-kicker">Where</p><h2 class="mt-1 text-lg">Location</h2><ol class="mt-4 space-y-3 text-sm"><li><span class="detail-label block">Campus</span><span class="detail-value">{{ $order->campus->name }}</span></li><li class="border-l-2 border-blue-200 pl-4"><span class="detail-label block">Building</span><span class="detail-value">{{ $order->building->name }}</span></li><li class="border-l-2 border-blue-200 pl-4"><span class="detail-label block">Floor</span><span class="detail-value">{{ $order->floor?->name ?? 'Building-wide concern' }}</span></li><li class="border-l-2 border-blue-200 pl-4"><span class="detail-label block">Area</span><span class="detail-value">{{ $order->location?->name ?? 'No specific room or area' }}</span></li></ol></section>

        <section class="section-card"><p class="detail-kicker">Responsibility</p><h2 class="mt-1 text-lg">People</h2><div class="mt-4 space-y-4"><div><p class="detail-label mb-2">Requester</p><div class="person-row"><span class="person-avatar">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($order->requester->name, 0, 1)) }}</span><div><p class="text-sm font-semibold">{{ $order->requester->name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ $order->requester->requesterProfile?->department ?? $order->requester->requesterProfile?->organizational_office ?? ucfirst($order->requester->user_type) }}</p></div></div></div>
            @if ($order->preferredPersonnel)<div><p class="detail-label mb-2">Requester preference</p><div class="person-row"><span class="person-avatar !bg-amber-100 !text-amber-800">☆</span><div><p class="text-sm font-semibold">{{ $order->preferredPersonnel->user->name }}</p><p class="mt-0.5 text-xs text-slate-500">Preferred only · not an assignment</p></div></div></div>@endif
            <div><p class="detail-label mb-2">Assigned FMO team</p><div class="space-y-2">@forelse($order->activeAssignments as $assignment)<div class="person-row"><span class="person-avatar">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($assignment->personnel->user->name, 0, 1)) }}</span><div><p class="text-sm font-semibold">{{ $assignment->personnel->user->name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ $assignment->personnel->designation ?: 'FMO Personnel' }}</p></div></div>@empty<p class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">Awaiting FMO personnel assignment.</p>@endforelse</div></div></div></section>

        <section class="section-card"><p class="detail-kicker">Additional details</p><h2 class="mt-1 text-lg">Work Order Info</h2><dl class="mt-4 space-y-4"><div><dt class="detail-label">Work Order number</dt><dd class="detail-value">{{ $order->work_order_number }}</dd></div><div><dt class="detail-label">Urgency</dt><dd class="detail-value">{{ ucfirst(strtolower($order->urgency)) }}</dd></div><div><dt class="detail-label">Last updated</dt><dd class="detail-value">{{ $order->updated_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</dd></div>@if($management && $order->recommendation)<div><dt class="detail-label">Screening recommendation</dt><dd class="detail-value">{{ \Illuminate\Support\Str::of($order->recommendation)->replace('_', ' ')->title() }}</dd></div>@endif</dl></section>
    </aside>
</div>

<x-image-lightbox />
@endsection
