<?php

namespace App\Observers;

use App\Models\WorkOrderWorkflowEvent;
use App\Services\WorkflowNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkOrderWorkflowEventObserver
{
    public function created(WorkOrderWorkflowEvent $event): void
    {
        try {
            app(WorkflowNotificationService::class)->workflow($event);
        } catch (Throwable $error) {
            Log::warning('Workflow notification fanout failed', ['event_id' => $event->id, 'exception' => $error::class]);
        }
    }
}
