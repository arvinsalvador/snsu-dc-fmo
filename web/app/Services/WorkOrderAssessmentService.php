<?php

namespace App\Services;

use App\Enums\AssessmentOutcome;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAssessment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WorkOrderAssessmentService
{
    public function __construct(private readonly WorkOrderWorkflowService $workflow) {}

    private function assignment(WorkOrder $order, User $actor)
    {
        abort_unless($actor->can('work_orders.assess_assigned'), 403);
        $assignment = $order->activeAssignments()->whereHas('personnel', fn ($query) => $query->where('user_id', $actor->id))->first();
        abort_unless($assignment, 403);

        return $assignment;
    }

    public function acknowledge(WorkOrder $order, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($order, $actor): WorkOrder {
            $locked = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assignment($locked, $actor);
            if ($locked->status !== WorkOrderStatus::Assigned) {
                throw new ConflictHttpException('Assessment cannot be acknowledged in this state.');
            }
            $this->workflow->recordOperational($locked, $actor, 'ASSESSMENT_ACKNOWLEDGED', null, 'FMO personnel are assessing this request.');

            return $locked;
        });
    }

    /** @param array<string, mixed> $data @param array<int, UploadedFile> $files */
    public function submit(WorkOrder $order, User $actor, array $data, array $files = []): WorkOrderAssessment
    {
        return DB::transaction(function () use ($order, $actor, $data, $files): WorkOrderAssessment {
            $locked = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $assignment = $this->assignment($locked, $actor);
            if (! in_array($locked->status, [WorkOrderStatus::ForAssessment, WorkOrderStatus::ReadyForWork], true)) {
                throw new ConflictHttpException('This Work Order is not accepting assessments.');
            }
            $assessment = $locked->assessments()->create(['work_order_assignment_id' => $assignment->id, 'fmo_personnel_id' => $assignment->fmo_personnel_id, 'outcome' => $data['outcome'], 'findings' => $data['findings'], 'resource_notes' => $data['resource_notes'] ?? null, 'assessed_at' => now()]);
            foreach ($files as $file) {
                $path = $file->store('work-orders/'.$locked->id.'/assessments/'.$assessment->id, 'local');
                $assessment->attachments()->create(['work_order_id' => $locked->id, 'uploaded_by' => $actor->id, 'purpose' => 'ASSESSMENT', 'original_filename' => $file->getClientOriginalName(), 'stored_path' => $path, 'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize()]);
            }
            $this->workflow->recordOperational($locked, $actor, $data['outcome'] === AssessmentOutcome::ReadyForWork->value ? 'ASSESSMENT_READY' : 'ASSESSMENT_EXCEPTION', 'Assessment '.$data['outcome'].': '.$data['findings'], $data['outcome'] === AssessmentOutcome::ReadyForWork->value ? 'Staff assessment completed. Awaiting authorization to begin work.' : 'Staff assessment requires FMO review.');

            return $assessment;
        });
    }

    public function resolve(WorkOrder $order, User $actor, string $decision, ?string $note, ?string $message): WorkOrder
    {
        $actions = ['proceed' => 'ASSESSMENT_PROCEED', 'investigate' => 'ASSESSMENT_INVESTIGATE', 'request-information' => 'ASSESSMENT_REQUEST_INFORMATION', 'hold-materials' => 'ASSESSMENT_HOLD_MATERIALS', 'refer-external' => 'ASSESSMENT_REFER_EXTERNAL', 'beyond-scope' => 'ASSESSMENT_BEYOND_SCOPE', 'cancel' => 'ASSESSMENT_CANCELLED'];
        abort_unless(isset($actions[$decision]), 404);
        abort_unless($actor->can('work_orders.resolve_assessment'), 403);
        if ($decision === 'refer-external') {
            abort_unless($actor->can('work_orders.refer_external'), 403);
        }
        if ($decision === 'beyond-scope') {
            abort_unless($actor->can('work_orders.mark_beyond_scope'), 403);
        }
        if ($decision === 'cancel') {
            abort_unless($actor->can('work_orders.cancel'), 403);
        }
        if (in_array($decision, ['request-information', 'beyond-scope', 'cancel'], true) && ! filled($message)) {
            throw ValidationException::withMessages(['requester_message' => 'A requester-facing explanation is required.']);
        }
        if (in_array($decision, ['investigate', 'hold-materials', 'refer-external'], true) && ! filled($note)) {
            throw ValidationException::withMessages(['internal_note' => 'An internal explanation is required.']);
        }

        return DB::transaction(function () use ($order, $actor, $decision, $actions, $note, $message): WorkOrder {
            $locked = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->workflow->recordOperational($locked, $actor, $actions[$decision], $note, $message);
            if ($decision === 'request-information') {
                $locked->update(['information_context' => 'ASSESSMENT']);
            }
            if ($decision === 'cancel') {
                $locked->update(['decided_by' => $actor->id, 'decided_at' => now()]);
            }

            return $locked;
        });
    }
}
