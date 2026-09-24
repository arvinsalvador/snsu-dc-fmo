<?php

namespace App\Http\Controllers\WorkOrders;

use App\Enums\AssessmentOutcome;
use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Services\WorkOrderAssessmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkOrderAssessmentController extends Controller
{
    public function queue(Request $request)
    {
        abort_unless($request->user()->can('work_orders.view_assessments'), 403);
        $orders = WorkOrder::with('requester', 'activeAssignments.personnel.user')->where('status', 'ASSESSMENT_REVIEW')->latest('submitted_at')->paginate(20);

        return view('work-orders.assessment-queue', compact('orders'));
    }

    public function acknowledge(Request $request, WorkOrder $workOrder, WorkOrderAssessmentService $assessments)
    {
        $assessments->acknowledge($workOrder, $request->user());

        return back()->with('success', 'Assessment acknowledged.');
    }

    public function store(Request $request, WorkOrder $workOrder, WorkOrderAssessmentService $assessments)
    {
        $data = $request->validate(['outcome' => ['required', Rule::enum(AssessmentOutcome::class)], 'findings' => ['required', 'string', 'max:10000'], 'resource_notes' => ['nullable', 'string', 'max:5000'], 'evidence' => ['array', 'max:'.config('work_orders.attachments.max_count')], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')]]);
        $assessments->submit($workOrder, $request->user(), $data, $request->file('evidence', []));

        return back()->with('success', 'Assessment recorded.');
    }

    public function resolve(Request $request, WorkOrder $workOrder, string $decision, WorkOrderAssessmentService $assessments)
    {
        $data = $request->validate(['internal_note' => ['nullable', 'string', 'max:5000'], 'requester_message' => ['nullable', 'string', 'max:5000']]);
        $assessments->resolve($workOrder, $request->user(), $decision, $data['internal_note'] ?? null, $data['requester_message'] ?? null);

        return back()->with('success', 'Assessment review updated.');
    }
}
