<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CommunicationNotice extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $eventKey,
        public readonly string $title,
        public readonly string $message,
        public readonly string $actionUrl,
        public readonly array $context = [],
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
            'action_url' => $this->actionUrl,
            ...$this->context,
        ];
    }
}
