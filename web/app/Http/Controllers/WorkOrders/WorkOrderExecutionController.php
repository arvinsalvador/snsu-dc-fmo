<?php

namespace App\Http\Controllers\WorkOrders;

use App\Enums\WorkSessionOutcome;
use App\Enums\WorkUpdateType;
use App\Http\Controllers\Controller;
use App\Models\FmoPersonnel;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Models\WorkSession;
use App\Services\WorkOrderExecutionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkOrderExecutionController extends Controller
{
    public function queue(Request $request, string $queue)
    {
        abort_unless(in_array($queue, ['active', 'verification'], true), 404);
        abort_unless($request->user()->can('work_orders.view_execution'), 403);
        $statuses = $queue === 'active' ? ['IN_PROGRESS', 'FOR_CONTINUATION', 'PAUSED', 'WAITING_FOR_MATERIALS', 'NEEDS_INVESTIGATION'] : ['FOR_VERIFICATION'];
        $priority = strtoupper(trim((string) $request->query('priority', '')));
        $submitted = (string) $request->query('submitted', '');
        $defaultSort = $queue === 'active' ? 'latest_activity' : 'oldest_waiting';
        $sort = strtolower(trim((string) $request->query('sort', $defaultSort)));
        $perPage = $request->integer('per_page', 20);
        $filters = [
            'search' => mb_substr(trim((string) $request->query('search', '')), 0, 100),
            'category' => (string) $request->query('category', ''),
            'personnel' => (string) $request->query('personnel', ''),
            'priority' => in_array($priority, ['NORMAL', 'URGENT'], true) ? $priority : '',
            'submitted' => in_array($submitted, ['7', '30', '90'], true) ? $submitted : '',
            'sort' => in_array($sort, ['latest_activity', 'oldest_activity', 'oldest_waiting', 'newest_waiting', 'highest_priority'], true) ? $sort : $defaultSort,
            'per_page' => in_array($perPage, [20, 50, 100], true) ? $perPage : 20,
        ];
        $base = WorkOrder::query()->whereIn('status', $statuses);
        $summary = $queue === 'active'
            ? [
                'jobs' => (clone $base)->count(),
                'sessions' => WorkSession::query()->whereNull('ended_at')->whereHas('workOrder', fn (Builder $query) => $query->whereIn('status', $statuses))->count(),
            ]
            : [
                'waiting' => (clone $base)->count(),
                'submitted_today' => (clone $base)->whereBetween('completion_submitted_at', [now('Asia/Manila')->startOfDay()->utc(), now('Asia/Manila')->endOfDay()->utc()])->count(),
            ];
        $orders = $base->with([
            'requester', 'category', 'building', 'floor', 'location',
            'activeAssignments.personnel.user', 'activeSessions.personnel.user',
            'updates' => fn ($query) => $query->reorder()->latest('recorded_at')->limit(1),
        ])->withCount([
            'activeSessions',
            'attachments as completion_evidence_count' => fn (Builder $query) => $query->where('purpose', 'EXECUTION')->where('evidence_type', 'COMPLETION'),
        ])->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
            $term = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(function (Builder $query) use ($term): void {
                $query->where('work_order_number', 'like', $term)
                    ->orWhere('subject', 'like', $term)
                    ->orWhereHas('requester', fn (Builder $user) => $user->where('name', 'like', $term))
                    ->orWhereHas('building', fn (Builder $building) => $building->where('name', 'like', $term))
                    ->orWhereHas('location', fn (Builder $location) => $location->where('name', 'like', $term));
            });
        })->when($filters['category'], fn (Builder $query, string $value) => $query->where('work_order_category_id', $value))
            ->when($filters['personnel'], fn (Builder $query, string $value) => $query->whereHas('activeAssignments', fn (Builder $assignment) => $assignment->where('fmo_personnel_id', $value)))
            ->when($filters['priority'], fn (Builder $query, string $value) => $query->where('urgency', $value))
            ->when($queue === 'verification' && $filters['submitted'], fn (Builder $query) => $query->where('completion_submitted_at', '>=', now()->subDays((int) $filters['submitted'])));
        match ($filters['sort']) {
            'oldest_activity' => $orders->orderBy('updated_at')->orderBy('id'),
            'oldest_waiting' => $orders->orderBy('completion_submitted_at')->orderBy('id'),
            'newest_waiting' => $orders->orderByDesc('completion_submitted_at')->orderByDesc('id'),
            'highest_priority' => $orders->orderByRaw("CASE WHEN urgency = 'URGENT' THEN 0 ELSE 1 END")->orderBy('updated_at'),
            default => $orders->orderByDesc('updated_at')->orderByDesc('id'),
        };
        $orders = $orders->paginate($filters['per_page'])->appends(array_filter($filters, fn (mixed $value): bool => $value !== '' && $value !== null));

        return view('work-orders.execution-queue', [
            'orders' => $orders,
            'queue' => $queue,
            'filters' => $filters,
            'categories' => WorkOrderCategory::query()->where('is_active', true)->orderBy('name')->get(),
            'personnel' => FmoPersonnel::query()->with('user')->where('personnel_status', 'ACTIVE')->whereNull('archived_at')->get()->sortBy('user.name')->values(),
            'summary' => $summary,
            'hasFilters' => collect($filters)->except(['sort', 'per_page'])->filter()->isNotEmpty() || $filters['sort'] !== $defaultSort,
        ]);
    }

    public function start(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution)
    {
        $execution->start($workOrder, $request->user());

        return back()->with('success', 'Work session started.');
    }

    public function update(Request $request, WorkOrder $workOrder, WorkSession $session, WorkOrderExecutionService $execution)
    {
        $data = $request->validate($this->updateRules(), $this->friendlyMessages(), $this->friendlyAttributes());
        $execution->update($workOrder, $session, $request->user(), $data, $request->file('evidence', []));

        return back()->with('success', 'Work progress saved.');
    }

    public function end(Request $request, WorkOrder $workOrder, WorkSession $session, WorkOrderExecutionService $execution)
    {
        $data = $request->validate($this->endRules(), $this->friendlyMessages(), $this->friendlyAttributes());
        $execution->end($workOrder, $session, $request->user(), $data, $request->file('evidence', []));

        return back()->with('success', 'Your work status was saved.');
    }

    public function submit(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution)
    {
        $data = $request->validate($this->completionRules(), $this->friendlyMessages(), $this->friendlyAttributes());
        $execution->submit($workOrder, $request->user(), $data);

        return back()->with('success', 'Work submitted for management verification.');
    }

    public function verify(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution)
    {
        $data = $request->validate(['verification_note' => ['nullable', 'string', 'max:5000']]);
        $execution->verify($workOrder, $request->user(), $data['verification_note'] ?? null);

        return back()->with('success', 'Completion verified.');
    }

    public function returnForWork(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        $execution->returnForWork($workOrder, $request->user(), $data['reason']);

        return back()->with('success', 'Returned for additional work.');
    }

    public function releaseInvestigation(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        $execution->releaseInvestigation($workOrder, $request->user(), $data['note']);

        return back()->with('success', 'Investigation reviewed; work may resume.');
    }

    public function forceEnd(Request $request, WorkOrder $workOrder, WorkSession $session, WorkOrderExecutionService $execution)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        $execution->forceEnd($workOrder, $session, $request->user(), $data['reason']);

        return back()->with('success', 'Stuck session resolved and audited.');
    }

    public function updateRules(): array
    {
        return [
            'staff_action' => ['nullable', Rule::in(['progress', 'before'])],
            'type' => ['required', Rule::enum(WorkUpdateType::class)], 'description' => ['required', 'string', 'max:10000'],
            'guided_actions' => ['nullable', 'array'], 'guided_actions.*' => ['string', 'max:60'],
            'guided_outcome' => ['nullable', 'string', 'max:60'], 'other_action' => ['nullable', 'string', 'max:1000'],
            'additional_remarks' => ['nullable', 'string', 'max:5000'],
            'requester_summary' => ['nullable', 'string', 'max:2000'], ...$this->evidenceRules(),
        ];
    }

    public function endRules(): array
    {
        return [
            'staff_action' => ['nullable', Rule::in(['stop', 'blocked', 'contribution', 'complete'])],
            'stop_reason' => ['nullable', 'string', 'max:60'], 'blocker_reason' => ['nullable', 'string', 'max:60'],
            'outcome' => ['required', Rule::enum(WorkSessionOutcome::class)], 'summary' => ['required', 'string', 'max:10000'],
            'guided_actions' => ['nullable', 'array'], 'guided_actions.*' => ['string', 'max:60'],
            'guided_outcome' => ['nullable', 'string', 'max:60'], 'other_action' => ['nullable', 'string', 'max:1000'],
            'additional_remarks' => ['nullable', 'string', 'max:5000'],
            'requester_summary' => ['nullable', 'string', 'max:2000'], 'material_description' => ['nullable', 'string', 'max:255'],
            'remaining_work' => ['nullable', 'string', 'max:5000'], ...$this->completionRules(false), ...$this->evidenceRules(),
        ];
    }

    public function completionRules(bool $required = true): array
    {
        return ['completion_summary' => [$required ? 'required' : 'nullable', 'string', 'max:5000'], 'work_performed_summary' => [$required ? 'required' : 'nullable', 'string', 'max:5000'], 'non_photo_reason' => ['nullable', 'string', 'max:5000'], 'requester_summary' => ['nullable', 'string', 'max:2000']];
    }

    private function evidenceRules(): array
    {
        return ['evidence' => ['array', 'max:'.config('work_orders.attachments.max_count')], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp,mp4', 'max:'.config('work_orders.attachments.max_size_kb')]];
    }

    /** @return array<string, string> */
    private function friendlyMessages(): array
    {
        return [
            'description.required' => 'Please review or edit the work summary before saving.',
            'summary.required' => 'Please describe what you completed before stopping.',
            'outcome.required' => 'Please choose what happened with the work.',
            'evidence.max' => 'Please choose no more than :max photos for this submission.',
            'evidence.*.mimes' => 'Please choose a JPG, PNG, WebP, or MP4 file.',
            'evidence.*.max' => 'One of the selected files is too large to upload.',
        ];
    }

    /** @return array<string, string> */
    private function friendlyAttributes(): array
    {
        return [
            'description' => 'work summary', 'summary' => 'work summary', 'guided_actions' => 'work performed',
            'guided_outcome' => 'work result', 'other_action' => 'other work performed', 'material_description' => 'materials needed',
            'remaining_work' => 'remaining work', 'evidence' => 'photos', 'evidence.*' => 'photo',
        ];
    }
}
