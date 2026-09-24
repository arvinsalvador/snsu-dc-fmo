<?php

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WorkOrderWorkflowService
{
    private const OPERATIONAL = [
        'ASSIGNED' => ['from' => [WorkOrderStatus::Approved], 'to' => WorkOrderStatus::Assigned],
        'ASSIGNEE_ADDED' => ['from' => [WorkOrderStatus::Assigned, WorkOrderStatus::ForAssessment, WorkOrderStatus::AssessmentReview], 'to' => null],
        'ASSIGNEE_REMOVED' => ['from' => [WorkOrderStatus::Assigned, WorkOrderStatus::ForAssessment, WorkOrderStatus::AssessmentReview], 'to' => null],
        'UNASSIGNED' => ['from' => [WorkOrderStatus::Assigned], 'to' => WorkOrderStatus::Approved],
        'ASSESSMENT_ACKNOWLEDGED' => ['from' => [WorkOrderStatus::Assigned], 'to' => WorkOrderStatus::ForAssessment],
        'ASSESSMENT_READY' => ['from' => [WorkOrderStatus::ForAssessment, WorkOrderStatus::ReadyForWork], 'to' => WorkOrderStatus::ReadyForWork],
        'ASSESSMENT_EXCEPTION' => ['from' => [WorkOrderStatus::ForAssessment, WorkOrderStatus::ReadyForWork], 'to' => WorkOrderStatus::AssessmentReview],
        'ASSESSMENT_PROCEED' => ['from' => [WorkOrderStatus::AssessmentReview], 'to' => WorkOrderStatus::ReadyForWork],
        'ASSESSMENT_INVESTIGATE' => ['from' => [WorkOrderStatus::AssessmentReview], 'to' => WorkOrderStatus::ForAssessment],
        'ASSESSMENT_REQUEST_INFORMATION' => ['from' => [WorkOrderStatus::AssessmentReview], 'to' => WorkOrderStatus::NeedsInformation],
        'ASSESSMENT_HOLD_MATERIALS' => ['from' => [WorkOrderStatus::AssessmentReview], 'to' => null],
        'ASSESSMENT_REFER_EXTERNAL' => ['from' => [WorkOrderStatus::AssessmentReview], 'to' => null],
        'ASSESSMENT_BEYOND_SCOPE' => ['from' => [WorkOrderStatus::AssessmentReview], 'to' => null],
        'ASSESSMENT_CANCELLED' => ['from' => [WorkOrderStatus::AssessmentReview], 'to' => WorkOrderStatus::Cancelled],
    ];

    private const ACTIONS = [
        'begin-screening' => ['permission' => 'work_orders.screen', 'from' => WorkOrderStatus::Submitted, 'to' => WorkOrderStatus::ForScreening],
        'request-information' => ['permission' => 'work_orders.request_information', 'from' => WorkOrderStatus::ForScreening, 'to' => WorkOrderStatus::NeedsInformation],
        'recommend-approval' => ['permission' => 'work_orders.recommend', 'from' => WorkOrderStatus::ForScreening, 'to' => WorkOrderStatus::ForApproval],
        'recommend-disapproval' => ['permission' => 'work_orders.recommend', 'from' => WorkOrderStatus::ForScreening, 'to' => WorkOrderStatus::ForApproval],
        'approve' => ['permission' => 'work_orders.approve', 'from' => WorkOrderStatus::ForApproval, 'to' => WorkOrderStatus::Approved],
        'disapprove' => ['permission' => 'work_orders.disapprove', 'from' => WorkOrderStatus::ForApproval, 'to' => WorkOrderStatus::Disapproved],
        'return-to-screening' => ['permission' => 'work_orders.return_to_screening', 'from' => WorkOrderStatus::ForApproval, 'to' => WorkOrderStatus::ForScreening],
        'resubmit' => ['permission' => 'work_orders.resubmit_own', 'from' => WorkOrderStatus::NeedsInformation, 'to' => WorkOrderStatus::ForScreening],
    ];

    public function recordCreation(WorkOrder $order, User $actor, bool $direct = false): void
    {
        $order->workflowEvents()->create([
            'actor_id' => $actor->id,
            'action' => $direct ? 'DIRECT_AUTHORIZATION' : 'SUBMITTED',
            'from_status' => null,
            'to_status' => $order->status->value,
            'requester_message' => $direct ? 'Direct work order created and authorized by Campus Director.' : null,
            'created_at' => now(),
        ]);
    }

    public function transition(WorkOrder $order, User $actor, string $action, ?string $internalNote = null, ?string $requesterMessage = null): WorkOrder
    {
        $this->authorize($order, $actor, $action);
        $rule = self::ACTIONS[$action] ?? null;

        return DB::transaction(function () use ($order, $actor, $action, $rule, $internalNote, $requesterMessage): WorkOrder {
            $locked = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== $rule['from']) {
                throw new ConflictHttpException('This Work Order has already changed status. Please refresh and review the latest state.');
            }

            $old = $locked->status;
            $locked->status = $action === 'resubmit' && $locked->information_context === 'ASSESSMENT' ? WorkOrderStatus::ForAssessment : $rule['to'];
            if ($action === 'resubmit') {
                $locked->information_context = null;
            }
            if (str_starts_with($action, 'recommend-')) {
                $locked->recommendation = $action === 'recommend-approval' ? 'APPROVE' : 'DISAPPROVE';
            } elseif ($action === 'return-to-screening') {
                $locked->recommendation = null;
            }
            if (in_array($action, ['approve', 'disapprove'], true)) {
                $locked->decided_by = $actor->id;
                $locked->decided_at = now();
            }
            $locked->save();
            $locked->workflowEvents()->create([
                'actor_id' => $actor->id,
                'action' => strtoupper(str_replace('-', '_', $action)),
                'from_status' => $old->value,
                'to_status' => $locked->status->value,
                'internal_note' => $internalNote,
                'requester_message' => $requesterMessage,
                'created_at' => now(),
            ]);

            return $locked;
        });
    }

    public function authorize(WorkOrder $order, User $actor, string $action): void
    {
        $rule = self::ACTIONS[$action] ?? null;
        abort_unless($rule, 404);
        abort_unless($actor->can($rule['permission']), 403);
        if (in_array($action, ['request-information', 'recommend-approval', 'recommend-disapproval'], true)) {
            abort_unless($actor->can('work_orders.screen'), 403);
        }
        if (in_array($action, ['disapprove', 'return-to-screening'], true)) {
            abort_unless($actor->can('work_orders.approve'), 403);
        }
        if ($action === 'resubmit') {
            abort_unless($order->requester_id === $actor->id, 403);
        }
    }

    /** Call inside a transaction after locking the Work Order and authorizing the actor. */
    public function recordOperational(WorkOrder $order, User $actor, string $action, ?string $internalNote = null, ?string $requesterMessage = null): WorkOrder
    {
        $rule = self::OPERATIONAL[$action] ?? null;
        abort_unless($rule, 404);
        if (! in_array($order->status, $rule['from'], true)) {
            throw new ConflictHttpException('This Work Order has already changed status. Please refresh and review the latest state.');
        }
        $previous = $order->status;
        if ($rule['to']) {
            $order->status = $rule['to'];
            $order->save();
        }
        $order->workflowEvents()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'from_status' => $previous->value,
            'to_status' => $order->status->value,
            'internal_note' => $internalNote,
            'requester_message' => $requesterMessage,
            'created_at' => now(),
        ]);

        return $order;
    }
}
