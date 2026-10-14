<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\NotificationWorkOrderContext;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    public function index(Request $request, NotificationWorkOrderContext $context)
    {
        $page = $request->user()->notifications()->latest()->paginate(20);
        $workOrders = $context->load($page->getCollection());

        return response()->json([
            'data' => $page->getCollection()->map(function ($item) use ($workOrders): array {
                $workOrder = $workOrders->get($item->data['work_order_id'] ?? '');

                return [
                    'id' => $item->id,
                    'type' => $item->data['event_key'] ?? null,
                    'title' => $item->data['title'] ?? '',
                    'message' => $item->data['message'] ?? '',
                    'work_order_id' => $item->data['work_order_id'] ?? null,
                    'work_order_number' => $item->data['work_order_number'] ?? ($workOrder['number'] ?? null),
                    'work_order_subject' => $item->data['work_order_subject'] ?? ($workOrder['subject'] ?? null),
                    'requester_name' => $item->data['requester_name'] ?? ($workOrder['requester_name'] ?? null),
                    'created_at' => $item->created_at?->toIso8601String(),
                    'read_at' => $item->read_at?->toIso8601String(),
                ];
            }),
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function read(Request $request, string $notification)
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return response()->json(['status' => 'ok']);
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['status' => 'ok']);
    }
}
