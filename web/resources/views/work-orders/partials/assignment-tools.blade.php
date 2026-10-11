@if ($canAssign && in_array($order->status, [\App\Enums\WorkOrderStatus::Approved, \App\Enums\WorkOrderStatus::Assigned, \App\Enums\WorkOrderStatus::ForAssessment, \App\Enums\WorkOrderStatus::AssessmentReview, \App\Enums\WorkOrderStatus::ReadyForWork, \App\Enums\WorkOrderStatus::InProgress, \App\Enums\WorkOrderStatus::ForContinuation, \App\Enums\WorkOrderStatus::Paused, \App\Enums\WorkOrderStatus::WaitingForMaterials, \App\Enums\WorkOrderStatus::NeedsInvestigation]))
    <details id="assignment-tools" class="section-card scroll-mt-24">
        <summary class="flex items-center justify-between gap-3 text-lg font-semibold text-slate-900"><span class="flex items-center gap-2"><x-icon name="assignment" class="text-blue-700" />Manage Assignment</span><x-icon name="chevron-right" class="text-slate-400" /></summary>
        <p class="mt-3 text-sm text-slate-600">Requester preference is advisory. Select active personnel based on skills, availability and current workload.</p>
        <form method="POST" action="{{ route('work-orders.assignments.store', $order) }}" class="mt-5 space-y-4">
            @csrf
            <fieldset><legend class="text-sm font-semibold text-slate-800">Available personnel</legend><div class="mt-3 grid gap-3 md:grid-cols-2">
                @forelse ($assignablePersonnel as $person)
                    <label class="relative flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 transition hover:border-blue-400 has-checked:border-blue-600 has-checked:bg-blue-50">
                        <input class="mt-1" type="checkbox" name="personnel_ids[]" value="{{ $person->id }}">
                        <x-avatar :name="$person->user->name" />
                        <span class="min-w-0 flex-1"><span class="flex flex-wrap items-center gap-2"><span class="font-semibold text-slate-900">{{ $person->user->name }}</span>@if ($order->preferred_fmo_personnel_id === $person->id)<span class="status-badge status-open">Requester preference</span>@endif</span><span class="mt-1 block text-sm text-slate-600">{{ $person->designation ?: 'FMO Personnel' }}</span><span class="mt-2 block text-xs text-slate-500">{{ $person->skills->pluck('name')->join(', ') ?: 'No skills recorded' }} · {{ $person->active_workload_count }} active {{ \Illuminate\Support\Str::plural('assignment', $person->active_workload_count) }}</span></span>
                    </label>
                @empty
                    <x-empty-state title="No assignable personnel" description="Review personnel availability before attempting assignment." icon="users" />
                @endforelse
            </div></fieldset>
            <label>Internal assignment note <span class="text-slate-500">(optional)</span><textarea name="note"></textarea></label>
            <button class="btn btn-primary" type="submit"><x-icon name="assignment" />Assign Personnel</button>
        </form>
        @if ($order->activeAssignments->isNotEmpty() && auth()->user()->can('work_orders.remove_assignee') && $order->status !== \App\Enums\WorkOrderStatus::ReadyForWork)
            <div class="mt-6 border-t border-slate-100 pt-5"><p class="text-sm font-semibold">Current assignees</p><div class="mt-3 space-y-3">
                @foreach ($order->activeAssignments as $assignment)
                    <form method="POST" action="{{ route('work-orders.assignments.destroy', [$order, $assignment]) }}" class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(12rem,1fr)_auto]" data-confirm="Remove {{ $assignment->personnel->user->name }} from this Work Order? The reason will be recorded in the audit history.">@csrf @method('DELETE')<div class="flex items-center gap-3"><x-avatar :name="$assignment->personnel->user->name" size="sm" /><p class="text-sm font-semibold">{{ $assignment->personnel->user->name }}</p></div><label class="sr-only" for="remove-reason-{{ $assignment->id }}">Removal reason</label><input id="remove-reason-{{ $assignment->id }}" name="reason" required placeholder="Removal reason"><button class="btn btn-danger" type="submit"><x-icon name="trash" size="size-4" />Remove</button></form>
                @endforeach
            </div></div>
        @endif
    </details>
@endif
