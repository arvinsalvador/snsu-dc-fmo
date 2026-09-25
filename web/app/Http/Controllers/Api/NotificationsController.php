<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    public function index(Request $request)
    {
        $page = $request->user()->notifications()->latest()->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->data['event_key'] ?? null,
                'title' => $item->data['title'] ?? '',
                'message' => $item->data['message'] ?? '',
                'work_order_id' => $item->data['work_order_id'] ?? null,
                'created_at' => $item->created_at?->toIso8601String(),
                'read_at' => $item->read_at?->toIso8601String(),
            ]),
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
