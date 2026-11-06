<?php

namespace App\Notifications;

use App\Models\TransportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TransportNotice extends Notification
{
    use Queueable;

    public function __construct(private readonly TransportRequest $request, private readonly string $eventKey, private readonly string $title, private readonly string $message) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event_key' => $this->eventKey,
            'transport_request_id' => $this->request->id,
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => route('transport.show', $this->request),
        ];
    }
}
