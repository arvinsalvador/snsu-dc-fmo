@extends('layouts.app')
@section('title', $order->work_order_number)
@section('content')
@php($management = auth()->user()->can('work_orders.view_all') || auth()->user()->can('work_orders.screen') || auth()->user()->can('work_orders.approve') || auth()->user()->can('work_orders.view_assessments'))
@php($assigned = auth()->user()->can('work_orders.view_assigned') && app(\App\Services\WorkOrderAssignmentService::class)->assignedTo($order, auth()->user()))
@php($canAssign = app(\App\Services\WorkOrderAssignmentService::class)->mayManage($order, auth()->user(), $order->status !== \App\Enums\WorkOrderStatus::Approved))
<a href="{{ route('work-orders.index') }}">← Work Orders</a>
<h1 class="mt-3 text-2xl font-semibold">{{ $order->work_order_number }}</h1>
<p class="mt-2">Status: {{ str_replace('_', ' ', $order->status->value) }}</p>
@if ($order->status === \App\Enums\WorkOrderStatus::Approved)
    <p class="mt-3 rounded bg-green-100 p-3">Approved by FMO and awaiting assignment.</p>
@endif
@if ($order->status === \App\Enums\WorkOrderStatus::NeedsInformation)
    <p class="mt-3 rounded bg-amber-100 p-3">FMO requires additional information. Please update and resubmit this request.</p>
@endif
@if ($order->requester_id === auth()->id() && (($order->status === \App\Enums\WorkOrderStatus::Submitted && auth()->user()->can('work_orders.update_own_submitted')) || ($order->status === \App\Enums\WorkOrderStatus::NeedsInformation && auth()->user()->can('work_orders.resubmit_own'))))
    <a class="mt-3 inline-block rounded bg-blue-700 px-4 py-2 text-white" href="{{ route('work-orders.edit', $order) }}">{{ $order->status === \App\Enums\WorkOrderStatus::NeedsInformation ? 'Update and Resubmit' : 'Edit Request' }}</a>
