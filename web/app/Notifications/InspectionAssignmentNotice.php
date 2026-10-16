<?php

namespace App\Notifications;

use App\Models\InspectionRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InspectionAssignmentNotice extends Notification
{
    use Queueable;

    public function __construct(
        private readonly InspectionRun $run,
        private readonly string $eventKey,
        private readonly int $assetCount,
        private readonly bool $removed = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $title = match ($this->eventKey) {
            'INSPECTION_OVERDUE' => 'Inspection Overdue — Update Required',
            'INSPECTION_ASSIGNMENT_UPDATED' => 'Inspection Assignment Updated',
            default => str($this->run->template->frequency->value)->title().' Inspection Due',
        };
        $message = $this->removed
            ? $this->assetCount.' '.str('asset')->plural($this->assetCount).' removed from your '.$this->run->period_key.' inspection assignment.'
            : $this->assetCount.' '.str('asset')->plural($this->assetCount).' assigned to you for '.$this->run->template->title.' · '.$this->run->period_key.'.';

        return [
            'event_key' => $this->eventKey,
            'inspection_run_id' => $this->run->id,
            'title' => $title,
            'message' => $message,
            'action_url' => route('inspections.show', $this->run),
        ];
    }
}
