<?php

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Models\FmoPersonnel;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WorkOrderAssignmentService
{
    public function __construct(private readonly WorkOrderWorkflowService $workflow) {}

    public function assignedTo(WorkOrder $order, User $user): bool
    {
        return $order->activeAssignments()->whereHas('personnel', fn ($query) => $query->where('user_id', $user->id))->exists();
    }

    public function mayManage(WorkOrder $order, User $actor, bool $reassign = false): bool
    {
        if ($actor->can($reassign ? 'work_orders.reassign' : 'work_orders.assign')) {
            return true;
        }

        return $actor->can('work_orders.assign_direct')
            && $order->requester_id === $actor->id
            && $order->workflowEvents()->where('action', 'DIRECT_AUTHORIZATION')->where('actor_id', $actor->id)->exists();
    }

    public function add(WorkOrder $order, User $actor, array $personnelIds, ?string $note = null): WorkOrder
    {
        return DB::transaction(function () use ($order, $actor, $personnelIds, $note): WorkOrder {
            $locked = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [WorkOrderStatus::Approved, WorkOrderStatus::Assigned, WorkOrderStatus::ForAssessment, WorkOrderStatus::AssessmentReview, WorkOrderStatus::ReadyForWork, WorkOrderStatus::InProgress, WorkOrderStatus::ForContinuation, WorkOrderStatus::Paused, WorkOrderStatus::WaitingForMaterials, WorkOrderStatus::NeedsInvestigation], true)) {
                throw new ConflictHttpException('This Work Order is not available for assignment. Refresh its current status.');
            }
            abort_unless($this->mayManage($locked, $actor, $locked->status !== WorkOrderStatus::Approved), 403);
            $people = FmoPersonnel::with('user', 'skills')->whereIn('id', $personnelIds)->get()->keyBy('id');
            foreach ($personnelIds as $id) {
                if (! $people->has($id) || ! $people[$id]->isAssignable()) {
                    throw ValidationException::withMessages(['personnel_ids' => 'Every selected FMO personnel member must be active and assignable.']);
                }
                if ($locked->activeAssignments()->where('fmo_personnel_id', $id)->exists()) {
                    throw ValidationException::withMessages(['personnel_ids' => 'A selected personnel member is already assigned. Refresh the assignment list.']);
                }
            }
            if ($locked->status === WorkOrderStatus::Approved) {
                $this->workflow->recordOperational($locked, $actor, 'ASSIGNED', null, 'Assigned to FMO personnel for initial assessment.');
            }
            foreach ($personnelIds as $id) {
                $locked->assignments()->create(['fmo_personnel_id' => $id, 'assigned_by_user_id' => $actor->id, 'assigned_at' => now(), 'assignment_note' => $note, 'active_marker' => 1]);
                $this->workflow->recordOperational($locked, $actor, 'ASSIGNEE_ADDED', "Assigned {$people[$id]->user->name}. ".$note);
            }

            return $locked->refresh()->load('activeAssignments.personnel.user');
        });
    }

    public function remove(WorkOrder $order, WorkOrderAssignment $assignment, User $actor, string $reason): WorkOrder
    {
        return DB::transaction(function () use ($order, $assignment, $actor, $reason): WorkOrder {
            $locked = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($assignment->work_order_id === $locked->id, 404);
            abort_unless($actor->can('work_orders.remove_assignee') || $this->mayManage($locked, $actor, true), 403);
            if (! in_array($locked->status, [WorkOrderStatus::Assigned, WorkOrderStatus::ForAssessment, WorkOrderStatus::AssessmentReview, WorkOrderStatus::ReadyForWork, WorkOrderStatus::InProgress, WorkOrderStatus::ForContinuation, WorkOrderStatus::Paused, WorkOrderStatus::WaitingForMaterials, WorkOrderStatus::NeedsInvestigation], true)) {
                throw new ConflictHttpException('Assignments cannot be changed in this Work Order state.');
            }
            $active = $locked->activeAssignments()->whereKey($assignment->id)->lockForUpdate()->first();
            if (! $active) {
                throw new ConflictHttpException('This assignment has already changed. Refresh the assignment list.');
            }
            if ($locked->activeSessions()->where('fmo_personnel_id', $active->fmo_personnel_id)->exists()) {
                throw ValidationException::withMessages(['assignment' => 'End or administratively resolve this person’s active work session before removing the assignment.']);
            }
            if ($locked->status !== WorkOrderStatus::Assigned && $locked->activeAssignments()->count() === 1) {
                throw ValidationException::withMessages(['assignment' => 'At least one assignee must remain during assessment or execution.']);
            }
            $active->update(['active_marker' => null, 'unassigned_at' => now(), 'unassigned_by_user_id' => $actor->id, 'unassignment_reason' => $reason]);
            $this->workflow->recordOperational($locked, $actor, 'ASSIGNEE_REMOVED', "Removed {$active->personnel->user->name}. Reason: {$reason}");
            if (! $locked->activeAssignments()->exists()) {
                $this->workflow->recordOperational($locked, $actor, 'UNASSIGNED', 'All assignees removed; returned for assignment.', 'Awaiting assignment to FMO personnel.');
            }

            return $locked->refresh()->load('activeAssignments.personnel.user');
        });
    }
}
