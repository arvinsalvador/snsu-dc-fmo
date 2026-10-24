<?php

namespace App\Services;

use App\Enums\FmoContactStatus;
use App\Models\FmoContactMessage;
use App\Models\FmoContactThread;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderInternalNote;
use App\Models\WorkOrderMessage;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommunicationService
{
    public function __construct(
        private readonly StoredFileTransaction $files,
        private readonly CommunicationMediaService $media,
        private readonly CommunicationNotificationService $notifications,
    ) {}

    public function messages(WorkOrder $workOrder, int $perPage = 20): Paginator
    {
        $page = $workOrder->messages()->reorder()->with(['sender.roles', 'attachments.uploader'])
            ->latest('created_at')->latest('id')->simplePaginate($perPage, pageName: 'messages_page');
        $page->setCollection($page->getCollection()->reverse()->values());

        return $page;
    }

    public function internalNotes(WorkOrder $workOrder, int $perPage = 20): Paginator
    {
        $page = $workOrder->internalNotes()->reorder()->with(['author.roles', 'attachments.uploader'])
            ->latest('created_at')->latest('id')->simplePaginate($perPage, pageName: 'notes_page');
        $page->setCollection($page->getCollection()->reverse()->values());

        return $page;
    }

    public function sendWorkOrderMessage(WorkOrder $workOrder, User $sender, ?string $body, array $attachments): WorkOrderMessage
    {
        $message = $this->files->run(function () use ($workOrder, $sender, $body, $attachments): WorkOrderMessage {
            $message = $workOrder->messages()->create([
                'sender_user_id' => $sender->id,
                'body' => $this->body($body),
            ]);
            $this->media->store($message, $attachments, $sender, 'WORK_ORDER_MESSAGE');

            return $message;
        });
        $this->notifications->workOrderMessage($message);

        return $message->load('sender.roles', 'attachments');
    }

    public function addInternalNote(WorkOrder $workOrder, User $author, ?string $body, array $attachments): WorkOrderInternalNote
    {
        $note = $this->files->run(function () use ($workOrder, $author, $body, $attachments): WorkOrderInternalNote {
            $note = $workOrder->internalNotes()->create([
                'author_user_id' => $author->id,
                'body' => $this->body($body),
            ]);
            $this->media->store($note, $attachments, $author, 'WORK_ORDER_INTERNAL_NOTE');

            return $note;
        });
        $this->notifications->internalNote($note);

        return $note->load('author.roles', 'attachments');
    }

    public function createInquiry(User $requester, array $attributes, ?string $body, array $attachments): FmoContactThread
    {
        [$thread, $message] = $this->files->run(function () use ($requester, $attributes, $body, $attachments): array {
            $thread = FmoContactThread::create([
                ...$attributes,
                'requester_user_id' => $requester->id,
                'status' => FmoContactStatus::Open,
            ]);
            $message = $thread->messages()->create([
                'sender_user_id' => $requester->id,
                'body' => $this->body($body),
            ]);
            $this->media->store($message, $attachments, $requester, 'FMO_INQUIRY_MESSAGE');

            return [$thread, $message];
        });
        $this->notifications->inquiryMessage($thread, $message, true);

        return $thread->load('requester', 'relatedWorkOrder', 'messages.sender', 'messages.attachments');
    }

    public function replyToInquiry(FmoContactThread $thread, User $sender, ?string $body, array $attachments): FmoContactMessage
    {
        $message = $this->files->run(function () use ($thread, $sender, $body, $attachments): FmoContactMessage {
            $locked = FmoContactThread::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === FmoContactStatus::Closed) {
                throw ValidationException::withMessages(['message' => 'This inquiry is closed and is now read-only.']);
            }
            $message = $locked->messages()->create([
                'sender_user_id' => $sender->id,
                'body' => $this->body($body),
            ]);
            $locked->update([
                'status' => $sender->id === $locked->requester_user_id
                    ? FmoContactStatus::Open
                    : FmoContactStatus::Responded,
            ]);
            $this->media->store($message, $attachments, $sender, 'FMO_INQUIRY_MESSAGE');

            return $message;
        });
        $this->notifications->inquiryMessage($thread->fresh(), $message);

        return $message->load('sender.roles', 'attachments');
    }

    public function closeInquiry(FmoContactThread $thread, User $actor): FmoContactThread
    {
        return DB::transaction(function () use ($thread, $actor): FmoContactThread {
            $locked = FmoContactThread::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'status' => FmoContactStatus::Closed,
                'closed_at' => now(),
                'closed_by_user_id' => $actor->id,
            ]);

            return $locked->refresh();
        });
    }

    private function body(?string $body): ?string
    {
        $body = trim((string) $body);

        return $body === '' ? null : $body;
    }
}
