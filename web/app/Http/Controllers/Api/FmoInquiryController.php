<?php

namespace App\Http\Controllers\Api;

use App\Enums\FmoContactCategory;
use App\Http\Controllers\Controller;
use App\Models\CommunicationAttachment;
use App\Models\FmoContactMessage;
use App\Models\FmoContactThread;
use App\Services\CommunicationAuthorizationService;
use App\Services\CommunicationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FmoInquiryController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('fmo_inquiries.view_own') || $request->user()->can('fmo_inquiries.manage'), 403);
        $query = FmoContactThread::query()->with('requester', 'relatedWorkOrder')->withCount('messages');
        if (! $request->user()->can('fmo_inquiries.manage')) {
            $query->where('requester_user_id', $request->user()->id);
        }
        $page = $query->latest('updated_at')->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (FmoContactThread $thread): array => $this->threadData($thread)),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function store(Request $request, CommunicationService $communications)
    {
        abort_unless($request->user()->can('fmo_inquiries.create'), 403);
        $data = $request->validate([
            'category' => ['required', Rule::enum(FmoContactCategory::class)],
            'subject' => ['required', 'string', 'max:255'],
            'related_work_order_id' => ['nullable', 'ulid', Rule::exists('work_orders', 'id')->where('requester_id', $request->user()->id)],
            ...$this->messageRules(),
        ]);
        if ($data['category'] === FmoContactCategory::ExistingWorkOrder->value && empty($data['related_work_order_id'])) {
            throw ValidationException::withMessages(['related_work_order_id' => 'Select the Work Order related to this question.']);
        }
        $this->ensureMessage($request, $data);
        $thread = $communications->createInquiry(
            $request->user(),
            collect($data)->only(['category', 'subject', 'related_work_order_id'])->all(),
            $data['message'] ?? null,
            $request->file('attachments', []),
        );

        return response()->json(['data' => $this->threadData($thread)], 201);
    }

    public function show(Request $request, FmoContactThread $thread, CommunicationAuthorizationService $authorization)
    {
        abort_unless($authorization->canViewContactThread($request->user(), $thread), 403);
        $messages = $thread->messages()->reorder()->with(['sender.roles', 'attachments'])->latest('created_at')->latest('id')->paginate(20);
        $messages->setCollection($messages->getCollection()->reverse()->values());
        $thread->load('requester', 'relatedWorkOrder');

        return response()->json([
            'data' => [
                ...$this->threadData($thread),
                'messages' => $messages->getCollection()->map(fn (FmoContactMessage $message): array => $this->messageData($message, $thread)),
                'can_reply' => $authorization->canReplyToContactThread($request->user(), $thread),
            ],
            'current_page' => $messages->currentPage(),
            'last_page' => $messages->lastPage(),
        ]);
    }

    public function reply(
        Request $request,
        FmoContactThread $thread,
        CommunicationAuthorizationService $authorization,
        CommunicationService $communications,
    ) {
        abort_unless($authorization->canReplyToContactThread($request->user(), $thread), 403);
        $data = $request->validate($this->messageRules());
        $this->ensureMessage($request, $data);
        $message = $communications->replyToInquiry($thread, $request->user(), $data['message'] ?? null, $request->file('attachments', []));

        return response()->json(['data' => $this->messageData($message, $thread)], 201);
    }

    public function close(Request $request, FmoContactThread $thread, CommunicationService $communications)
    {
        abort_unless($request->user()->can('fmo_inquiries.close'), 403);

        return response()->json(['data' => $this->threadData($communications->closeInquiry($thread, $request->user()))]);
    }

    private function threadData(FmoContactThread $thread): array
    {
        return [
            'id' => $thread->id,
            'category' => $thread->category->value,
            'category_label' => $thread->category->label(),
            'subject' => $thread->subject,
            'status' => $thread->status->value,
            'requester' => $thread->relationLoaded('requester') ? ['id' => $thread->requester->id, 'name' => $thread->requester->name] : null,
            'related_work_order' => $thread->relatedWorkOrder ? ['id' => $thread->relatedWorkOrder->id, 'number' => $thread->relatedWorkOrder->work_order_number] : null,
            'messages_count' => $thread->messages_count ?? ($thread->relationLoaded('messages') ? $thread->messages->count() : null),
            'created_at' => $thread->created_at?->toIso8601String(),
            'updated_at' => $thread->updated_at?->toIso8601String(),
            'closed_at' => $thread->closed_at?->toIso8601String(),
        ];
    }

    private function messageData(FmoContactMessage $message, FmoContactThread $thread): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'sender' => [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'role' => $message->sender_user_id === $thread->requester_user_id ? 'Requester' : ($message->sender->getRoleNames()->first() ?? 'FMO'),
            ],
            'attachments' => $message->attachments->map(fn (CommunicationAttachment $attachment): array => [
                'id' => $attachment->id,
                'filename' => $attachment->original_filename,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->file_size,
                'url' => route('api.communication-attachments.show', $attachment),
                'thumbnail_url' => route('api.communication-attachments.show', [$attachment, 'thumbnail']),
            ])->values(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function messageRules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:'.config('communications.message_max_length')],
            'attachments' => ['nullable', 'array', 'max:'.config('communications.attachments.max_count')],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('communications.attachments.max_size_kb')],
        ];
    }

    private function ensureMessage(Request $request, array $data): void
    {
        if (trim((string) ($data['message'] ?? '')) === '' && ! $request->hasFile('attachments')) {
            throw ValidationException::withMessages(['message' => 'Write a message or attach at least one photo.']);
        }
    }
}
