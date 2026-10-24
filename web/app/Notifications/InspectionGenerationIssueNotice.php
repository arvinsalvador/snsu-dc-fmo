<?php

namespace App\Notifications;

use App\Models\RoutineTaskTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InspectionGenerationIssueNotice extends Notification
{
    use Queueable;

    public function __construct(private readonly RoutineTaskTemplate $template, private readonly string $periodKey) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event_key' => 'INSPECTION_NO_ACTIVE_ASSETS',
            'routine_task_template_id' => $this->template->id,
            'period_key' => $this->periodKey,
            'title' => 'Inspection schedule needs attention',
            'message' => 'No active assets match “'.$this->template->title.'”. The schedule remains active and generation will retry automatically.',
            'action_url' => route('routine-templates.edit', $this->template),
        ];
    }
}
