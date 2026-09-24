<?php

namespace App\Http\Controllers\Api;

use App\Enums\AssessmentOutcome;
use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Services\WorkOrderAssessmentService;
use App\Services\WorkOrderAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkOrderAssessmentController extends Controller
{
    public function index(Request $request, WorkOrder $workOrder, WorkOrderAssignmentService $assignments)
    {
        abort_unless($request->user()->can('work_orders.view_assessments') || ($request->user()->can('work_orders.view_assigned') && $assignments->assignedTo($workOrder, $request->user())), 403);
        $assessments = $workOrder->assessments()->with('personnel.user', 'attachments')->get();

        return response()->json(['data' => $assessments->map(fn ($assessment) => ['id' => $assessment->id, 'personnel' => $assessment->personnel->user->name, 'outcome' => $assessment->outcome->value, 'findings' => $assessment->findings, 'resource_notes' => $assessment->resource_notes, 'assessed_at' => $assessment->assessed_at, 'evidence' => $assessment->attachments->map(fn ($file) => ['id' => $file->id, 'filename' => $file->original_filename])])]);
    }

    public function acknowledge(Request $request, WorkOrder $workOrder, WorkOrderAssessmentService $assessments)
    {
        $order = $assessments->acknowledge($workOrder, $request->user());

        return response()->json(['data' => ['id' => $order->id, 'status' => $order->status->value]]);
    }

    public function store(Request $request, WorkOrder $workOrder, WorkOrderAssessmentService $assessments)
    {
        $data = $request->validate(['outcome' => ['required', Rule::enum(AssessmentOutcome::class)], 'findings' => ['required', 'string', 'max:10000'], 'resource_notes' => ['nullable', 'string', 'max:5000'], 'evidence' => ['array', 'max:'.config('work_orders.attachments.max_count')], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')]]);
        $assessment = $assessments->submit($workOrder, $request->user(), $data, $request->file('evidence', []));

        return response()->json(['data' => ['id' => $assessment->id, 'outcome' => $assessment->outcome->value, 'status' => $workOrder->fresh()->status->value]], 201);
    }

    public function resolve(Request $request, WorkOrder $workOrder, string $decision, WorkOrderAssessmentService $assessments)
    {
        $data = $request->validate(['internal_note' => ['nullable', 'string', 'max:5000'], 'requester_message' => ['nullable', 'string', 'max:5000']]);
        $order = $assessments->resolve($workOrder, $request->user(), $decision, $data['internal_note'] ?? null, $data['requester_message'] ?? null);

        return response()->json(['data' => ['id' => $order->id, 'status' => $order->status->value]]);
    }
}
