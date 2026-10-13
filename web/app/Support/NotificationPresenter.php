<?php

namespace App\Support;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

class NotificationPresenter
{
    /** @var array<string, array{label: string, events: list<string>}> */
    private const FILTERS = [
        'requests' => [
            'label' => 'Requests',
            'events' => ['SUBMITTED', 'DIRECT_AUTHORIZATION', 'APPROVE', 'DISAPPROVE'],
        ],
        'information' => [
            'label' => 'Information requests',
            'events' => ['REQUEST_INFORMATION', 'ASSESSMENT_REQUEST_INFORMATION'],
        ],
        'assignments' => [
            'label' => 'Assignments',
            'events' => ['ASSIGNED', 'ASSIGNEE_ADDED', 'ASSIGNEE_REMOVED'],
        ],
        'assessment' => [
            'label' => 'Assessment',
            'events' => ['ASSESSMENT_EXCEPTION', 'ASSESSMENT_READY', 'ASSESSMENT_PROCEED', 'WORK_NEEDS_INVESTIGATION', 'ASSESSMENT_CANCELLED'],
        ],
        'work' => [
            'label' => 'Work updates',
            'events' => ['WORK_WAITING_MATERIALS'],
        ],
        'completion' => [
            'label' => 'Completion',
            'events' => ['COMPLETION_SUBMITTED', 'COMPLETION_VERIFIED', 'COMPLETION_RETURNED'],
        ],
        'account' => [
            'label' => 'Account',
            'events' => ['REGISTRATION_APPROVED'],
        ],
    ];

    /**
     * @return array<string, string>
     */
    public function filterOptions(): array
    {
        return collect(self::FILTERS)->mapWithKeys(
            fn (array $filter, string $key): array => [$key => $filter['label']]
        )->all();
    }

    /**
     * @return list<string>
     */
    public function eventsForFilter(string $filter): array
    {
        return self::FILTERS[$filter]['events'] ?? [];
    }

    /**
     * @return array{
     *     title: string,
     *     message: string,
     *     event_label: string,
     *     icon: string,
     *     icon_classes: string,
     *     work_order_number: ?string,
     *     action_url: ?string,
     *     work_order_subject: ?string,
     *     requester_name: ?string,
     *     requester_meta: ?string,
     *     location: ?string,
     *     category: ?string,
     *     relative_time: string,
     *     exact_time: string,
     *     is_unread: bool
     * }
     */
    public function present(DatabaseNotification $notification, ?array $workOrder = null): array
    {
        $eventKey = (string) ($notification->data['event_key'] ?? '');
        $metadata = $this->metadata($eventKey);
        $message = (string) ($notification->data['message'] ?? '');
        $createdAt = $notification->created_at?->copy()->timezone(config('app.timezone'));
        $workOrderId = $notification->data['work_order_id'] ?? null;
        $requesterName = $workOrder['requester_name'] ?? $notification->data['requester_name'] ?? null;
        $requesterType = $workOrder['requester_type'] ?? $notification->data['requester_type'] ?? null;
        $requesterDepartment = $workOrder['requester_department'] ?? null;
        $location = collect([$workOrder['building'] ?? null, $workOrder['location'] ?? null])->filter()->join(' • ');

        return [
            'title' => (string) ($notification->data['title'] ?? 'Notification'),
            'message' => $message,
            'event_label' => $metadata['label'],
            'icon' => $metadata['icon'],
            'icon_classes' => $metadata['classes'],
            'work_order_number' => $workOrder['number'] ?? $notification->data['work_order_number'] ?? $this->workOrderNumberFrom($message),
            'work_order_subject' => $workOrder['subject'] ?? $notification->data['work_order_subject'] ?? null,
            'requester_name' => $workOrderId ? ($requesterName ?: 'Requester unavailable') : null,
            'requester_meta' => collect([$requesterType, $requesterDepartment])->filter()->join(' • ') ?: null,
            'location' => $location ?: null,
            'category' => $workOrder['category'] ?? null,
            'action_url' => $notification->data['action_url'] ?? null,
            'relative_time' => $createdAt?->diffForHumans() ?? '',
            'exact_time' => $createdAt?->format('F j, Y \a\t g:i A') ?? '',
            'is_unread' => $notification->read_at === null,
        ];
    }

    public function dateGroup(?Carbon $createdAt): string
    {
        if (! $createdAt) {
            return 'Earlier';
        }

        $date = $createdAt->copy()->timezone(config('app.timezone'))->startOfDay();
        $today = now(config('app.timezone'))->startOfDay();

        return match (true) {
            $date->equalTo($today) => 'Today',
            $date->equalTo($today->copy()->subDay()) => 'Yesterday',
            default => 'Earlier',
        };
    }

    /**
     * @return array{label: string, icon: string, classes: string}
     */
    private function metadata(string $eventKey): array
    {
        return match ($eventKey) {
            'SUBMITTED', 'DIRECT_AUTHORIZATION' => $this->style('Request received', 'clipboard', 'bg-blue-100 text-blue-800'),
            'APPROVE' => $this->style('Approval', 'shield-check', 'bg-emerald-100 text-emerald-800'),
            'DISAPPROVE', 'ASSESSMENT_CANCELLED' => $this->style('Decision', 'warning', 'bg-rose-100 text-rose-800'),
            'REQUEST_INFORMATION', 'ASSESSMENT_REQUEST_INFORMATION' => $this->style('Information request', 'mail', 'bg-amber-100 text-amber-800'),
            'ASSIGNED', 'ASSIGNEE_ADDED' => $this->style('Assignment', 'assignment', 'bg-indigo-100 text-indigo-800'),
            'ASSIGNEE_REMOVED' => $this->style('Assignment change', 'users', 'bg-slate-200 text-slate-700'),
            'ASSESSMENT_EXCEPTION', 'WORK_NEEDS_INVESTIGATION' => $this->style('Assessment review', 'search', 'bg-amber-100 text-amber-800'),
            'ASSESSMENT_READY', 'ASSESSMENT_PROCEED' => $this->style('Ready for work', 'wrench', 'bg-cyan-100 text-cyan-800'),
            'WORK_WAITING_MATERIALS' => $this->style('Work update', 'clock', 'bg-orange-100 text-orange-800'),
            'COMPLETION_SUBMITTED' => $this->style('Verification', 'eye', 'bg-violet-100 text-violet-800'),
            'COMPLETION_VERIFIED' => $this->style('Completed', 'check-circle', 'bg-emerald-100 text-emerald-800'),
            'COMPLETION_RETURNED' => $this->style('Additional work', 'wrench', 'bg-amber-100 text-amber-800'),
            'REGISTRATION_APPROVED' => $this->style('Account', 'user', 'bg-emerald-100 text-emerald-800'),
            default => $this->style('Update', 'bell', 'bg-slate-100 text-slate-700'),
        };
    }

    /**
     * @return array{label: string, icon: string, classes: string}
     */
    private function style(string $label, string $icon, string $classes): array
    {
        return compact('label', 'icon', 'classes');
    }

    private function workOrderNumberFrom(string $message): ?string
    {
        preg_match('/\bWO-[A-Z0-9-]+\b/i', $message, $matches);

        return $matches[0] ?? null;
    }
}
