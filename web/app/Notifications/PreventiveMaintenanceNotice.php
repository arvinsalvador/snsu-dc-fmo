<?php

namespace App\Notifications;

use App\Models\PreventiveMaintenanceOccurrence;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PreventiveMaintenanceNotice extends Notification
{
    use Queueable;

    public function __construct(private readonly PreventiveMaintenanceOccurrence $occurrence, private readonly string $eventKey) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $overdue = $this->eventKey === 'PM_OVERDUE';
        if ($this->occurrence->due_odometer_km !== null) {
            $remaining = (int) $this->occurrence->due_odometer_km - (int) $this->occurrence->asset->current_odometer_km;

            return [
                'event_key' => $this->eventKey,
                'preventive_maintenance_occurrence_id' => $this->occurrence->id,
                'title' => $overdue ? 'Preventive Maintenance Overdue' : 'Preventive Maintenance Due Soon',
                'message' => $this->occurrence->asset->name.' — '.$this->occurrence->plan_name_snapshot.($overdue
                    ? ' is overdue by '.number_format(abs($remaining)).' km.'
                    : ' is due at '.number_format((int) $this->occurrence->due_odometer_km).' km ('.number_format($remaining).' km remaining).'),
                'action_url' => route('preventive-maintenance.tasks.show', $this->occurrence),
            ];
        }

        return [
            'event_key' => $this->eventKey,
            'preventive_maintenance_occurrence_id' => $this->occurrence->id,
            'title' => $overdue ? 'Preventive Maintenance Overdue' : 'Preventive Maintenance Due',
            'message' => $this->occurrence->asset->name.' — '.$this->occurrence->plan_name_snapshot.' is '.($overdue ? 'overdue.' : 'available and due '.$this->occurrence->due_at->timezone(config('app.timezone'))->format('F j, Y').'.'),
            'action_url' => route('preventive-maintenance.tasks.show', $this->occurrence),
        ];
    }
}
