<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\RegistrationReview;
use App\Models\User;
use App\Models\WorkOrderWorkflowEvent;
use App\Notifications\WorkflowNotice;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkflowNotificationService
{
    public function workflow(WorkOrderWorkflowEvent $event): void
    {
        $order = $event->workOrder;
        $number = $order->work_order_number;
        $requester = $order->requester;
        $recipients = collect();
        $title = null;
        $message = null;

        switch ($event->action) {
            case 'SUBMITTED':
            case 'DIRECT_AUTHORIZATION':
                $title = 'Work Order received';
                $message = "{$number} was recorded.";
                $recipients->push($requester);
                if ($event->action === 'SUBMITTED') {
                    $recipients = $recipients->merge($this->withPermission('work_orders.screen'));
                }
                break;
            case 'REQUEST_INFORMATION':
            case 'ASSESSMENT_REQUEST_INFORMATION':
                $title = 'More information requested';
                $message = "{$number} needs information from you.";
                $recipients->push($requester);
                break;
            case 'APPROVE':
                $title = 'Work Order approved';
                $message = "{$number} was approved and is awaiting assignment.";
                $recipients->push($requester);
                $recipients = $recipients->merge($this->withPermission('work_orders.assign'));
                break;
            case 'DISAPPROVE':
                $title = 'Work Order disapproved';
                $message = "{$number} was disapproved. Open the request for details.";
                $recipients->push($requester);
                break;
            case 'ASSIGNED':
                $title = 'Work Order assigned';
                $message = "{$number} has been assigned to FMO personnel.";
                $recipients->push($requester);
                break;
            case 'ASSIGNEE_ADDED':
                $title = 'New field assignment';
                $message = "You were assigned to {$number}.";
                $assignment = $order->assignments()->with('personnel.user')->latest('id')->first();
                if ($assignment?->personnel?->user) {
                    $recipients->push($assignment->personnel->user);
                }
                break;
            case 'ASSIGNEE_REMOVED':
                $title = 'Assignment changed';
                $message = "Your assignment to {$number} was removed.";
                $assignment = $order->assignments()->with('personnel.user')->whereNotNull('unassigned_at')->latest('id')->first();
                if ($assignment?->personnel?->user) {
                    $recipients->push($assignment->personnel->user);
                }
                break;
            case 'ASSESSMENT_EXCEPTION':
                $title = 'Assessment review needed';
                $message = "{$number} requires management assessment review.";
                $recipients = $this->withPermission('work_orders.resolve_assessment');
                break;
            case 'ASSESSMENT_READY':
            case 'ASSESSMENT_PROCEED':
                $title = 'Ready for work';
                $message = "{$number} is ready for field work.";
                $recipients->push($requester);
                $recipients = $recipients->merge($this->assignees($order));
                break;
            case 'WORK_WAITING_MATERIALS':
                $title = 'Waiting for materials';
                $message = "{$number} is waiting for materials or resources.";
                $recipients->push($requester);
                $recipients = $recipients->merge($this->withPermission('work_orders.assign'));
                break;
            case 'WORK_NEEDS_INVESTIGATION':
                $title = 'Investigation needed';
                $message = "{$number} needs further investigation.";
                $recipients = $this->withPermission('work_orders.resolve_assessment');
                break;
            case 'COMPLETION_SUBMITTED':
                $title = 'Verification needed';
                $message = "{$number} was submitted for completion verification.";
                $recipients->push($requester);
                $recipients = $recipients->merge($this->withPermission('work_orders.verify_completion'));
                break;
            case 'COMPLETION_VERIFIED':
                $title = 'Work Order completed';
                $message = "{$number} was verified and completed.";
                $recipients->push($requester);
                $recipients = $recipients->merge($this->assignees($order));
                break;
            case 'COMPLETION_RETURNED':
                $title = 'Additional work requested';
                $message = "{$number} was returned for additional work.";
                $recipients->push($requester);
                $recipients = $recipients->merge($this->assignees($order));
                break;
            case 'ASSESSMENT_CANCELLED':
                $title = 'Work Order cancelled';
                $message = "{$number} was cancelled. Open the request for details.";
                $recipients->push($requester);
                break;
        }

        if ($title === null) {
            return;
        }
        foreach ($recipients->filter()->unique('id') as $recipient) {
            if ($recipient->status !== AccountStatus::Approved) {
                continue;
            }
            $this->deliver($recipient, new WorkflowNotice($event->action, $title, $message, $order->id, $event->id));
        }
    }

    public function registration(RegistrationReview $review): void
    {
        if ($review->action !== 'approved') {
            return;
        }
        $this->deliver($review->user, new WorkflowNotice('REGISTRATION_APPROVED', 'Registration approved', 'Your account is approved. You can now use the Work Order system.'));
    }

    private function withPermission(string $permission)
    {
        return User::permission($permission)->where('status', AccountStatus::Approved->value)->get();
    }

    private function assignees($order)
    {
        return $order->activeAssignments()->with('personnel.user')->get()->pluck('personnel.user');
    }

    private function deliver(User $recipient, WorkflowNotice $notice): void
    {
        try {
            if ($notice->workflowEventId && $recipient->notifications()->where('data->workflow_event_id', $notice->workflowEventId)->exists()) {
                return;
            }
            $recipient->notify($notice);
        } catch (Throwable $error) {
            Log::warning('In-app notification delivery failed', ['recipient_id' => $recipient->id, 'event' => $notice->eventKey, 'exception' => $error::class]);
        }
    }
}
