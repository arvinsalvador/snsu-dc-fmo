<?php

namespace App\Notifications;

use App\Models\RoutineTaskOccurrence;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RoutineTaskNotice extends Notification
{
    use Queueable;

    public function __construct(private readonly RoutineTaskOccurrence $occurrence, private readonly string $eventKey) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $overdue = $this->eventKey === 'ROUTINE_OVERDUE';

        return [
            'event_key' => $this->eventKey, 'routine_occurrence_id' => $this->occurrence->id,
            'title' => $overdue ? 'Routine task overdue — update required' : 'Routine task due',
            'message' => $this->occurrence->template->title.' is '.($overdue ? 'overdue and requires an accountable update.' : 'due today.'),
            'action_url' => route('routine-tasks.show', $this->occurrence),
        ];
    }
}
