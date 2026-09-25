<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $order->work_order_number }} · Work Order record</title>
<style>
body{font-family:Arial,sans-serif;color:#17212b;max-width:850px;margin:2rem auto;line-height:1.45}
h1{font-size:1.5rem;margin-bottom:.2rem}h2{font-size:1.05rem;border-bottom:1px solid #cbd5e1;padding-bottom:.3rem;margin-top:1.6rem}
.meta{color:#475569}.grid{display:grid;grid-template-columns:1fr 1fr;gap:.4rem 1.5rem}
.entry{border-bottom:1px solid #e2e8f0;padding:.5rem 0}.actions{margin-bottom:1rem}
@media print{body{margin:0;max-width:none}.actions{display:none}a{color:inherit;text-decoration:none}}
</style></head><body>
<div class="actions"><button onclick="window.print()">Print</button> <a href="{{ route('reports.history') }}">Back to history</a></div>
<p class="meta">Surigao del Norte State University · Del Carmen Campus · Facilities Management Office</p>
<h1>Work Order {{ $order->work_order_number }}</h1>
<p class="meta">System-generated record · {{ str_replace('_', ' ', $order->status->value) }}</p>
<h2>Request</h2>
<div class="grid">
<div><b>Requester:</b> {{ $order->requester->name }}</div>
<div><b>Submitted:</b> {{ $order->submitted_at?->timezone('Asia/Manila')->format('Y-m-d H:i') }}</div>
<div><b>Category:</b> {{ $order->category->name }}</div>
<div><b>Campus:</b> {{ $order->campus->name }}</div>
<div><b>Building:</b> {{ $order->building->name }}</div>
<div><b>Office / room / area:</b> {{ $order->location?->name ?? $order->floor?->name ?? 'Not specified' }}</div>
</div>
<p><b>Subject:</b> {{ $order->subject }}</p><p><b>Description:</b> {{ $order->description }}</p>
@if ($management && $order->preferredPersonnel)<p><b>Preferred personnel:</b> {{ $order->preferredPersonnel->user->name }} (requester preference)</p>@endif
<h2>Decision and assignment</h2>
<p><b>Decision:</b> {{ $order->decided_at ? ($order->decisionMaker?->name ?? 'Authorized reviewer').' · '.$order->decided_at->timezone('Asia/Manila')->format('Y-m-d H:i') : 'Pending' }}</p>
<p><b>Assigned personnel:</b> {{ ($management ? $order->assignments : $order->activeAssignments)->pluck('personnel.user.name')->filter()->unique()->join(', ') ?: 'None' }}</p>
@if ($management)
<h2>Staff assessment</h2>
@forelse ($order->assessments as $assessment)
<div class="entry"><b>{{ $assessment->personnel->user->name }}</b> · {{ str_replace('_', ' ', $assessment->outcome->value) }} · {{ $assessment->assessed_at?->timezone('Asia/Manila')->format('Y-m-d H:i') }}<br>{{ $assessment->findings }}</div>
@empty<p>No assessment recorded.</p>@endforelse
<h2>Work sessions</h2>
@forelse ($order->sessions as $session)
<div class="entry">{{ $session->personnel->user->name }} · {{ $session->started_at?->timezone('Asia/Manila')->format('Y-m-d H:i') }} to {{ $session->ended_at?->timezone('Asia/Manila')->format('Y-m-d H:i') ?? 'active' }} · {{ $session->end_outcome?->value ?? 'In progress' }}</div>
@empty<p>No work session recorded.</p>@endforelse
@endif
<h2>Completion and verification</h2>
<p><b>Work performed:</b> {{ $order->work_performed_summary ?: 'Not yet recorded' }}</p>
<p><b>Completion summary:</b> {{ $order->completion_summary ?: 'Not yet submitted' }}</p>
<p><b>Submitted by:</b> {{ $order->completionSubmitter?->name ?? '—' }} · {{ $order->completion_submitted_at?->timezone('Asia/Manila')->format('Y-m-d H:i') ?? '—' }}</p>
<p><b>Verified by:</b> {{ $order->verifier?->name ?? '—' }} · {{ $order->verified_at?->timezone('Asia/Manila')->format('Y-m-d H:i') ?? '—' }}</p>
<p><b>Evidence records:</b> {{ $order->attachments->count() }} attachment(s) stored in the system; full-resolution files are not embedded.</p>
<h2>Public milestones</h2>
@foreach ($order->workflowEvents as $event)
    @if ($management || $event->requester_message || in_array($event->action, ['SUBMITTED', 'DIRECT_AUTHORIZATION', 'APPROVE', 'DISAPPROVE', 'COMPLETION_VERIFIED']))
    <div class="entry">{{ str_replace('_', ' ', $event->action) }} · {{ $event->created_at->timezone('Asia/Manila')->format('Y-m-d H:i') }}
        @if ($event->requester_message)<br>{{ $event->requester_message }}@endif
    </div>
    @endif
@endforeach
<p class="meta">This record reflects authenticated system actions. It is not a handwritten signature.</p>
</body></html>
