@php($executionViewer = auth()->user()->can('work_orders.view_execution') || $assigned)
@php($mySession = $assigned ? $order->sessions->first(fn ($session) => $session->ended_at === null && $session->personnel?->user_id === auth()->id()) : null)
@php($visibleUpdates = $executionViewer ? $order->updates->sortByDesc('recorded_at') : $order->updates->whereNotNull('requester_summary')->sortByDesc('recorded_at'))

<section id="current-work" class="section-card scroll-mt-24">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div><p class="detail-kicker">Operational status</p><h2 class="mt-1 text-xl">Current Work</h2></div>
        @if ($order->activeSessions->isNotEmpty())<span class="status-badge status-active">{{ $order->activeSessions->count() }} actively working</span>@endif
    </div>

    @if ($order->activeSessions->isNotEmpty())
        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            @foreach ($order->activeSessions as $session)
                <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-5"><div class="flex items-center gap-2 text-indigo-800"><span class="size-2.5 rounded-full bg-indigo-600 ring-4 ring-indigo-100"></span><p class="text-xs font-bold uppercase tracking-[0.16em]">Active Work</p></div><p class="mt-3 text-lg font-semibold text-slate-900">{{ $session->personnel->user->name }}</p><p class="mt-1 text-sm text-slate-600">{{ $order->work_order_number }} · {{ $order->subject }}</p><p class="mt-2 text-sm text-slate-600">Started {{ $session->started_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</p>@if($mySession?->is($session))<a class="btn btn-primary mt-4 min-h-11 w-full sm:w-auto" href="#field-actions"><x-icon name="wrench" />Continue Working</a>@else<p class="mt-2 text-sm font-medium text-indigo-800">Working for {{ $session->started_at->diffForHumans(now(), true) }}</p>@endif</div>
            @endforeach
        </div>
    @elseif ($order->status === \App\Enums\WorkOrderStatus::ForContinuation)
        <p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Work is ready to continue.</p>
    @elseif ($order->status === \App\Enums\WorkOrderStatus::WaitingForMaterials)
        <p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Work is waiting for required materials or resources.</p>
    @elseif ($order->status === \App\Enums\WorkOrderStatus::NeedsInvestigation)
        <p class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">Further investigation is required before work can resume.</p>
    @elseif ($order->sessions->isEmpty())
        <p class="mt-4 text-sm text-slate-500">Work has not started yet.</p>
    @endif

    @if ($assigned && ! $mySession && in_array($order->status, [\App\Enums\WorkOrderStatus::ReadyForWork, \App\Enums\WorkOrderStatus::InProgress, \App\Enums\WorkOrderStatus::ForContinuation, \App\Enums\WorkOrderStatus::Paused, \App\Enums\WorkOrderStatus::WaitingForMaterials]))
        @can('work_orders.start_assigned')<form method="POST" action="{{ route('work-orders.start-work', $order) }}" class="mt-5">@csrf<button class="btn btn-success min-h-12 w-full sm:w-auto" type="submit"><x-icon name="play" />Start Work</button></form>@endcan
    @endif

    @if ($mySession && $order->status === \App\Enums\WorkOrderStatus::InProgress)
        @include('work-orders.partials.staff-action-guide')
    @endif

    @if ($assigned && ! $mySession && in_array($order->status, [\App\Enums\WorkOrderStatus::InProgress, \App\Enums\WorkOrderStatus::ForContinuation]) && ! $order->sessions->contains(fn ($session) => $session->ended_at === null))
        @can('work_orders.submit_completion')
            <details id="submit-completion" data-collapsible class="mt-6 scroll-mt-24 rounded-xl border border-emerald-200 bg-emerald-50/60 p-4"><x-collapsible-summary class="-mx-2 text-emerald-950 hover:bg-emerald-100/60">The whole job is finished</x-collapsible-summary>
                <form method="POST" action="{{ route('work-orders.submit-verification', $order) }}" class="mt-4 space-y-4" data-confirm="Is all required work finished? Submit only if no additional repair or work is required.">@csrf<p class="text-sm text-emerald-900">Use this only after everyone has stopped working and the required completion photos have been added.</p><label>Completion Summary<textarea name="completion_summary" required>{{ old('completion_summary') }}</textarea></label><label>What work was completed?<textarea name="work_performed_summary" required>{{ old('work_performed_summary') }}</textarea></label><label>If a photo cannot show the result <span class="font-normal text-slate-400">(optional)</span><textarea name="non_photo_reason">{{ old('non_photo_reason') }}</textarea></label><button class="btn btn-success min-h-12 w-full sm:w-auto" type="submit"><x-icon name="shield-check" />Submit for Verification</button></form>
            </details>
        @endcan
    @endif

    @if ($visibleUpdates->isNotEmpty())
        <div class="mt-7 border-t border-slate-100 pt-6"><h3 class="font-semibold text-slate-900">Work Progress</h3><ol class="mt-4">
            @foreach ($visibleUpdates as $update)
                @php($updateLabels = ['START' => 'Work Started', 'BEFORE' => 'Before Work', 'PROGRESS' => 'Work Progress', 'ISSUE' => 'Problem Reported', 'CONTINUATION' => 'More Work Needed', 'PAUSE' => 'Work Paused', 'WAITING_FOR_MATERIALS' => 'Waiting for Materials', 'INVESTIGATION' => 'Further Inspection Needed', 'COMPLETION' => 'Finished Work', 'OTHER' => 'Work Update'])
                <li class="progress-entry"><div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"><span>{{ $update->recorded_at->timezone('Asia/Manila')->format('M j · g:i A') }}</span>@if ($executionViewer)<span>• {{ $update->personnel->user->name }}</span><span class="status-badge status-neutral">{{ $updateLabels[$update->type->value] ?? 'Work Update' }}</span>@endif</div><p class="mt-2 leading-6 text-slate-700">{{ $executionViewer ? $update->description : $update->requester_summary }}</p>@if ($executionViewer && $update->additional_remarks)<p class="mt-2 text-sm text-slate-500">Remarks: {{ $update->additional_remarks }}</p>@endif @if ($executionViewer && $update->requester_summary)<p class="mt-2 text-sm text-slate-500">Requester update: {{ $update->requester_summary }}</p>@endif @if ($update->attachments->isNotEmpty())<p class="mt-2 text-xs font-medium text-blue-700">{{ $update->attachments->count() }} {{ \Illuminate\Support\Str::plural('photo', $update->attachments->count()) }}</p>@endif</li>
            @endforeach
        </ol></div>
    @else
        <p class="mt-6 border-t border-slate-100 pt-5 text-sm text-slate-500">No progress updates are available yet.</p>
    @endif

    @if ($executionViewer && $order->sessions->isNotEmpty())
        <details data-collapsible class="mt-6 border-t border-slate-100 pt-5"><x-collapsible-summary class="-mx-2 text-sm text-blue-800">View work activity history ({{ $order->sessions->count() }})</x-collapsible-summary><div class="mt-3 space-y-3">
            @foreach ($order->sessions->sortByDesc('started_at') as $session)
                <article class="rounded-xl border border-slate-200 p-4"><p class="font-semibold">{{ $session->personnel->user->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $session->started_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }} → {{ $session->ended_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? 'Active' }}</p>@if ($session->session_summary)<p class="mt-2 text-sm text-slate-700">{{ $session->session_summary }}</p>@endif @if ($session->ended_at === null && auth()->user()->can('work_orders.manage_sessions'))<form method="POST" action="{{ route('work-orders.execution.force-end', [$order, $session]) }}" class="mt-3 flex flex-col gap-2 sm:flex-row" data-confirm="Administratively end this active session? The provided reason will be recorded.">@csrf<input name="reason" required placeholder="Administrative reason"><button class="btn btn-danger shrink-0" type="submit">Resolve Stuck Session</button></form>@endif</article>
            @endforeach
        </div></details>
    @endif

    @if ($order->status === \App\Enums\WorkOrderStatus::NeedsInvestigation)
        @can('work_orders.resolve_assessment')<form method="POST" action="{{ route('work-orders.release-investigation', $order) }}" class="mt-6 border-t border-slate-100 pt-5">@csrf<label>Investigation resolution<textarea name="note" required></textarea></label><button class="btn btn-primary mt-3" type="submit">Release for Work</button></form>@endcan
    @endif
</section>
