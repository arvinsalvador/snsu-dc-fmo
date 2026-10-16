<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Enums\RoutineOccurrenceStatus;
use App\Enums\RoutineTaskType;
use App\Http\Controllers\Controller;
use App\Models\RoutineTaskOccurrence;
use App\Services\GuidedDescriptionService;
use App\Services\OperationalEvidenceService;
use App\Services\RoutineTaskAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoutineTaskController extends Controller
{
    public function index(Request $request, RoutineTaskAccessService $access)
    {
        abort_unless($request->user()->can('routine_tasks.view'), 403);
        $personnel = $access->activePersonnel($request->user());
        $inspectionItemScope = fn ($query) => $request->user()->can('routine_tasks.view_all')
            ? $query
            : $query->whereHas('assignments', fn ($assignment) => $assignment->where('fmo_personnel_id', $personnel?->id)->whereNull('unassigned_at'));
        $query = RoutineTaskOccurrence::with([
            'template.campus', 'template.building', 'template.location', 'template.assetType', 'template.activeAssignees.user', 'assignees.user',
            'inspectionRun' => fn ($run) => $run->withCount([
                'items as assigned_items_count' => fn ($items) => $inspectionItemScope($items),
                'items as assigned_inspected_count' => fn ($items) => $inspectionItemScope($items)->where('status', 'INSPECTED'),
            ]),
        ])
            ->orderByRaw("CASE WHEN due_at < ? AND status NOT IN ('COMPLETED', 'COMPLETED_LATE', 'UNABLE_TO_COMPLETE') THEN 0 WHEN status = 'DUE' THEN 1 WHEN status = 'UPCOMING' THEN 2 ELSE 3 END", [now(config('app.timezone'))])
            ->orderBy('due_at');
        $access->scopeOccurrences($query, $request->user());
        if ($request->filled('template')) {
            $query->where('routine_task_template_id', $request->string('template')->value());
        }
        $summaryQuery = clone $query;
        $summaryQuery->getQuery()->orders = null;
        $summaryCounts = [
            'overdue' => (clone $summaryQuery)->unresolvedOverdue()->count(),
            'due_today' => (clone $summaryQuery)->whereBetween('due_at', [now(config('app.timezone')), now(config('app.timezone'))->endOfDay()])
                ->whereNotIn('status', [RoutineOccurrenceStatus::Completed->value, RoutineOccurrenceStatus::CompletedLate->value, RoutineOccurrenceStatus::UnableToComplete->value])->count(),
            'upcoming' => (clone $summaryQuery)->where('due_at', '>', now(config('app.timezone'))->endOfDay())
                ->whereNotIn('status', [RoutineOccurrenceStatus::Completed->value, RoutineOccurrenceStatus::CompletedLate->value, RoutineOccurrenceStatus::UnableToComplete->value])->count(),
            'completed_today' => (clone $summaryQuery)->whereIn('status', [RoutineOccurrenceStatus::Completed->value, RoutineOccurrenceStatus::CompletedLate->value])
                ->whereBetween('completed_at', [now(config('app.timezone'))->startOfDay(), now(config('app.timezone'))->endOfDay()])->count(),
        ];
        if ($request->filled('status')) {
            $request->string('status')->value() === RoutineOccurrenceStatus::Overdue->value
                ? $query->unresolvedOverdue()
                : $query->where('status', $request->string('status')->value());
        } elseif (! $request->filled('template') && ! $request->boolean('history')) {
            $query->where(fn ($current) => $current
                ->whereNotIn('status', [RoutineOccurrenceStatus::Completed->value, RoutineOccurrenceStatus::CompletedLate->value, RoutineOccurrenceStatus::UnableToComplete->value])
                ->orWhere(fn ($completed) => $completed
                    ->whereIn('status', [RoutineOccurrenceStatus::Completed->value, RoutineOccurrenceStatus::CompletedLate->value])
                    ->whereBetween('completed_at', [now(config('app.timezone'))->startOfDay(), now(config('app.timezone'))->endOfDay()])));
        }
        $occurrences = $query->paginate(20)->withQueryString();

        return view('routine-operations.tasks.index', compact('occurrences', 'summaryCounts'));
    }

    public function show(Request $request, RoutineTaskOccurrence $routineTask)
    {
        $this->authorizeOccurrence($request, $routineTask, false);
        $routineTask->load(['template.guidedProfile', 'template.campus', 'template.building', 'template.location', 'template.activeAssignees.user', 'assignees.user', 'attachments', 'referencedWorkOrder', 'inspectionRun']);

        return view('routine-operations.tasks.show', ['occurrence' => $routineTask]);
    }

    public function start(Request $request, RoutineTaskOccurrence $routineTask)
    {
        $this->authorizeOccurrence($request, $routineTask, true);
        abort_unless(in_array($routineTask->status, [RoutineOccurrenceStatus::Due, RoutineOccurrenceStatus::Upcoming, RoutineOccurrenceStatus::Overdue], true), 422);
        $routineTask->update(['status' => RoutineOccurrenceStatus::InProgress, 'started_at' => $routineTask->started_at ?? now()]);

        return back()->with('status', 'Routine task started.');
    }

    public function resolve(Request $request, RoutineTaskOccurrence $routineTask, GuidedDescriptionService $guided, OperationalEvidenceService $evidence)
    {
        $this->authorizeOccurrence($request, $routineTask, true);
        $routineTask->load('template.guidedProfile', 'attachments');
        abort_if($routineTask->template->task_type === RoutineTaskType::Inspection, 422, 'Inspection occurrences must be completed item by item.');
        $allowed = ['COMPLETED', 'COMPLETED_LATE', 'PARTIALLY_COMPLETED', 'UNABLE_TO_COMPLETE', 'WAITING_FOR_SUPPLIES'];
        $data = $request->validate([
            'resolution' => ['required', Rule::in($allowed)], 'guided_actions' => ['nullable', 'array'], 'guided_actions.*' => ['string', 'max:60'],
            'guided_outcome' => ['nullable', 'string', 'max:60'], 'generated_summary' => ['nullable', 'string', 'max:5000'],
            'additional_remarks' => ['nullable', 'required_if:resolution_code,OTHER', 'string', 'max:5000'], 'remaining_work' => ['nullable', 'required_if:resolution,PARTIALLY_COMPLETED', 'string', 'max:5000'],
            'resolution_code' => ['nullable', 'required_if:resolution,UNABLE_TO_COMPLETE,WAITING_FOR_SUPPLIES', Rule::in(['MISSING_SUPPLIES', 'AREA_INACCESSIBLE', 'EQUIPMENT_UNAVAILABLE', 'WEATHER', 'URGENT_WORK', 'SAFETY_ISSUE', 'OTHER'])],
            'referenced_work_order_id' => ['nullable', 'exists:work_orders,id'], 'actual_completion_at' => ['nullable', 'required_if:resolution,COMPLETED_LATE', 'date', 'before_or_equal:now'],
            'evidence' => ['nullable', 'array', 'max:'.config('work_orders.attachments.max_count')], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')],
        ]);
        $profile = $routineTask->template->guidedProfile;
        if ($profile) {
            if (empty($data['guided_actions']) || empty($data['guided_outcome'])) {
                throw ValidationException::withMessages(['guided_actions' => 'Select the work performed and result.']);
            }
            $baseline = $guided->generate($profile, $data['guided_actions'], $data['guided_outcome']);
            $data['generated_summary'] = trim($data['generated_summary'] ?? '') ?: $baseline;
        }
        if (blank($data['generated_summary'] ?? null)) {
            throw ValidationException::withMessages(['generated_summary' => 'Describe the work performed or what happened.']);
        }
        if (($routineTask->template->evidence_required || $data['resolution'] === 'PARTIALLY_COMPLETED') && $routineTask->attachments->isEmpty() && empty($data['evidence'])) {
            throw ValidationException::withMessages(['evidence' => 'Photo evidence is required for this task.']);
        }
        $status = $data['resolution'];
        if ($status === 'COMPLETED' && $routineTask->due_at->isPast()) {
            $status = 'COMPLETED_LATE';
        }
        $personnel = $request->user()->personnelProfile;
        DB::transaction(function () use ($routineTask, $data, $status, $personnel, $request, $evidence): void {
            $routineTask->update([
                'status' => $status, 'started_at' => $routineTask->started_at ?? now(),
                'completed_at' => in_array($status, ['COMPLETED', 'COMPLETED_LATE', 'UNABLE_TO_COMPLETE'], true) ? ($data['actual_completion_at'] ?? now()) : null,
                'performed_by_personnel_id' => $personnel?->id, 'performed_by_user_id' => $request->user()->id,
                'structured_actions' => $data['guided_actions'] ?? [], 'guided_outcome' => $data['guided_outcome'] ?? null,
                'generated_summary' => $data['generated_summary'], 'additional_remarks' => $data['additional_remarks'] ?? null,
                'remaining_work' => $data['remaining_work'] ?? null, 'resolution_code' => $data['resolution_code'] ?? null,
                'referenced_work_order_id' => $data['referenced_work_order_id'] ?? null,
            ]);
            if (! empty($data['evidence'])) {
                $evidence->attach($routineTask, $data['evidence'], $request->user()->id, 'ROUTINE_EVIDENCE');
            }
        });

        return redirect()->route('routine-tasks.show', $routineTask)->with('status', 'Routine task update saved.');
    }

    private function authorizeOccurrence(Request $request, RoutineTaskOccurrence $occurrence, bool $perform): void
    {
        $permission = $perform ? 'routine_tasks.perform' : 'routine_tasks.view';
        abort_unless($request->user()->can($permission), 403);
        if ($request->user()->can('routine_tasks.view_all')) {
            return;
        }
        abort_unless(app(RoutineTaskAccessService::class)->assignedTo($occurrence, $request->user()), 403);
    }
}
