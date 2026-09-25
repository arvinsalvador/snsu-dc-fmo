<?php

namespace App\Http\Controllers\Api;

use App\Enums\AssessmentOutcome;
use App\Enums\WorkSessionOutcome;
use App\Http\Controllers\Controller;
use App\Models\FmoPersonnel;
use App\Models\WorkOrder;
use App\Models\WorkOrderAssessment;
use App\Models\WorkSession;
use App\Models\WorkUpdate;
use App\Services\ClientOperationService;
use App\Services\WorkOrderAssessmentService;
use App\Services\WorkOrderAssignmentService;
use App\Services\WorkOrderExecutionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileSyncController extends Controller
{
    public function bootstrap(Request $request)
    {
        abort_unless($request->user()->can('work_orders.view_assigned'), 403);
        $person = FmoPersonnel::with('user')->where('user_id', $request->user()->id)->first();
        abort_unless($person && $person->isAssignable(), 403);
        $orders = WorkOrder::with('requester', 'category', 'campus', 'building', 'floor', 'location', 'activeAssignments.personnel.user', 'assessments.personnel.user', 'sessions.personnel.user', 'updates.personnel.user')
            ->whereHas('activeAssignments', fn ($query) => $query->where('fmo_personnel_id', $person->id))
            ->orderBy('id')->get();

        return response()->json(['data' => ['personnel' => ['id' => $person->id, 'user_id' => $request->user()->id, 'name' => $request->user()->name], 'orders' => $orders->map(fn ($order) => [
            'id' => $order->id, 'number' => $order->work_order_number, 'subject' => $order->subject, 'description' => $order->description, 'status' => $order->status->value, 'requester_name' => $order->requester->name, 'category' => $order->category->name, 'campus' => $order->campus->name, 'building' => $order->building->name, 'floor' => $order->floor?->name, 'location' => $order->location?->name, 'server_updated_at' => $order->updated_at?->toIso8601String(),
            'team' => $order->activeAssignments->map(fn ($assignment) => ['personnel_id' => $assignment->fmo_personnel_id, 'name' => $assignment->personnel->user->name]),
            'assessments' => $order->assessments->map(fn ($assessment) => ['id' => $assessment->id, 'personnel_id' => $assessment->fmo_personnel_id, 'outcome' => $assessment->outcome->value, 'findings' => $assessment->findings, 'resource_notes' => $assessment->resource_notes, 'assessed_at' => $assessment->assessed_at?->toIso8601String()]),
            'sessions' => $order->sessions->map(fn ($session) => ['id' => $session->id, 'personnel_id' => $session->fmo_personnel_id, 'started_at' => $session->started_at?->toIso8601String(), 'ended_at' => $session->ended_at?->toIso8601String(), 'outcome' => $session->end_outcome?->value]),
            'updates' => $order->updates->map(fn ($update) => ['id' => $update->id, 'session_id' => $update->work_session_id, 'personnel_id' => $update->fmo_personnel_id, 'type' => $update->type->value, 'description' => $update->description, 'recorded_at' => $update->recorded_at?->toIso8601String()]),
        ])->values(), 'snapshot_at' => now()->toIso8601String()]]);
    }

    public function operation(Request $request, ClientOperationService $operations, WorkOrderAssessmentService $assessments, WorkOrderExecutionService $execution)
    {
        $data = $request->validate(['client_operation_id' => ['required', 'uuid'], 'installation_id' => ['nullable', 'uuid'], 'client_created_at' => ['nullable', 'date'], 'type' => ['required', Rule::in(['ACK_ASSESSMENT', 'SUBMIT_ASSESSMENT', 'START_WORK', 'ADD_WORK_UPDATE', 'END_WORK_SESSION', 'SUBMIT_COMPLETION'])], 'work_order_id' => ['required', 'ulid'], 'payload' => ['nullable', 'array']]);
        $payload = $data['payload'] ?? [];
        $rules = match ($data['type']) {
            'ACK_ASSESSMENT', 'START_WORK' => [],
            'SUBMIT_ASSESSMENT' => ['outcome' => ['required', Rule::enum(AssessmentOutcome::class)], 'findings' => ['required', 'string', 'max:10000'], 'resource_notes' => ['nullable', 'string', 'max:5000']],
            'ADD_WORK_UPDATE' => ['session_id' => ['required', 'ulid'], 'type' => ['required', Rule::in(['BEFORE', 'PROGRESS', 'ISSUE', 'COMPLETION', 'OTHER'])], 'description' => ['required', 'string', 'max:10000'], 'requester_summary' => ['nullable', 'string', 'max:2000']],
            'END_WORK_SESSION' => ['session_id' => ['required', 'ulid'], 'outcome' => ['required', Rule::enum(WorkSessionOutcome::class)], 'summary' => ['required', 'string', 'max:10000'], 'remaining_work' => ['nullable', 'string', 'max:5000'], 'material_description' => ['nullable', 'string', 'max:255'], 'completion_summary' => ['nullable', 'string', 'max:5000'], 'work_performed_summary' => ['nullable', 'string', 'max:5000'], 'non_photo_reason' => ['nullable', 'string', 'max:5000'], 'requester_summary' => ['nullable', 'string', 'max:2000']],
            'SUBMIT_COMPLETION' => ['completion_summary' => ['required', 'string', 'max:5000'], 'work_performed_summary' => ['required', 'string', 'max:5000'], 'non_photo_reason' => ['nullable', 'string', 'max:5000'], 'requester_summary' => ['nullable', 'string', 'max:2000']],
        };
        $payload = validator($payload, $rules)->validate();
        $order = WorkOrder::findOrFail($data['work_order_id']);
        $result = $operations->process($request->user(), $data['client_operation_id'], $data['installation_id'] ?? null, $data['type'], ['work_order_id' => $order->id, ...$payload], $data['client_created_at'] ?? null, function () use ($data, $payload, $order, $request, $assessments, $execution): array {
            return match ($data['type']) {
                'ACK_ASSESSMENT' => ['status' => $assessments->acknowledge($order, $request->user())->status->value],
                'SUBMIT_ASSESSMENT' => $this->submitAssessment($assessments, $order, $request, $payload),
                'START_WORK' => $this->startWork($execution, $order, $request),
                'ADD_WORK_UPDATE' => $this->addUpdate($execution, $order, $request, $payload),
                'END_WORK_SESSION' => ['status' => $execution->end($order, WorkSession::findOrFail($payload['session_id']), $request->user(), $payload)->status->value],
                'SUBMIT_COMPLETION' => ['status' => $execution->submit($order, $request->user(), $payload)->status->value],
            };
        });

        return response()->json(['data' => $result]);
    }

    private function submitAssessment(WorkOrderAssessmentService $service, WorkOrder $order, Request $request, array $payload): array
    {
        $assessment = $service->submit($order, $request->user(), $payload);

        return ['assessment_id' => $assessment->id, 'status' => $order->fresh()->status->value];
    }

    private function startWork(WorkOrderExecutionService $service, WorkOrder $order, Request $request): array
    {
        $session = $service->start($order, $request->user());

        return ['session_id' => $session->id, 'status' => $order->fresh()->status->value];
    }

    private function addUpdate(WorkOrderExecutionService $service, WorkOrder $order, Request $request, array $payload): array
    {
        $update = $service->update($order, WorkSession::findOrFail($payload['session_id']), $request->user(), $payload);

        return ['update_id' => $update->id, 'status' => $order->fresh()->status->value];
    }

    public function media(Request $request, ClientOperationService $operations, WorkOrderAssignmentService $assignments)
    {
        $data = $request->validate(['client_operation_id' => ['required', 'uuid'], 'installation_id' => ['nullable', 'uuid'], 'client_created_at' => ['nullable', 'date'], 'target_kind' => ['required', Rule::in(['ASSESSMENT', 'UPDATE'])], 'target_id' => ['required', 'ulid'], 'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,mp4', 'max:'.config('work_orders.attachments.max_size_kb')]]);
        $file = $request->file('file');
        $fileHash = hash_file('sha256', $file->getRealPath());
        $result = $operations->process($request->user(), $data['client_operation_id'], $data['installation_id'] ?? null, 'UPLOAD_MEDIA', ['target_kind' => $data['target_kind'], 'target_id' => $data['target_id'], 'file_hash' => $fileHash], $data['client_created_at'] ?? null, function () use ($request, $data, $file, $assignments): array {
            $target = $data['target_kind'] === 'ASSESSMENT' ? WorkOrderAssessment::findOrFail($data['target_id']) : WorkUpdate::findOrFail($data['target_id']);
            $order = WorkOrder::findOrFail($target->work_order_id);
            abort_unless($assignments->assignedTo($order, $request->user()), 403);
            abort_unless($target->personnel->user_id === $request->user()->id, 403);
            if (in_array($order->status->value, ['FOR_VERIFICATION', 'COMPLETED', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['file' => 'This Work Order no longer accepts new field evidence.']);
            }
            if ($data['target_kind'] === 'ASSESSMENT' && $file->getMimeType() === 'video/mp4') {
                throw ValidationException::withMessages(['file' => 'Assessment evidence must be a photo.']);
            }
            if ($target->attachments()->count() >= config('work_orders.attachments.max_count')) {
                throw ValidationException::withMessages(['file' => 'The evidence limit for this record has been reached.']);
            }
            $path = $file->store('work-orders/'.$order->id.'/mobile/'.$target->id, 'local');
            $attachment = $target->attachments()->create(['work_order_id' => $order->id, 'work_session_id' => $target instanceof WorkUpdate ? $target->work_session_id : null, 'uploaded_by' => $request->user()->id, 'purpose' => $data['target_kind'] === 'ASSESSMENT' ? 'ASSESSMENT' : 'EXECUTION', 'evidence_type' => $data['target_kind'] === 'ASSESSMENT' ? 'ASSESSMENT' : $target->type->value, 'requester_visible' => false, 'original_filename' => $file->getClientOriginalName(), 'stored_path' => $path, 'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize()]);

            return ['attachment_id' => $attachment->id];
        });

        return response()->json(['data' => $result]);
    }
}
