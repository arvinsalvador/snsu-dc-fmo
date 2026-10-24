<?php

namespace App\Services;

use App\Enums\FmoContactStatus;
use App\Enums\WorkOrderStatus;
use App\Models\FmoContactThread;
use App\Models\User;
use App\Models\WorkOrder;

class CommunicationAuthorizationService
{
    private const CLOSED_WORK_ORDER_STATUSES = [
        WorkOrderStatus::Completed,
        WorkOrderStatus::Cancelled,
        WorkOrderStatus::Disapproved,
    ];

    public function __construct(
        private readonly WorkOrderAccessService $workOrders,
        private readonly WorkOrderAssignmentService $assignments,
    ) {}

    public function canViewMessages(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('work_orders.messages.view') && $this->workOrders->canView($user, $workOrder);
    }

    public function canSendMessage(User $user, WorkOrder $workOrder): bool
    {
        if (! $user->can('work_orders.messages.create') || ! $this->canViewMessages($user, $workOrder) || $this->workOrderIsClosed($workOrder)) {
            return false;
        }

        return $workOrder->requester_id === $user->id
            || $this->assignments->assignedTo($workOrder, $user)
            || $user->can('work_orders.messages.manage');
    }

    public function canViewInternalNotes(User $user, WorkOrder $workOrder): bool
    {
        if (! $user->can('work_orders.internal_notes.view') || ! $this->workOrders->canView($user, $workOrder)) {
            return false;
        }

        return $this->assignments->assignedTo($workOrder, $user)
            || $user->can('work_orders.messages.manage')
            || $user->hasRole('FMO Oversight');
    }

    public function canCreateInternalNote(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('work_orders.internal_notes.create')
            && $this->canViewInternalNotes($user, $workOrder)
            && ! $this->workOrderIsClosed($workOrder);
    }

    public function canViewContactThread(User $user, FmoContactThread $thread): bool
    {
        return ($thread->requester_user_id === $user->id && $user->can('fmo_inquiries.view_own'))
            || $user->can('fmo_inquiries.manage');
    }

    public function canReplyToContactThread(User $user, FmoContactThread $thread): bool
    {
        if ($thread->status === FmoContactStatus::Closed || ! $this->canViewContactThread($user, $thread)) {
            return false;
        }

        return ($thread->requester_user_id === $user->id && $user->can('fmo_inquiries.create'))
            || $user->can('fmo_inquiries.reply');
    }

    public function workOrderIsClosed(WorkOrder $workOrder): bool
    {
        return in_array($workOrder->status, self::CLOSED_WORK_ORDER_STATUSES, true);
    }
}
