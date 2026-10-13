<?php

namespace App\View\Composers;

use App\Support\NotificationPresenter;
use App\Support\NotificationWorkOrderContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class NotificationComposer
{
    public function __construct(
        private readonly NotificationPresenter $presenter,
        private readonly NotificationWorkOrderContext $workOrderContext,
    ) {}

    public function compose(View $view): void
    {
        if (! Auth::check()) {
            return;
        }

        $user = Auth::user();
        $unreadCount = $view->getData()['unreadCount'] ?? $user->unreadNotifications()->count();
        $recentNotifications = $user->notifications()->latest()->limit(5)->get();
        $workOrders = $this->workOrderContext->load($recentNotifications);
        $recent = $recentNotifications->map(
            fn ($notification): array => [
                'notification' => $notification,
                'presentation' => $this->presenter->present(
                    $notification,
                    $workOrders->get((string) ($notification->data['work_order_id'] ?? '')),
                ),
            ]
        );

        $view->with([
            'headerUnreadNotificationCount' => $unreadCount,
            'headerNotifications' => $recent,
        ]);
    }
}
