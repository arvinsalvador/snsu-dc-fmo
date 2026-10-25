<?php

namespace App\Http\Controllers\WorkOrders;

use App\Enums\AssessmentOutcome;
use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Services\WorkOrderAssessmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkOrderAssessmentController extends Controller
{
    public function queue(Request $request)
    {
        abort_unless($request->user()->can('work_orders.view_assessments'), 403);
        $priority = strtoupper(trim((string) $request->query('priority', '')));
        $assigned = (string) $request->query('assigned', '');
        $sort = strtolower(trim((string) $request->query('sort', 'oldest_waiting')));
        $perPage = $request->integer('per_page', 20);
        $filters = [
            'search' => mb_substr(trim((string) $request->query('search', '')), 0, 100),
            'category' => (string) $request->query('category', ''),
            'priority' => in_array($priority, ['NORMAL', 'URGENT'], true) ? $priority : '',
            'assigned' => in_array($assigned, ['7', '30', '90'], true) ? $assigned : '',
            'sort' => in_array($sort, ['oldest_waiting', 'newest_waiting', 'highest_priority'], true) ? $sort : 'oldest_waiting',
            'per_page' => in_array($perPage, [20, 50, 100], true) ? $perPage : 20,
        ];
        $base = WorkOrder::query()->where('status', 'ASSESSMENT_REVIEW');
        $summary = [
            'awaiting' => (clone $base)->count(),
            'assessed_today' => (clone $base)->whereHas('assessments', fn (Builder $query) => $query->whereDate('assessed_at', now('Asia/Manila')->toDateString()))->count(),
        ];
        $orders = $base->with([
            'requester', 'category', 'building', 'floor', 'location',
            'activeAssignments.personnel.user', 'assessments.personnel.user',
        ])->withMax('assessments', 'assessed_at')
            ->withCasts(['assessments_max_assessed_at' => 'datetime'])
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
            ->when($filters['category'], fn (Builder $query, string $value) => $query->where('work_order_category_id', $value))
            ->when($filters['priority'], fn (Builder $query, string $value) => $query->where('urgency', $value))
            ->when($filters['assigned'], fn (Builder $query, string $days) => $query->whereHas('activeAssignments', fn (Builder $assignment) => $assignment->where('assigned_at', '>=', now()->subDays((int) $days))));
        match ($filters['sort']) {
            'newest_waiting' => $orders->orderByDesc('assessments_max_assessed_at')->orderByDesc('id'),
            'highest_priority' => $orders->orderByRaw("CASE WHEN urgency = 'URGENT' THEN 0 ELSE 1 END")->orderBy('assessments_max_assessed_at'),
            default => $orders->orderBy('assessments_max_assessed_at')->orderBy('id'),
        };
        $orders = $orders->paginate($filters['per_page'])->appends(array_filter($filters, fn (mixed $value): bool => $value !== '' && $value !== null));

        return view('work-orders.assessment-queue', [
            'orders' => $orders,
            'filters' => $filters,
            'categories' => WorkOrderCategory::query()->where('is_active', true)->orderBy('name')->get(),
            'summary' => $summary,
            'hasFilters' => collect($filters)->except(['sort', 'per_page'])->filter()->isNotEmpty() || $filters['sort'] !== 'oldest_waiting',
        ]);
    }

    public function acknowledge(Request $request, WorkOrder $workOrder, WorkOrderAssessmentService $assessments)
    {
        $assessments->acknowledge($workOrder, $request->user());

        return back()->with('success', 'Assessment acknowledged.');
    }

    public function store(Request $request, WorkOrder $workOrder, WorkOrderAssessmentService $assessments)
    {
        $data = $request->validate(['outcome' => ['required', Rule::enum(AssessmentOutcome::class)], 'findings' => ['required', 'string', 'max:10000'], 'resource_notes' => ['nullable', 'string', 'max:5000'], 'evidence' => ['array', 'max:'.config('work_orders.attachments.max_count')], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')]]);
        $assessments->submit($workOrder, $request->user(), $data, $request->file('evidence', []));

        return back()->with('success', 'Assessment recorded.');
    }

    public function resolve(Request $request, WorkOrder $workOrder, string $decision, WorkOrderAssessmentService $assessments)
    {
        $data = $request->validate(['internal_note' => ['nullable', 'string', 'max:5000'], 'requester_message' => ['nullable', 'string', 'max:5000']]);
        $assessments->resolve($workOrder, $request->user(), $decision, $data['internal_note'] ?? null, $data['requester_message'] ?? null);

        return back()->with('success', 'Assessment review updated.');
    }
}
