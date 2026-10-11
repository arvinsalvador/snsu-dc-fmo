@php($executionViewer = auth()->user()->can('work_orders.view_execution') || $assigned)
@php($mySession = $assigned ? $order->sessions->first(fn ($session) => $session->ended_at === null && $session->personnel?->user_id === auth()->id()) : null)
@php($visibleUpdates = $executionViewer ? $order->updates->sortByDesc('recorded_at') : $order->updates->whereNotNull('requester_summary')->sortByDesc('recorded_at'))

<section id="current-work" class="section-card scroll-mt-24">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div><p class="detail-kicker">Operational status</p><h2 class="mt-1 text-xl">Current Work</h2></div>
        @if ($order->activeSessions->isNotEmpty())<span class="status-badge status-active">{{ $order->activeSessions->count() }} active {{ \Illuminate\Support\Str::plural('session', $order->activeSessions->count()) }}</span>@endif
    </div>

    @if ($order->activeSessions->isNotEmpty())
        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            @foreach ($order->activeSessions as $session)
                <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-5"><div class="flex items-center gap-2 text-indigo-800"><span class="size-2.5 rounded-full bg-indigo-600 ring-4 ring-indigo-100"></span><p class="text-xs font-bold uppercase tracking-[0.16em]">Active Work</p></div><p class="mt-3 text-lg font-semibold text-slate-900">{{ $session->personnel->user->name }}</p><p class="mt-1 text-sm text-slate-600">{{ $order->work_order_number }} · Started {{ $session->started_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</p><p class="mt-2 text-sm font-medium text-indigo-800">Elapsed {{ $session->started_at->diffForHumans(now(), true) }}</p></div>
            @endforeach
        </div>
    @elseif ($order->status === \App\Enums\WorkOrderStatus::ForContinuation)
        <p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Work is ready to continue in a new session.</p>
    @elseif ($order->status === \App\Enums\WorkOrderStatus::WaitingForMaterials)
        <p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Work is waiting for required materials or resources.</p>
    @elseif ($order->status === \App\Enums\WorkOrderStatus::NeedsInvestigation)
        <p class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">Further investigation is required before work can resume.</p>
    @elseif ($order->sessions->isEmpty())
        <p class="mt-4 text-sm text-slate-500">No work session has started yet.</p>
    @endif

    @if ($assigned && ! $mySession && in_array($order->status, [\App\Enums\WorkOrderStatus::ReadyForWork, \App\Enums\WorkOrderStatus::InProgress, \App\Enums\WorkOrderStatus::ForContinuation, \App\Enums\WorkOrderStatus::Paused, \App\Enums\WorkOrderStatus::WaitingForMaterials]))
        @can('work_orders.start_assigned')<form method="POST" action="{{ route('work-orders.start-work', $order) }}" class="mt-5">@csrf<button class="btn btn-success min-h-12 w-full sm:w-auto" type="submit"><x-icon name="play" />Start Work</button></form>@endcan
    @endif

    @if ($mySession && $order->status === \App\Enums\WorkOrderStatus::InProgress)
        <div id="field-actions" class="mt-6 grid scroll-mt-24 gap-4 xl:grid-cols-2">
            <details class="rounded-xl border border-blue-200 bg-blue-50/50 p-4" open>
                <summary class="cursor-pointer font-semibold text-slate-900">Add work update</summary>
                <form method="POST" enctype="multipart/form-data" action="{{ route('work-orders.execution.update', [$order, $mySession]) }}" class="mt-4 space-y-4">@csrf
                    <label>Update type<select name="type">@foreach (['BEFORE' => 'Before / Initial Evidence', 'PROGRESS' => 'Progress', 'ISSUE' => 'Issue', 'COMPLETION' => 'Completion / Outcome Evidence', 'OTHER' => 'Other'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                    <label>Work performed or finding<textarea name="description" required placeholder="Describe what you did or found."></textarea></label>
                    <label>Requester-visible summary <span class="font-normal text-slate-400">(optional)</span><textarea name="requester_summary" placeholder="A plain-language update the requester may see."></textarea></label>
                    <x-photo-picker id="work-update-{{ $mySession->id }}" title="Add Progress Evidence" help="These files will be attached to this work update." :allow-video="true" />
                    <button class="btn btn-primary w-full sm:w-auto" type="submit"><x-icon name="check-circle" />Save Update</button>
                </form>
            </details>
            <details class="rounded-xl border border-slate-200 p-4">
                <summary class="cursor-pointer font-semibold text-slate-900">End current session</summary>
                <form method="POST" enctype="multipart/form-data" action="{{ route('work-orders.execution.end', [$order, $mySession]) }}" class="mt-4 space-y-4">@csrf
                    @php($outcomeDescriptions = ['CONTINUATION' => 'More work is required in another session.', 'PAUSED' => 'Work is temporarily paused.', 'WAITING_FOR_MATERIALS' => 'Required materials or resources are not yet available.', 'NEEDS_FURTHER_INVESTIGATION' => 'The issue requires additional diagnosis.', 'CONTRIBUTION_COMPLETE' => 'Your assigned contribution is finished.', 'WORK_ORDER_COMPLETION_SUBMITTED' => 'All work is complete and ready for verification.'])
                    <fieldset><legend class="text-sm font-semibold text-slate-800">Session outcome <span class="text-rose-600">*</span></legend><div class="mt-3 grid gap-2">@foreach (\App\Enums\WorkSessionOutcome::cases() as $outcome)<label class="flex cursor-pointer gap-3 rounded-xl border border-slate-200 p-3 transition hover:border-blue-300 has-checked:border-blue-600 has-checked:bg-blue-50"><input class="mt-1" type="radio" name="outcome" value="{{ $outcome->value }}" required @checked(old('outcome') === $outcome->value)><span><span class="block font-semibold text-slate-900">{{ \Illuminate\Support\Str::of($outcome->value)->replace('_', ' ')->title() }}</span><span class="mt-1 block text-xs leading-5 text-slate-500">{{ $outcomeDescriptions[$outcome->value] }}</span></span></label>@endforeach</div></fieldset>
                    <label>Session summary<textarea name="summary" required placeholder="Explain the current state of the work."></textarea></label>
                    <label>Requester-visible update <span class="font-normal text-slate-400">(optional)</span><textarea name="requester_summary"></textarea></label>
                    <label>Remaining work <span class="font-normal text-slate-400">(for continuation)</span><textarea name="remaining_work"></textarea></label>
                    <label>Materials or resources required<input name="material_description"></label>
                    <x-photo-picker id="session-end-{{ $mySession->id }}" title="Session Evidence" help="Add photos showing the current state, issue, continuation point, or completed work." :allow-video="true" />
                    <div class="rounded-lg border border-slate-200 bg-white p-3"><p class="text-sm font-semibold">If submitting completion</p><label class="mt-3 block">Completion summary<textarea name="completion_summary"></textarea></label><label class="mt-3 block">Work performed<textarea name="work_performed_summary"></textarea></label><label class="mt-3 block">Reason no completion photo is available<textarea name="non_photo_reason"></textarea></label></div>
                    <button class="btn btn-secondary w-full sm:w-auto" type="submit"><x-icon name="clock" />End Session</button>
                </form>
            </details>
        </div>
    @endif

    @if ($assigned && ! $mySession && in_array($order->status, [\App\Enums\WorkOrderStatus::InProgress, \App\Enums\WorkOrderStatus::ForContinuation]) && ! $order->sessions->contains(fn ($session) => $session->ended_at === null))
        @can('work_orders.submit_completion')
            <details id="submit-completion" class="mt-6 scroll-mt-24 rounded-xl border border-emerald-200 bg-emerald-50/60 p-4" open><summary class="cursor-pointer font-semibold text-emerald-950">Submit completed work for verification</summary>
                <form method="POST" action="{{ route('work-orders.submit-verification', $order) }}" class="mt-4 space-y-4" data-confirm="Submit this completed work for management verification? Field work will be treated as complete unless it is returned for additional work.">@csrf<p class="text-sm text-emerald-900">Record completion evidence during a work session first, or explain why photographic evidence is not meaningful.</p><label>Completion summary<textarea name="completion_summary" required></textarea></label><label>Work performed<textarea name="work_performed_summary" required></textarea></label><label>Requester-visible summary <span class="font-normal text-slate-400">(optional)</span><textarea name="requester_summary"></textarea></label><label>Non-photographic justification <span class="font-normal text-slate-400">(if needed)</span><textarea name="non_photo_reason"></textarea></label><button class="btn btn-success w-full sm:w-auto" type="submit"><x-icon name="shield-check" />Submit for Verification</button></form>
            </details>
        @endcan
    @endif

    @if ($visibleUpdates->isNotEmpty())
        <div class="mt-7 border-t border-slate-100 pt-6"><h3 class="font-semibold text-slate-900">Work Progress</h3><ol class="mt-4">
            @foreach ($visibleUpdates as $update)
                <li class="progress-entry"><div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"><span>{{ $update->recorded_at->timezone('Asia/Manila')->format('M j · g:i A') }}</span>@if ($executionViewer)<span>• {{ $update->personnel->user->name }}</span><span class="status-badge status-neutral">{{ \Illuminate\Support\Str::of($update->type->value)->replace('_', ' ')->title() }}</span>@endif</div><p class="mt-2 leading-6 text-slate-700">{{ $executionViewer ? $update->description : $update->requester_summary }}</p>@if ($executionViewer && $update->requester_summary)<p class="mt-2 text-sm text-slate-500">Requester update: {{ $update->requester_summary }}</p>@endif @if ($update->attachments->isNotEmpty())<p class="mt-2 text-xs font-medium text-blue-700">{{ $update->attachments->count() }} evidence {{ \Illuminate\Support\Str::plural('file', $update->attachments->count()) }}</p>@endif</li>
            @endforeach
        </ol></div>
    @else
        <p class="mt-6 border-t border-slate-100 pt-5 text-sm text-slate-500">No progress updates are available yet.</p>
    @endif

    @if ($executionViewer && $order->sessions->isNotEmpty())
        <details class="mt-6 border-t border-slate-100 pt-5"><summary class="cursor-pointer text-sm font-semibold text-blue-800">View session history ({{ $order->sessions->count() }})</summary><div class="mt-3 space-y-3">
            @foreach ($order->sessions->sortByDesc('started_at') as $session)
                <article class="rounded-xl border border-slate-200 p-4"><p class="font-semibold">{{ $session->personnel->user->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $session->started_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }} → {{ $session->ended_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? 'Active' }}</p>@if ($session->session_summary)<p class="mt-2 text-sm text-slate-700">{{ $session->session_summary }}</p>@endif @if ($session->ended_at === null && auth()->user()->can('work_orders.manage_sessions'))<form method="POST" action="{{ route('work-orders.execution.force-end', [$order, $session]) }}" class="mt-3 flex flex-col gap-2 sm:flex-row" data-confirm="Administratively end this active session? The provided reason will be recorded.">@csrf<input name="reason" required placeholder="Administrative reason"><button class="btn btn-danger shrink-0" type="submit">Resolve Stuck Session</button></form>@endif</article>
            @endforeach
        </div></details>
    @endif

    @if ($order->status === \App\Enums\WorkOrderStatus::NeedsInvestigation)
        @can('work_orders.resolve_assessment')<form method="POST" action="{{ route('work-orders.release-investigation', $order) }}" class="mt-6 border-t border-slate-100 pt-5">@csrf<label>Investigation resolution<textarea name="note" required></textarea></label><button class="btn btn-primary mt-3" type="submit">Release for Work</button></form>@endcan
    @endif
</section>
