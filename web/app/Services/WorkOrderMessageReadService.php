<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMessage;
use App\Models\WorkOrderMessageRead;
use Illuminate\Support\Collection;

class WorkOrderMessageReadService
{
    public function unreadCount(User $user, WorkOrder $workOrder): int
    {
        $lastReadAt = WorkOrderMessageRead::query()
            ->where('work_order_id', $workOrder->id)
            ->where('user_id', $user->id)
            ->value('last_read_at');

        return WorkOrderMessage::query()
            ->where('work_order_id', $workOrder->id)
            ->where('sender_user_id', '!=', $user->id)
            ->when($lastReadAt, fn ($query) => $query->where('created_at', '>', $lastReadAt))
            ->count();
    }

    /** @return Collection<string, int> */
    public function unreadCounts(User $user, Collection $workOrders): Collection
    {
        $ids = $workOrders->pluck('id')->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return WorkOrderMessage::query()
            ->leftJoin('work_order_message_reads as reads', function ($join) use ($user): void {
                $join->on('reads.work_order_id', '=', 'work_order_messages.work_order_id')
                    ->where('reads.user_id', '=', $user->id);
            })
            ->whereIn('work_order_messages.work_order_id', $ids)
            ->where('work_order_messages.sender_user_id', '!=', $user->id)
            ->whereRaw("work_order_messages.created_at > COALESCE(reads.last_read_at, '1970-01-01 00:00:00')")
            ->select('work_order_messages.work_order_id')
            ->selectRaw('COUNT(*) as unread_count')
            ->groupBy('work_order_messages.work_order_id')
            ->get()
            ->pluck('unread_count', 'work_order_id')
            ->map(fn ($count): int => (int) $count);
    }

    public function markRead(User $user, WorkOrder $workOrder): void
    {
        WorkOrderMessageRead::query()->updateOrCreate(
            ['work_order_id' => $workOrder->id, 'user_id' => $user->id],
            ['last_read_at' => now()],
        );
    }
}
