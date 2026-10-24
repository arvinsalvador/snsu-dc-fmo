<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\FmoContactMessage;
use App\Models\FmoContactThread;
use App\Models\User;
use App\Models\WorkOrderInternalNote;
use App\Models\WorkOrderMessage;
use App\Notifications\CommunicationNotice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class CommunicationNotificationService
{
    public function workOrderMessage(WorkOrderMessage $message): void
    {
        $message->loadMissing('sender', 'workOrder.requester', 'workOrder.activeAssignments.personnel.user');
        $order = $message->workOrder;
        $sender = $message->sender;
        $preview = str($message->body ?: 'Photo attachment')->squish()->limit(160);
        $context = [
            'work_order_id' => $order->id,
            'work_order_number' => $order->work_order_number,
            'work_order_subject' => $order->subject,
            'requester_id' => $order->requester?->id,
            'requester_name' => $order->requester?->name,
            'requester_type' => $order->requester?->user_type,
            'communication_id' => $message->id,
        ];

        if ($sender->id === $order->requester_id) {
            $recipients = $order->activeAssignments->pluck('personnel.user')
                ->merge(User::permission('work_orders.messages.manage')->get());
        } else {
            $recipients = collect([$order->requester]);
        }

        $this->deliver($recipients, $sender, new CommunicationNotice(
            'WORK_ORDER_MESSAGE',
            'New Work Order Message',
            "{$order->work_order_number}: {$preview}",
            route('work-orders.show', $order).'?communication=messages#communications',
            $context,
        ));
    }

    public function internalNote(WorkOrderInternalNote $note): void
    {
        $note->loadMissing('author', 'workOrder.requester', 'workOrder.activeAssignments.personnel.user');
        $order = $note->workOrder;
        $recipients = $order->activeAssignments->pluck('personnel.user')
            ->merge(User::permission('work_orders.messages.manage')->get());

        $this->deliver($recipients, $note->author, new CommunicationNotice(
            'WORK_ORDER_INTERNAL_NOTE',
            'New Internal FMO Note',
            $order->work_order_number.': '.str($note->body ?: 'Photo attachment')->squish()->limit(160),
            route('work-orders.show', $order).'?communication=notes#communications',
            [
                'work_order_id' => $order->id,
                'work_order_number' => $order->work_order_number,
                'work_order_subject' => $order->subject,
                'requester_id' => $order->requester?->id,
                'requester_name' => $order->requester?->name,
                'requester_type' => $order->requester?->user_type,
                'communication_id' => $note->id,
            ],
        ));
    }

    public function inquiryMessage(FmoContactThread $thread, FmoContactMessage $message, bool $created = false): void
    {
        $thread->loadMissing('requester');
        $message->loadMissing('sender');
        $management = User::permission('fmo_inquiries.manage')->get();
        $recipients = $message->sender_user_id === $thread->requester_user_id
            ? $management
            : collect([$thread->requester]);
        $event = $created ? 'FMO_INQUIRY_CREATED' : 'FMO_INQUIRY_REPLY';
        $title = $created ? 'New FMO Inquiry' : 'FMO Inquiry Reply';
        $route = $message->sender_user_id === $thread->requester_user_id ? 'fmo-inquiries.show' : 'contact-fmo.show';

        $this->deliver($recipients, $message->sender, new CommunicationNotice(
            $event,
            $title,
            $thread->subject.': '.str($message->body ?: 'Photo attachment')->squish()->limit(160),
            route($route, $thread),
            [
                'inquiry_id' => $thread->id,
                'requester_id' => $thread->requester?->id,
                'requester_name' => $thread->requester?->name,
                'requester_type' => $thread->requester?->user_type,
                'communication_id' => $message->id,
            ],
        ));
    }

    private function deliver(Collection $recipients, User $sender, CommunicationNotice $notice): void
    {
        foreach ($recipients->filter()->unique('id') as $recipient) {
            if ($recipient->id === $sender->id || $recipient->status !== AccountStatus::Approved) {
                continue;
            }
            try {
                $recipient->notify($notice);
            } catch (Throwable $error) {
                Log::warning('Communication notification delivery failed', [
                    'recipient_id' => $recipient->id,
                    'event' => $notice->eventKey,
                    'exception' => $error::class,
                ]);
            }
        }
    }
}
