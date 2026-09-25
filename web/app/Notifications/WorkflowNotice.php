<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class WorkflowNotice extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $eventKey,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $workOrderId = null,
        public readonly ?int $workflowEventId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'event_key' => $this->eventKey,
            'title' => $this->title,
            'message' => $this->message,
            'work_order_id' => $this->workOrderId,
            'workflow_event_id' => $this->workflowEventId,
            'action_url' => $this->workOrderId ? route($this->eventKey === 'ASSIGNEE_REMOVED' ? 'reports.print' : 'work-orders.show', $this->workOrderId) : null,
        ];
    }
}
