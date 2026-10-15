<?php

namespace App\Notifications;

use App\Models\AssetActionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AssetActionNotice extends Notification
{
    use Queueable;

    public function __construct(private readonly AssetActionRequest $request, private readonly string $eventKey) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $this->request->loadMissing(['asset.assetType', 'asset.building', 'requester']);
        $asset = $this->request->asset;
        $review = in_array($this->eventKey, ['ASSET_REPAIR_REQUESTED', 'ASSET_DISPOSAL_REQUESTED'], true);

        return [
            'event_key' => $this->eventKey,
            'asset_action_request_id' => $this->request->id,
            'asset_id' => $asset->id,
            'requester_name' => $this->request->requester->name,
            'title' => match ($this->eventKey) {
                'ASSET_REPAIR_REQUESTED' => 'Asset Needs Repair',
                'ASSET_DISPOSAL_REQUESTED' => 'Asset Disposal Review Required',
                'ASSET_REPAIR_APPROVED' => 'Repair approved',
                'ASSET_DISPOSAL_APPROVED' => 'Disposal approved',
                'ASSET_REQUEST_RETURNED' => 'Returned for additional information',
                default => 'Asset request updated',
            },
            'message' => $review
                ? $asset->asset_code.' · '.$asset->assetType->name.' · '.$asset->building->name.' · Reported by '.$this->request->requester->name
                : $asset->asset_code.' · '.str($this->request->decision ?: $this->request->status)->replace('_', ' ')->title(),
            'action_url' => route('assets.show', $asset),
        ];
    }
}
