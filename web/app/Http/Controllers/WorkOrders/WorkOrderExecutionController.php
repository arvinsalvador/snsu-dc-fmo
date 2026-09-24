<?php

namespace App\Http\Controllers\WorkOrders;

use App\Enums\WorkSessionOutcome;
use App\Enums\WorkUpdateType;
use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Models\WorkSession;
use App\Services\WorkOrderExecutionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkOrderExecutionController extends Controller
{
    public function queue(Request $request, string $queue)
    {
        abort_unless(in_array($queue, ['active', 'verification'], true), 404);
        abort_unless($request->user()->can('work_orders.view_execution'), 403);
        $statuses = $queue === 'active' ? ['IN_PROGRESS', 'FOR_CONTINUATION', 'PAUSED', 'WAITING_FOR_MATERIALS', 'NEEDS_INVESTIGATION'] : ['FOR_VERIFICATION'];
        $orders = WorkOrder::with('requester', 'category', 'building', 'activeAssignments.personnel.user', 'activeSessions.personnel.user')
            ->whereIn('status', $statuses)->latest('submitted_at')->paginate(20);

        return view('work-orders.execution-queue', compact('orders', 'queue'));
    }

    public function start(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution)
    {
        $execution->start($workOrder, $request->user());

        return back()->with('success', 'Work session started.');
    }

    public function update(Request $request, WorkOrder $workOrder, WorkSession $session, WorkOrderExecutionService $execution)
    {
        $data = $request->validate($this->updateRules());
        $execution->update($workOrder, $session, $request->user(), $data, $request->file('evidence', []));

        return back()->with('success', 'Individual work update recorded.');
    }

    public function end(Request $request, WorkOrder $workOrder, WorkSession $session, WorkOrderExecutionService $execution)
    {
        $data = $request->validate($this->endRules());
        $execution->end($workOrder, $session, $request->user(), $data, $request->file('evidence', []));

        return back()->with('success', 'Work session ended.');
    }

    public function submit(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution)
    {
        $data = $request->validate($this->completionRules());
        $execution->submit($workOrder, $request->user(), $data);

        return back()->with('success', 'Work submitted for management verification.');
    }

    public function verify(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution)
    {
        $data = $request->validate(['verification_note' => ['nullable', 'string', 'max:5000']]);
        $execution->verify($workOrder, $request->user(), $data['verification_note'] ?? null);

        return back()->with('success', 'Completion verified.');
    }

    public function returnForWork(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        $execution->returnForWork($workOrder, $request->user(), $data['reason']);

        return back()->with('success', 'Returned for additional work.');
    }

    public function releaseInvestigation(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        $execution->releaseInvestigation($workOrder, $request->user(), $data['note']);

        return back()->with('success', 'Investigation reviewed; work may resume.');
    }

    public function forceEnd(Request $request, WorkOrder $workOrder, WorkSession $session, WorkOrderExecutionService $execution)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        $execution->forceEnd($workOrder, $session, $request->user(), $data['reason']);

        return back()->with('success', 'Stuck session resolved and audited.');
    }

    public function updateRules(): array
    {
        return ['type' => ['required', Rule::enum(WorkUpdateType::class)], 'description' => ['required', 'string', 'max:10000'], 'requester_summary' => ['nullable', 'string', 'max:2000'], ...$this->evidenceRules()];
    }

    public function endRules(): array
    {
        return ['outcome' => ['required', Rule::enum(WorkSessionOutcome::class)], 'summary' => ['required', 'string', 'max:10000'], 'requester_summary' => ['nullable', 'string', 'max:2000'], 'material_description' => ['nullable', 'string', 'max:255'], 'remaining_work' => ['nullable', 'string', 'max:5000'], ...$this->completionRules(false), ...$this->evidenceRules()];
    }

    public function completionRules(bool $required = true): array
    {
        return ['completion_summary' => [$required ? 'required' : 'nullable', 'string', 'max:5000'], 'work_performed_summary' => [$required ? 'required' : 'nullable', 'string', 'max:5000'], 'non_photo_reason' => ['nullable', 'string', 'max:5000'], 'requester_summary' => ['nullable', 'string', 'max:2000']];
    }

    private function evidenceRules(): array
    {
        return ['evidence' => ['array', 'max:'.config('work_orders.attachments.max_count')], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp,mp4', 'max:'.config('work_orders.attachments.max_size_kb')]];
    }
}
