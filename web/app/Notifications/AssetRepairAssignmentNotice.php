<?php

namespace App\Notifications;

use App\Models\AssetActionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AssetRepairAssignmentNotice extends Notification
{
    use Queueable;

    public function __construct(private readonly AssetActionRequest $action, private readonly bool $removed = false) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event_key' => 'ASSET_REPAIR_ASSIGNMENT_UPDATED',
            'asset_action_request_id' => $this->action->id,
            'title' => 'Repair Assignment Updated',
            'message' => $this->removed
                ? $this->action->asset->asset_code.' is no longer assigned to you for repair.'
                : $this->action->asset->asset_code.' has been assigned to you for repair.',
            'action_url' => route('assets.show', $this->action->asset),
        ];
    }
}
