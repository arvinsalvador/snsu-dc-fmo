<?php

namespace App\Http\Controllers\WorkOrders;

use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Models\WorkOrderAssignment;
use App\Models\WorkOrderCategory;
use App\Services\WorkOrderAssignmentService;
use App\Services\WorkOrderMessageReadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class WorkOrderAssignmentController extends Controller
{
    public function queue(Request $request)
    {
        abort_unless($request->user()->can('work_orders.assign') || $request->user()->can('work_orders.assign_direct'), 403);
        $orders = WorkOrder::with('requester', 'category', 'building', 'location', 'preferredPersonnel.user', 'preferredPersonnel.skills')
            ->where('status', 'APPROVED')->whereDoesntHave('activeAssignments')
            ->when(! $request->user()->can('work_orders.assign'), fn ($query) => $query->where('requester_id', $request->user()->id)->whereHas('workflowEvents', fn ($events) => $events->where('action', 'DIRECT_AUTHORIZATION')->where('actor_id', $request->user()->id)))
            ->when($request->filled('search'), fn ($query) => $query->where('work_order_number', 'like', '%'.$request->string('search')->trim()->value().'%'))
            ->latest('submitted_at')->paginate(20)->withQueryString();

        return view('work-orders.assignment-queue', compact('orders'));
    }

    public function mine(Request $request)
    {
        abort_unless($request->user()->can('work_orders.view_assigned'), 403);
        $statusOptions = [
            WorkOrderStatus::Assigned, WorkOrderStatus::ForAssessment, WorkOrderStatus::AssessmentReview,
            WorkOrderStatus::ReadyForWork, WorkOrderStatus::InProgress, WorkOrderStatus::ForContinuation,
            WorkOrderStatus::Paused, WorkOrderStatus::WaitingForMaterials, WorkOrderStatus::NeedsInvestigation,
            WorkOrderStatus::ForVerification,
        ];
        $status = strtoupper(trim((string) $request->query('status', '')));
        $urgency = strtoupper(trim((string) $request->query('priority', '')));
        $sort = strtolower(trim((string) $request->query('sort', 'newest_assigned')));
        $perPage = $request->integer('per_page', 20);
        $filters = [
            'search' => mb_substr(trim((string) $request->query('search', '')), 0, 100),
            'status' => in_array($status, array_map(fn (WorkOrderStatus $item) => $item->value, $statusOptions), true) ? $status : '',
            'priority' => in_array($urgency, ['NORMAL', 'URGENT'], true) ? $urgency : '',
            'category' => (string) $request->query('category', ''),
            'sort' => in_array($sort, ['newest_assigned', 'oldest_assigned', 'highest_priority', 'action_needed'], true) ? $sort : 'newest_assigned',
            'per_page' => in_array($perPage, [20, 50, 100], true) ? $perPage : 20,
        ];
        $base = WorkOrder::query()->activelyAssignedTo($request->user());
        $counts = (clone $base)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $summary = [
            'assigned' => $counts->sum(),
            'in_progress' => (int) ($counts[WorkOrderStatus::InProgress->value] ?? 0),
            'needs_action' => collect([WorkOrderStatus::ForAssessment, WorkOrderStatus::ReadyForWork, WorkOrderStatus::ForContinuation])
                ->sum(fn (WorkOrderStatus $item): int => (int) ($counts[$item->value] ?? 0)),
            'awaiting_verification' => (int) ($counts[WorkOrderStatus::ForVerification->value] ?? 0),
        ];
        $assignmentScope = fn (Builder $query) => $query->activeForUser($request->user());
        $orders = $base->with([
            'requester', 'category', 'building', 'floor', 'location',
            'activeAssignments.personnel.user', 'activeSessions.personnel.user',
        ])->withMax(['activeAssignments as current_user_assigned_at' => $assignmentScope], 'assigned_at')
            ->withCasts(['current_user_assigned_at' => 'datetime'])
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $term = '%'.addcslashes($filters['search'], '%_\\').'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('work_order_number', 'like', $term)
                        ->orWhere('subject', 'like', $term)
                        ->orWhereHas('requester', fn (Builder $user) => $user->where('name', 'like', $term))
                        ->orWhereHas('building', fn (Builder $building) => $building->where('name', 'like', $term))
                        ->orWhereHas('location', fn (Builder $location) => $location->where('name', 'like', $term));
                });
            })
            ->when($filters['status'], fn (Builder $query, string $value) => $query->where('status', $value))
            ->when($filters['priority'], fn (Builder $query, string $value) => $query->where('urgency', $value))
            ->when($filters['category'], fn (Builder $query, string $value) => $query->where('work_order_category_id', $value));
        match ($filters['sort']) {
            'oldest_assigned' => $orders->orderBy('current_user_assigned_at')->orderBy('id'),
            'highest_priority' => $orders->orderByRaw("CASE WHEN urgency = 'URGENT' THEN 0 ELSE 1 END")->orderByDesc('current_user_assigned_at'),
            'action_needed' => $orders->orderBy('updated_at')->orderBy('id'),
            default => $orders->orderByDesc('current_user_assigned_at')->orderByDesc('id'),
        };
        $orders = $orders->paginate($filters['per_page'])->appends(array_filter($filters, fn (mixed $value): bool => $value !== '' && $value !== null));
        if ($request->user()->can('work_orders.messages.view')) {
            $unread = app(WorkOrderMessageReadService::class)->unreadCounts($request->user(), $orders->getCollection());
            $orders->getCollection()->each(fn (WorkOrder $order) => $order->setAttribute('unread_messages_count', $unread->get($order->id, 0)));
        }

        return view('work-orders.my-tasks', [
            'orders' => $orders,
            'filters' => $filters,
            'statusOptions' => $statusOptions,
            'categories' => WorkOrderCategory::query()->where('is_active', true)->orderBy('name')->get(),
            'summary' => $summary,
            'hasFilters' => collect($filters)->except(['sort', 'per_page'])->filter()->isNotEmpty() || $filters['sort'] !== 'newest_assigned',
        ]);
    }

    public function store(Request $request, WorkOrder $workOrder, WorkOrderAssignmentService $assignments)
    {
        abort_unless($assignments->mayManage($workOrder, $request->user(), $workOrder->status !== WorkOrderStatus::Approved), 403);
        $data = $request->validate(['personnel_ids' => ['required', 'array', 'min:1', 'max:10'], 'personnel_ids.*' => ['required', 'ulid', 'distinct'], 'note' => ['nullable', 'string', 'max:2000']]);
        $assignments->add($workOrder, $request->user(), $data['personnel_ids'], $data['note'] ?? null);

        return back()->with('success', 'FMO personnel assigned.');
    }

    public function destroy(Request $request, WorkOrder $workOrder, WorkOrderAssignment $assignment, WorkOrderAssignmentService $assignments)
    {
        abort_unless($request->user()->can('work_orders.remove_assignee') || $assignments->mayManage($workOrder, $request->user(), true), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $assignments->remove($workOrder, $assignment, $request->user(), $data['reason']);

        return back()->with('success', 'FMO personnel assignment removed.');
    }
}
