<?php

namespace App\Http\Controllers;

use App\Enums\FmoContactCategory;
use App\Enums\FmoContactStatus;
use App\Models\FmoContactThread;
use App\Models\WorkOrder;
use App\Services\CommunicationAuthorizationService;
use App\Services\CommunicationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FmoContactController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('fmo_inquiries.view_own'), 403);
        $threads = FmoContactThread::query()
            ->with(['relatedWorkOrder', 'messages' => fn ($query) => $query->reorder()->latest()->limit(1)])
            ->where('requester_user_id', $request->user()->id)
            ->latest('updated_at')->paginate(15);

        return view('communications.contact-index', ['threads' => $threads, 'management' => false]);
    }

    public function managementIndex(Request $request)
    {
        abort_unless($request->user()->can('fmo_inquiries.manage'), 403);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(FmoContactStatus::class)],
            'category' => ['nullable', Rule::enum(FmoContactCategory::class)],
            'date' => ['nullable', Rule::in(['7', '30', '90'])],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $threads = FmoContactThread::query()
            ->with(['requester.requesterProfile', 'relatedWorkOrder', 'messages' => fn ($query) => $query->reorder()->latest()->limit(1)])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('subject', 'like', $term)
                        ->orWhere('category', 'like', $term)
                        ->orWhereHas('requester', fn (Builder $user) => $user->where('name', 'like', $term))
                        ->orWhereHas('relatedWorkOrder', fn (Builder $order) => $order->where('work_order_number', 'like', $term));
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->when($filters['category'] ?? null, fn (Builder $query, $category) => $query->where('category', $category))
            ->when($filters['date'] ?? null, fn (Builder $query, $days) => $query->where('created_at', '>=', now()->subDays((int) $days)))
            ->latest('updated_at')->paginate(20)->withQueryString();

        return view('communications.contact-index', [
            'threads' => $threads,
            'management' => true,
            'filters' => $filters,
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('fmo_inquiries.create'), 403);

        return view('communications.contact-create', [
            'categories' => FmoContactCategory::cases(),
            'workOrders' => WorkOrder::query()->where('requester_id', $request->user()->id)->latest('submitted_at')->limit(50)->get(),
        ]);
    }

    public function store(Request $request, CommunicationService $communications)
    {
        abort_unless($request->user()->can('fmo_inquiries.create'), 403);
        $data = $this->validatedNewInquiry($request);
        $thread = $communications->createInquiry(
            $request->user(),
            collect($data)->only(['category', 'subject', 'related_work_order_id'])->all(),
            $data['message'] ?? null,
            $request->file('attachments', []),
        );

        return redirect()->route('contact-fmo.show', $thread)->with('success', 'Your inquiry was sent to the FMO.');
    }

    public function show(
        Request $request,
        FmoContactThread $thread,
        CommunicationAuthorizationService $authorization,
    ) {
        abort_unless($authorization->canViewContactThread($request->user(), $thread), 403);
        $messages = $thread->messages()->reorder()->with(['sender.roles', 'attachments.uploader'])
            ->latest('created_at')->latest('id')->simplePaginate(20, pageName: 'messages_page');
        $messages->setCollection($messages->getCollection()->reverse()->values());
        $thread->load('requester.requesterProfile', 'relatedWorkOrder', 'closedBy');

        return view('communications.contact-show', [
            'thread' => $thread,
            'messages' => $messages,
            'management' => $request->user()->can('fmo_inquiries.manage'),
            'canReply' => $authorization->canReplyToContactThread($request->user(), $thread),
        ]);
    }

    public function reply(
        Request $request,
        FmoContactThread $thread,
        CommunicationAuthorizationService $authorization,
        CommunicationService $communications,
    ) {
        abort_unless($authorization->canReplyToContactThread($request->user(), $thread), 403);
        $data = $this->validatedMessage($request);
        $communications->replyToInquiry(
            $thread,
            $request->user(),
            $data['message'] ?? null,
            $request->file('attachments', []),
        );
        $route = $request->user()->can('fmo_inquiries.manage') ? 'fmo-inquiries.show' : 'contact-fmo.show';

        return redirect()->route($route, $thread)->with('success', 'Reply sent.');
    }

    public function close(
        Request $request,
        FmoContactThread $thread,
        CommunicationService $communications,
    ) {
        abort_unless($request->user()->can('fmo_inquiries.close'), 403);
        $communications->closeInquiry($thread, $request->user());

        return redirect()->route('fmo-inquiries.show', $thread)->with('success', 'Inquiry closed.');
    }

    private function validatedNewInquiry(Request $request): array
    {
        $data = $request->validate([
            'category' => ['required', Rule::enum(FmoContactCategory::class)],
            'subject' => ['required', 'string', 'max:255'],
            'related_work_order_id' => ['nullable', 'ulid', Rule::exists('work_orders', 'id')->where('requester_id', $request->user()->id)],
            ...$this->messageRules(),
        ]);
        if (($data['category'] ?? null) === FmoContactCategory::ExistingWorkOrder->value && empty($data['related_work_order_id'])) {
            throw ValidationException::withMessages(['related_work_order_id' => 'Select the Work Order related to this question.']);
        }
        $this->ensureMessage($request, $data);

        return $data;
    }

    private function validatedMessage(Request $request): array
    {
        $data = $request->validate($this->messageRules());
        $this->ensureMessage($request, $data);

        return $data;
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
