<?php

namespace App\Http\Controllers;

use App\Support\NotificationPresenter;
use App\Support\NotificationWorkOrderContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request, NotificationPresenter $presenter, NotificationWorkOrderContext $workOrderContext)
    {
        $tab = $request->string('tab')->value() === 'unread' ? 'unread' : 'all';
        $type = $request->string('type')->value();
        $type = array_key_exists($type, $presenter->filterOptions()) ? $type : '';
        $date = $request->string('date')->value();
        $date = in_array($date, ['7', '30', '90'], true) ? $date : '';
        $search = mb_substr(trim($request->string('search')->value()), 0, 100);

        $query = $request->user()->notifications()->latest();

        if ($tab === 'unread') {
            $query->whereNull('read_at');
        }

        if ($type !== '') {
            $events = $presenter->eventsForFilter($type);
            $query->where(function (Builder $query) use ($events): void {
                foreach ($events as $event) {
                    $query->orWhere('data->event_key', $event);
                }
            });
        }

        if ($date !== '') {
            $query->where('created_at', '>=', now()->subDays((int) $date));
        }

        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $query) use ($term, $search, $workOrderContext): void {
                $query->where('data->title', 'like', $term)
                    ->orWhere('data->message', 'like', $term)
                    ->orWhere('data->work_order_number', 'like', $term)
                    ->orWhere('data->work_order_subject', 'like', $term)
                    ->orWhere('data->requester_name', 'like', $term)
                    ->orWhereIn('data->work_order_id', $workOrderContext->matchingIds($search));
            });
        }

        $notifications = $query->paginate(self::PER_PAGE)->withQueryString();
        $workOrders = $workOrderContext->load($notifications->getCollection());
        $groupedNotifications = $notifications->getCollection()
            ->map(fn ($notification): array => [
                'notification' => $notification,
                'presentation' => $presenter->present(
                    $notification,
                    $workOrders->get((string) ($notification->data['work_order_id'] ?? '')),
                ),
            ])
            ->groupBy(fn (array $item): string => $presenter->dateGroup($item['notification']->created_at));

        return view('notifications.index', [
            'notifications' => $notifications,
            'groupedNotifications' => $groupedNotifications,
            'unreadCount' => $request->user()->unreadNotifications()->count(),
            'tab' => $tab,
            'type' => $type,
            'date' => $date,
            'search' => $search,
            'typeOptions' => $presenter->filterOptions(),
        ]);
    }

    public function read(Request $request, string $notification)
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return back()->with('success', 'Notification marked as read.');
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }
}
