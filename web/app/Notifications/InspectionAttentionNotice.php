<?php

namespace App\Notifications;

use App\Models\InspectionItem;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InspectionAttentionNotice extends Notification
{
    use Queueable;

    public function __construct(private readonly InspectionItem $item) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['event_key' => 'INSPECTION_ATTENTION', 'inspection_item_id' => $this->item->id,
            'title' => 'Inspection item needs attention',
            'message' => $this->item->asset->asset_code.' was recorded as '.str_replace('_', ' ', strtolower($this->item->result)).'.',
            'action_url' => route('inspections.show', $this->item->run)];
    }
}
