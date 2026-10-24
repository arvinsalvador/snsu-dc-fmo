<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunicationAttachment;
use App\Models\WorkOrder;
use App\Models\WorkOrderInternalNote;
use App\Models\WorkOrderMessage;
use App\Services\CommunicationAuthorizationService;
use App\Services\CommunicationService;
use App\Services\WorkOrderMessageReadService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkOrderCommunicationController extends Controller
{
    public function messages(
        Request $request,
        WorkOrder $workOrder,
        CommunicationAuthorizationService $authorization,
        CommunicationService $communications,
        WorkOrderMessageReadService $reads,
    ) {
        abort_unless($authorization->canViewMessages($request->user(), $workOrder), 403);
        $page = $communications->messages($workOrder);
        $unread = $reads->unreadCount($request->user(), $workOrder);
        $reads->markRead($request->user(), $workOrder);

        return response()->json([
            'data' => $page->getCollection()->map(fn (WorkOrderMessage $message): array => $this->messageData($message, $workOrder)),
            'unread_count' => $unread,
            'current_page' => $page->currentPage(),
            'next_page_url' => $page->nextPageUrl(),
            'previous_page_url' => $page->previousPageUrl(),
            'can_send' => $authorization->canSendMessage($request->user(), $workOrder),
        ]);
    }

    public function storeMessage(
        Request $request,
        WorkOrder $workOrder,
        CommunicationAuthorizationService $authorization,
        CommunicationService $communications,
        WorkOrderMessageReadService $reads,
    ) {
        abort_unless($authorization->canSendMessage($request->user(), $workOrder), 403);
        $data = $this->validated($request);
        $message = $communications->sendWorkOrderMessage($workOrder, $request->user(), $data['message'] ?? null, $request->file('attachments', []));
        $reads->markRead($request->user(), $workOrder);

        return response()->json(['data' => $this->messageData($message, $workOrder)], 201);
    }

    public function internalNotes(
        Request $request,
        WorkOrder $workOrder,
        CommunicationAuthorizationService $authorization,
        CommunicationService $communications,
    ) {
        abort_unless($authorization->canViewInternalNotes($request->user(), $workOrder), 403);
        $page = $communications->internalNotes($workOrder);

        return response()->json([
            'data' => $page->getCollection()->map(fn (WorkOrderInternalNote $note): array => $this->noteData($note)),
            'current_page' => $page->currentPage(),
            'next_page_url' => $page->nextPageUrl(),
            'previous_page_url' => $page->previousPageUrl(),
            'can_create' => $authorization->canCreateInternalNote($request->user(), $workOrder),
        ]);
    }

    public function storeInternalNote(
        Request $request,
        WorkOrder $workOrder,
        CommunicationAuthorizationService $authorization,
        CommunicationService $communications,
    ) {
        abort_unless($authorization->canCreateInternalNote($request->user(), $workOrder), 403);
        $data = $this->validated($request);
        $note = $communications->addInternalNote($workOrder, $request->user(), $data['message'] ?? null, $request->file('attachments', []));

        return response()->json(['data' => $this->noteData($note)], 201);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:'.config('communications.message_max_length')],
            'attachments' => ['nullable', 'array', 'max:'.config('communications.attachments.max_count')],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('communications.attachments.max_size_kb')],
        ]);
        if (trim((string) ($data['message'] ?? '')) === '' && ! $request->hasFile('attachments')) {
            throw ValidationException::withMessages(['message' => 'Write a message or attach at least one photo.']);
        }

        return $data;
    }

    private function messageData(WorkOrderMessage $message, WorkOrder $workOrder): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'sender' => $this->senderData($message->sender, $workOrder),
            'attachments' => $message->attachments->map(fn (CommunicationAttachment $attachment): array => $this->attachmentData($attachment))->values(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function noteData(WorkOrderInternalNote $note): array
    {
        return [
            'id' => $note->id,
            'body' => $note->body,
            'author' => ['id' => $note->author->id, 'name' => $note->author->name, 'role' => $note->author->getRoleNames()->first() ?? 'FMO'],
            'attachments' => $note->attachments->map(fn (CommunicationAttachment $attachment): array => $this->attachmentData($attachment))->values(),
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }

    private function senderData($sender, WorkOrder $workOrder): array
    {
        return [
            'id' => $sender->id,
            'name' => $sender->name,
            'role' => $sender->id === $workOrder->requester_id ? 'Requester' : ($sender->getRoleNames()->first() ?? 'FMO'),
        ];
    }

    private function attachmentData(CommunicationAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'filename' => $attachment->original_filename,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->file_size,
            'url' => route('api.communication-attachments.show', $attachment),
            'thumbnail_url' => route('api.communication-attachments.show', [$attachment, 'thumbnail']),
        ];
    }
}