@endif
<div class="mt-5 space-y-2 rounded border bg-white p-5">
    <p><b>Submitted:</b> {{ $order->submitted_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}</p>
    @if ($management)<p><b>Requester:</b> {{ $order->requester->name }} ({{ $order->requester->user_type }})</p>@endif
    <p><b>Category:</b> {{ $order->category->name }}</p>
    <p><b>Location:</b> {{ $order->campus->name }} — {{ $order->building->name }} @if ($order->floor) — {{ $order->floor->name }} @endif @if ($order->location) — {{ $order->location->name }} @endif</p>
    <p><b>Subject:</b> {{ $order->subject }}</p>
    <p><b>Description:</b> {{ $order->description }}</p>
    <p><b>Urgency indicated:</b> {{ $order->urgency }}</p>
    <p><b>Preferred personnel:</b> {{ $order->preferredPersonnel?->user?->name ?? 'None' }} (preference only)</p>
    @if ($management && $order->recommendation)<p><b>Screening recommendation:</b> {{ $order->recommendation }}</p>@endif
</div>
<h2 class="mt-6 text-lg font-semibold">Initial attachments</h2>
<ul class="mt-2 list-inside list-disc">
    @forelse ($order->attachments->where('purpose', '!=', 'ASSESSMENT') as $attachment)
        <li><a class="text-blue-700 underline" href="{{ route('work-orders.attachments.show', [$order, $attachment]) }}">{{ $attachment->original_filename }}</a></li>
    @empty
        <li>No attachments</li>
    @endforelse
</ul>
<h2 class="mt-6 text-lg font-semibold">History</h2>
<ol class="mt-2 space-y-2">
    @foreach ($order->workflowEvents as $event)
        @if ($management || !in_array($event->action, ['RECOMMEND_APPROVAL', 'RECOMMEND_DISAPPROVAL', 'RETURN_TO_SCREENING', 'ASSIGNEE_ADDED', 'ASSIGNEE_REMOVED', 'ASSESSMENT_EXCEPTION', 'ASSESSMENT_HOLD_MATERIALS', 'ASSESSMENT_REFER_EXTERNAL', 'ASSESSMENT_BEYOND_SCOPE']))
            <li class="rounded border bg-white p-3"><b>{{ str_replace('_', ' ', $event->action) }}</b> · {{ $event->created_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}
                @if ($event->requester_message)<p>{{ $event->requester_message }}</p>@endif
                @if ($management && $event->internal_note)<p>Internal: {{ $event->internal_note }}</p>@endif
            </li>
        @endif
    @endforeach
</ol>
@if (in_array($order->status, [\App\Enums\WorkOrderStatus::Assigned, \App\Enums\WorkOrderStatus::ForAssessment, \App\Enums\WorkOrderStatus::AssessmentReview, \App\Enums\WorkOrderStatus::ReadyForWork]))
    <section class="mt-6 rounded border bg-white p-5"><h2 class="text-lg font-semibold">Assigned FMO personnel</h2>
        <ul class="mt-2 list-disc pl-5">@forelse ($order->activeAssignments as $assignment)<li>{{ $assignment->personnel->user->name }} — {{ $assignment->personnel->designation }}
            @if ($canAssign && auth()->user()->can('work_orders.remove_assignee') && $order->status !== \App\Enums\WorkOrderStatus::ReadyForWork)<form method="POST" action="{{ route('work-orders.assignments.destroy', [$order, $assignment]) }}" class="inline">@csrf @method('DELETE')<input name="reason" required placeholder="Removal reason" class="rounded border p-1"><button class="text-red-700">Remove</button></form>@endif
        </li>@empty<li>None</li>@endforelse</ul>
    </section>
@endif
@if ($canAssign && in_array($order->status, [\App\Enums\WorkOrderStatus::Approved, \App\Enums\WorkOrderStatus::Assigned, \App\Enums\WorkOrderStatus::ForAssessment, \App\Enums\WorkOrderStatus::AssessmentReview]))
    <section class="mt-6 rounded border bg-white p-5"><h2 class="text-lg font-semibold">Assign personnel</h2><p class="text-sm">Preferred personnel is advisory. Select active personnel; assess skills and current workload before assigning.</p>
        <form method="POST" action="{{ route('work-orders.assignments.store', $order) }}" class="mt-3 space-y-3">@csrf
            <select name="personnel_ids[]" multiple required class="w-full rounded border p-2">@foreach ($assignablePersonnel as $person)<option value="{{ $person->id }}">{{ $person->user->name }} — {{ $person->designation }} — {{ $person->skills->pluck('name')->join(', ') }} ({{ $person->workOrderAssignments()->whereNull('unassigned_at')->count() }} active)</option>@endforeach</select>
            <textarea name="note" placeholder="Internal assignment note (optional)" class="w-full rounded border p-2"></textarea><button class="rounded bg-blue-700 px-4 py-2 text-white">Assign</button>
        </form>
    </section>
@endif
@if ($assigned && $order->status === \App\Enums\WorkOrderStatus::Assigned)
    <form class="mt-6" method="POST" action="{{ route('work-orders.assessment.acknowledge', $order) }}">@csrf<button class="rounded bg-blue-700 px-4 py-2 text-white">Acknowledge and begin assessment</button></form>
@endif
@if ($assigned && in_array($order->status, [\App\Enums\WorkOrderStatus::ForAssessment, \App\Enums\WorkOrderStatus::ReadyForWork]))
    <section class="mt-6 rounded border bg-white p-5"><h2 class="text-lg font-semibold">Record assessment</h2><form method="POST" enctype="multipart/form-data" action="{{ route('work-orders.assessments.store', $order) }}" class="mt-3 space-y-3">@csrf
        <select name="outcome" required class="w-full rounded border p-2">@foreach (\App\Enums\AssessmentOutcome::cases() as $outcome)<option value="{{ $outcome->value }}">{{ str_replace('_', ' ', $outcome->value) }}</option>@endforeach</select>
        <textarea name="findings" required placeholder="Assessment findings" class="w-full rounded border p-2"></textarea><textarea name="resource_notes" placeholder="Resources or materials needed (optional)" class="w-full rounded border p-2"></textarea><input type="file" name="evidence[]" multiple accept="image/jpeg,image/png,image/webp"><button class="rounded bg-blue-700 px-4 py-2 text-white">Submit assessment</button>
    </form></section>
@endif
@if ($management || $assigned)
    <section class="mt-6 rounded border bg-white p-5"><h2 class="text-lg font-semibold">Staff assessments</h2>
    @forelse ($order->assessments as $assessment)
        <article class="mt-3 border-t pt-3"><b>{{ $assessment->personnel->user->name }} — {{ str_replace('_', ' ', $assessment->outcome->value) }}</b><p>{{ $assessment->findings }}</p>
            @if ($assessment->resource_notes)<p>Resources: {{ $assessment->resource_notes }}</p>@endif
            @foreach ($assessment->attachments as $file)<a class="mr-3 text-blue-700 underline" href="{{ route('work-orders.attachments.show', [$order, $file]) }}">{{ $file->original_filename }}</a>@endforeach
        </article>
    @empty<p>No assessment yet.</p>
    @endforelse
    </section>
@endif
@if ($order->status === \App\Enums\WorkOrderStatus::AssessmentReview && auth()->user()->can('work_orders.resolve_assessment'))
    <section class="mt-6 rounded border bg-white p-5"><h2 class="text-lg font-semibold">Management assessment review</h2>@foreach (['proceed' => 'Proceed to ready for work', 'investigate' => 'Further investigation', 'request-information' => 'Request requester information', 'hold-materials' => 'Hold for materials', 'refer-external' => 'Refer externally', 'beyond-scope' => 'Beyond FMO scope', 'cancel' => 'Cancel'] as $decision => $label)
        @if (($decision !== 'refer-external' || auth()->user()->can('work_orders.refer_external')) && ($decision !== 'beyond-scope' || auth()->user()->can('work_orders.mark_beyond_scope')) && ($decision !== 'cancel' || auth()->user()->can('work_orders.cancel')))
        <form method="POST" action="{{ route('work-orders.assessment.resolve', [$order, $decision]) }}" class="mt-3 border-t pt-3">@csrf<strong>{{ $label }}</strong><textarea name="internal_note" placeholder="Internal note" class="mt-1 w-full rounded border p-2"></textarea><textarea name="requester_message" placeholder="Message to requester when applicable" class="mt-1 w-full rounded border p-2"></textarea><button class="rounded bg-slate-700 px-4 py-2 text-white">Confirm</button></form>
        @endif
    @endforeach</section>
@endif
@if ($management)
    <section class="mt-7 rounded border bg-white p-5"><h2 class="text-lg font-semibold">Management actions</h2><div class="mt-4 grid gap-4 md:grid-cols-2">
        @if ($order->status === \App\Enums\WorkOrderStatus::Submitted)
            @can('work_orders.screen')<form method="POST" action="{{ route('work-orders.workflow', [$order, 'begin-screening']) }}">@csrf<button class="rounded bg-blue-700 px-4 py-2 text-white">Begin Screening</button></form>@endcan
        @endif
        @if ($order->status === \App\Enums\WorkOrderStatus::ForScreening)
            @can('work_orders.request_information')<form method="POST" action="{{ route('work-orders.workflow', [$order, 'request-information']) }}">@csrf<label>Request more information<textarea class="mt-1 w-full rounded border p-2" name="requester_message" required></textarea></label><button class="mt-2 rounded bg-amber-700 px-4 py-2 text-white">Send to requester</button></form>@endcan
            @can('work_orders.recommend')<form method="POST" action="{{ route('work-orders.workflow', [$order, 'recommend-approval']) }}">@csrf<label>Internal screening note<textarea class="mt-1 w-full rounded border p-2" name="internal_note"></textarea></label><button class="mt-2 rounded bg-blue-700 px-4 py-2 text-white">Recommend approval</button></form>@endcan
            @can('work_orders.recommend')<form method="POST" action="{{ route('work-orders.workflow', [$order, 'recommend-disapproval']) }}">@csrf<label>Internal reason<textarea class="mt-1 w-full rounded border p-2" name="internal_note" required></textarea></label><button class="mt-2 rounded bg-slate-700 px-4 py-2 text-white">Recommend disapproval</button></form>@endcan
        @endif
        @if ($order->status === \App\Enums\WorkOrderStatus::ForApproval)
            @can('work_orders.approve')<form method="POST" action="{{ route('work-orders.workflow', [$order, 'approve']) }}">@csrf<label>Internal approval note<textarea class="mt-1 w-full rounded border p-2" name="internal_note"></textarea></label><button class="mt-2 rounded bg-green-700 px-4 py-2 text-white">Approve</button></form>@endcan
            @can('work_orders.disapprove')<form method="POST" action="{{ route('work-orders.workflow', [$order, 'disapprove']) }}">@csrf<label>Reason shown to requester<textarea class="mt-1 w-full rounded border p-2" name="requester_message" required></textarea></label><button class="mt-2 rounded bg-red-700 px-4 py-2 text-white">Disapprove</button></form>@endcan
            @can('work_orders.return_to_screening')<form method="POST" action="{{ route('work-orders.workflow', [$order, 'return-to-screening']) }}">@csrf<label>Internal return reason<textarea class="mt-1 w-full rounded border p-2" name="internal_note" required></textarea></label><button class="mt-2 rounded bg-slate-700 px-4 py-2 text-white">Return to screening</button></form>@endcan
        @endif
    </div></section>
@endif
@endsection
