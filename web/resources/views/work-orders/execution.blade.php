@php($executionViewer = auth()->user()->can('work_orders.view_execution') || $assigned)
@php($mySession = $assigned ? $order->sessions->first(fn ($session) => $session->ended_at === null && $session->personnel?->user_id === auth()->id()) : null)

@if ($executionViewer)
<section class="mt-6 rounded border bg-white p-5">
    <h2 class="text-lg font-semibold">Work execution</h2>
    <p class="text-sm text-slate-600">A work session begins only after Start Work. Each team member records their own activity.</p>
    @if (auth()->user()->can('work_orders.view_execution'))
        @php($timeline = $order->workflowEvents->map(fn ($event) => ['at' => $event->created_at, 'label' => str_replace('_', ' ', $event->action), 'actor' => $event->actor?->name, 'detail' => $event->internal_note ?? $event->requester_message])->concat($order->updates->map(fn ($update) => ['at' => $update->recorded_at, 'label' => $update->type->value, 'actor' => $update->personnel->user->name, 'detail' => $update->description]))->sortBy('at'))
        <h3 class="mt-5 font-semibold">Execution timeline</h3>
        <ol class="mt-2 space-y-2">@foreach ($timeline as $item)<li class="border-l-2 pl-3"><small>{{ $item['at']->timezone('Asia/Manila')->format('M j, Y g:i A') }} · {{ $item['actor'] }} · {{ $item['label'] }}</small>@if ($item['detail'])<p>{{ $item['detail'] }}</p>@endif</li>@endforeach</ol>
    @endif
    @if ($assigned && ! $mySession && in_array($order->status, [\App\Enums\WorkOrderStatus::ReadyForWork, \App\Enums\WorkOrderStatus::InProgress, \App\Enums\WorkOrderStatus::ForContinuation, \App\Enums\WorkOrderStatus::Paused, \App\Enums\WorkOrderStatus::WaitingForMaterials]))
        @can('work_orders.start_assigned')<form method="POST" action="{{ route('work-orders.start-work', $order) }}" class="mt-4">@csrf<button class="rounded bg-green-700 px-5 py-3 font-semibold text-white">Start Work</button></form>@endcan
    @endif

    @if ($mySession)
        <p class="mt-4 rounded bg-green-50 p-3">Your active session began {{ $mySession->started_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}.</p>
        @if ($order->status === \App\Enums\WorkOrderStatus::InProgress)
        <form method="POST" enctype="multipart/form-data" action="{{ route('work-orders.execution.update', [$order, $mySession]) }}" class="mt-4 space-y-2 border-t pt-4">@csrf
            <h3 class="font-semibold">Add individual update and evidence</h3>
            <select name="type" class="w-full rounded border p-2">@foreach (['BEFORE' => 'Before / Initial Evidence', 'PROGRESS' => 'Progress', 'ISSUE' => 'Issue', 'COMPLETION' => 'Completion / Outcome Evidence', 'OTHER' => 'Other'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            <textarea name="description" required placeholder="What did you do or find? (internal detail)" class="w-full rounded border p-2"></textarea>
            <textarea name="requester_summary" placeholder="Optional requester-visible progress summary" class="w-full rounded border p-2"></textarea>
            <input type="file" name="evidence[]" multiple accept="image/jpeg,image/png,image/webp,video/mp4">
            <button class="rounded bg-blue-700 px-4 py-2 text-white">Save Update</button>
        </form>
        <form method="POST" enctype="multipart/form-data" action="{{ route('work-orders.execution.end', [$order, $mySession]) }}" class="mt-6 space-y-2 border-t pt-4">@csrf
            <h3 class="font-semibold">End your work session</h3>
            <select name="outcome" required class="w-full rounded border p-2">@foreach (\App\Enums\WorkSessionOutcome::cases() as $outcome)<option value="{{ $outcome->value }}">{{ str_replace('_', ' ', $outcome->value) }}</option>@endforeach</select>
            <textarea name="summary" required placeholder="Explain the state of the work" class="w-full rounded border p-2"></textarea>
            <textarea name="requester_summary" placeholder="Optional requester-visible update" class="w-full rounded border p-2"></textarea>
            <textarea name="remaining_work" placeholder="What remains? Required for continuation" class="w-full rounded border p-2"></textarea>
            <input name="material_description" placeholder="Materials/resources required when waiting" class="w-full rounded border p-2">
            <input type="file" name="evidence[]" multiple accept="image/jpeg,image/png,image/webp,video/mp4">
            <h4 class="font-medium">If submitting completion with this end action</h4>
            <textarea name="completion_summary" placeholder="Completion summary" class="w-full rounded border p-2"></textarea>
            <textarea name="work_performed_summary" placeholder="Work performed" class="w-full rounded border p-2"></textarea>
            <textarea name="non_photo_reason" placeholder="If no completion photo is possible, provide a specific justification" class="w-full rounded border p-2"></textarea>
            <button class="rounded bg-slate-700 px-4 py-2 text-white">End Session with Outcome</button>
        </form>
        @endif
    @endif

    @if ($assigned && ! $mySession && in_array($order->status, [\App\Enums\WorkOrderStatus::InProgress, \App\Enums\WorkOrderStatus::ForContinuation]) && ! $order->sessions->contains(fn ($session) => $session->ended_at === null))
        @can('work_orders.submit_completion')<form method="POST" action="{{ route('work-orders.submit-verification', $order) }}" class="mt-6 space-y-2 border-t pt-4">@csrf
            <h3 class="font-semibold">Submit for verification</h3><p class="text-sm">Record a completion update and photo during a session first, or explain why a photo is not meaningful.</p>
            <textarea name="completion_summary" required placeholder="Completion summary" class="w-full rounded border p-2"></textarea>
            <textarea name="work_performed_summary" required placeholder="Work performed" class="w-full rounded border p-2"></textarea>
            <textarea name="requester_summary" placeholder="Requester-visible completion summary" class="w-full rounded border p-2"></textarea>
            <textarea name="non_photo_reason" placeholder="Specific non-photographic justification, if needed" class="w-full rounded border p-2"></textarea>
            <button class="rounded bg-blue-700 px-4 py-2 text-white">Submit for Verification</button>
        </form>@endcan
    @endif

    @if ($order->status === \App\Enums\WorkOrderStatus::ForVerification)
        @can('work_orders.verify_completion')<form method="POST" action="{{ route('work-orders.verify', $order) }}" class="mt-5 border-t pt-4">@csrf<label>Verification note<textarea name="verification_note" class="mt-1 w-full rounded border p-2"></textarea></label><button class="mt-2 rounded bg-green-700 px-4 py-2 text-white">Verify Completion</button></form>@endcan
        @can('work_orders.return_for_work')<form method="POST" action="{{ route('work-orders.return-for-work', $order) }}" class="mt-5 border-t pt-4">@csrf<label>Reason for additional work<textarea name="reason" required class="mt-1 w-full rounded border p-2"></textarea></label><button class="mt-2 rounded bg-amber-700 px-4 py-2 text-white">Return for Additional Work</button></form>@endcan
    @endif
    @if ($order->status === \App\Enums\WorkOrderStatus::NeedsInvestigation)
        @can('work_orders.resolve_assessment')<form method="POST" action="{{ route('work-orders.release-investigation', $order) }}" class="mt-5 border-t pt-4">@csrf<label>Investigation resolution<textarea name="note" required class="mt-1 w-full rounded border p-2"></textarea></label><button class="mt-2 rounded bg-blue-700 px-4 py-2 text-white">Release for Work</button></form>@endcan
    @endif

    <h3 class="mt-6 font-semibold">Individual sessions and updates</h3>
    @forelse ($order->sessions as $session)
        <article class="mt-3 rounded border p-3"><b>{{ $session->personnel->user->name }}</b> · {{ $session->started_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} → {{ $session->ended_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'ACTIVE' }} @if ($session->end_outcome) · {{ str_replace('_', ' ', $session->end_outcome->value) }} @endif
            @if ($session->session_summary)<p>{{ $session->session_summary }}</p>@endif
            @foreach ($session->updates as $update)
                <div class="mt-2 border-l-2 pl-3"><small>{{ $update->recorded_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} · {{ $update->type->value }}</small><p>{{ $update->description }}</p>
                    @foreach ($update->attachments as $file)<a class="mr-2 text-blue-700 underline" href="{{ route('work-orders.attachments.show', [$order, $file]) }}">{{ $file->evidence_type }}: {{ $file->original_filename }}</a>@endforeach
                </div>
            @endforeach
            @if ($session->ended_at === null && auth()->user()->can('work_orders.manage_sessions'))
                <form method="POST" action="{{ route('work-orders.execution.force-end', [$order, $session]) }}" class="mt-3">@csrf<input name="reason" required placeholder="Administrative reason" class="rounded border p-2"><button class="ml-2 text-red-700">Resolve Stuck Session</button></form>
            @endif
        </article>
    @empty<p class="mt-2">No work sessions yet.</p>@endforelse

    @if ($order->completion_summary)<p class="mt-5"><b>Submitted completion:</b> {{ $order->completion_summary }}</p>@endif
</section>
@else
<section class="mt-6 rounded border bg-white p-5"><h2 class="text-lg font-semibold">Work progress</h2>
    @foreach ($order->updates->whereNotNull('requester_summary')->sortBy('recorded_at') as $update)<p class="mt-2">{{ $update->recorded_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} — {{ $update->requester_summary }}</p>@endforeach
    @if ($order->status === \App\Enums\WorkOrderStatus::Completed)<p class="mt-4"><b>Completed:</b> {{ $order->verified_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}</p><p>{{ $order->work_performed_summary }}</p>@endif
</section>
@endif
