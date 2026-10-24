<?php

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderAccessService
{
    public function __construct(private readonly WorkOrderAssignmentService $assignments) {}

    public function canView(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->requester_id === $user->id
            || $user->can('work_orders.view_all')
            || $user->can('work_orders.view_execution')
            || ($user->can('work_orders.view_assigned') && $this->assignments->assignedTo($workOrder, $user))
            || ($this->assignments->mayManage($workOrder, $user) && in_array($workOrder->status, [WorkOrderStatus::Approved, WorkOrderStatus::Assigned, WorkOrderStatus::ForAssessment, WorkOrderStatus::AssessmentReview, WorkOrderStatus::ReadyForWork], true))
            || ($user->can('work_orders.screen') && in_array($workOrder->status, [WorkOrderStatus::Submitted, WorkOrderStatus::ForScreening, WorkOrderStatus::NeedsInformation, WorkOrderStatus::ForApproval], true))
            || ($user->can('work_orders.approve') && $workOrder->status === WorkOrderStatus::ForApproval);
    }
}
