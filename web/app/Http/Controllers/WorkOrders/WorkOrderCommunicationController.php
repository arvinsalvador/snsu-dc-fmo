<?php

namespace App\Http\Controllers\WorkOrders;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Services\CommunicationAuthorizationService;
use App\Services\CommunicationService;
use App\Services\WorkOrderMessageReadService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkOrderCommunicationController extends Controller
{
    public function message(
        Request $request,
        WorkOrder $workOrder,
        CommunicationAuthorizationService $authorization,
        CommunicationService $communications,
        WorkOrderMessageReadService $reads,
    ) {
        abort_unless($authorization->canSendMessage($request->user(), $workOrder), 403);
        $data = $this->validated($request);
        $communications->sendWorkOrderMessage(
            $workOrder,
            $request->user(),
            $data['message'] ?? null,
            $request->file('attachments', []),
        );
        $reads->markRead($request->user(), $workOrder);

        return redirect()->to(route('work-orders.show', $workOrder).'?communication=messages#communications')
            ->with('success', 'Message sent.');
    }

    public function internalNote(
        Request $request,
        WorkOrder $workOrder,
        CommunicationAuthorizationService $authorization,
        CommunicationService $communications,
    ) {
        abort_unless($authorization->canCreateInternalNote($request->user(), $workOrder), 403);
        $data = $this->validated($request);
        $communications->addInternalNote(
            $workOrder,
            $request->user(),
            $data['message'] ?? null,
            $request->file('attachments', []),
        );

        return redirect()->to(route('work-orders.show', $workOrder).'?communication=notes#communications')
            ->with('success', 'Internal note added.');
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
}
