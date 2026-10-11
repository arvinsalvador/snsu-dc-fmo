@if ($canAssign && in_array($order->status, [\App\Enums\WorkOrderStatus::Approved, \App\Enums\WorkOrderStatus::Assigned, \App\Enums\WorkOrderStatus::ForAssessment, \App\Enums\WorkOrderStatus::AssessmentReview, \App\Enums\WorkOrderStatus::ReadyForWork, \App\Enums\WorkOrderStatus::InProgress, \App\Enums\WorkOrderStatus::ForContinuation, \App\Enums\WorkOrderStatus::Paused, \App\Enums\WorkOrderStatus::WaitingForMaterials, \App\Enums\WorkOrderStatus::NeedsInvestigation]))
    <details id="assignment-tools" class="section-card scroll-mt-24">
        <summary class="cursor-pointer text-lg font-semibold text-slate-900">Manage assignment</summary>
        <p class="mt-3 text-sm text-slate-600">Requester preference is advisory. Assign active personnel based on skills and workload.</p>
        <form method="POST" action="{{ route('work-orders.assignments.store', $order) }}" class="mt-4 space-y-3">@csrf
            <label>Add personnel<select name="personnel_ids[]" multiple required>@foreach ($assignablePersonnel as $person)<option value="{{ $person->id }}">{{ $person->user->name }} — {{ $person->designation }} — {{ $person->skills->pluck('name')->join(', ') }} ({{ $person->active_workload_count }} active)</option>@endforeach</select></label>
            <label>Internal assignment note <span class="font-normal text-slate-400">(optional)</span><textarea name="note"></textarea></label>
            <button class="btn btn-primary" type="submit">Assign Personnel</button>
        </form>
        @if ($order->activeAssignments->isNotEmpty() && auth()->user()->can('work_orders.remove_assignee') && $order->status !== \App\Enums\WorkOrderStatus::ReadyForWork)
            <div class="mt-6 border-t border-slate-100 pt-5"><p class="text-sm font-semibold">Remove current assignee</p>
                @foreach ($order->activeAssignments as $assignment)
                    <form method="POST" action="{{ route('work-orders.assignments.destroy', [$order, $assignment]) }}" class="mt-3 grid gap-2 sm:grid-cols-[1fr_1fr_auto]">@csrf @method('DELETE')<p class="self-center text-sm font-medium">{{ $assignment->personnel->user->name }}</p><input name="reason" required placeholder="Removal reason"><button class="btn btn-danger" type="submit">Remove</button></form>
                @endforeach
            </div>
        @endif
    </details>
@endif
