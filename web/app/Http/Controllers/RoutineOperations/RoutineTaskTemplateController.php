<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Http\Controllers\Controller;
use App\Models\AssetType;
use App\Models\Building;
use App\Models\BuildingLocation;
use App\Models\Campus;
use App\Models\Floor;
use App\Models\FmoPersonnel;
use App\Models\GuidedDescriptionProfile;
use App\Models\InspectableAsset;
use App\Models\RoutineTaskOccurrence;
use App\Models\RoutineTaskTemplate;
use App\Models\RoutineTaskTemplateAssignment;
use App\Services\InspectionAssignmentService;
use App\Services\RoutineNoticeService;
use App\Services\RoutineOccurrenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoutineTaskTemplateController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('routine_tasks.view_all'), 403);
        $now = now(config('app.timezone'));
        $closedStatuses = ['COMPLETED', 'COMPLETED_LATE', 'UNABLE_TO_COMPLETE'];
        $templates = RoutineTaskTemplate::with(['campus', 'building', 'floor', 'location', 'assetType', 'activeAssignees.user', 'activeAssignees.skills', 'currentInspectionRun'])
            ->withCount([
                'occurrences',
                'occurrences as overdue_occurrences_count' => fn ($query) => $query->unresolvedOverdue(),
            ])
            ->withMin(['occurrences as next_due_at' => fn ($query) => $query
                ->where('due_at', '>=', $now)->whereNotIn('status', $closedStatuses)], 'due_at')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = '%'.$request->string('q')->trim().'%';
                $query->where(fn ($matches) => $matches
                    ->where('title', 'like', $search)
                    ->orWhere('exact_placement', 'like', $search)
                    ->orWhereHas('campus', fn ($campus) => $campus->where('name', 'like', $search))
                    ->orWhereHas('building', fn ($building) => $building->where('name', 'like', $search))
                    ->orWhereHas('location', fn ($location) => $location->where('name', 'like', $search))
                    ->orWhereHas('assetType', fn ($type) => $type->where('name', 'like', $search))
                    ->orWhereHas('activeAssignees.user', fn ($user) => $user->where('name', 'like', $search)));
            })
            ->when(in_array($request->string('type')->value(), ['GENERAL', 'INSPECTION'], true), fn ($query) => $query->where('task_type', $request->string('type')->value()))
            ->when(in_array($request->string('frequency')->value(), ['DAILY', 'WEEKLY', 'MONTHLY'], true), fn ($query) => $query->where('frequency', $request->string('frequency')->value()))
            ->when($request->string('status')->value() === 'ACTIVE', fn ($query) => $query->where('is_active', true))
            ->when($request->string('status')->value() === 'INACTIVE', fn ($query) => $query->where('is_active', false))
            ->when($request->filled('campus'), fn ($query) => $query->where('campus_id', $request->string('campus')->value()))
            ->when($request->filled('building'), fn ($query) => $query->where('building_id', $request->string('building')->value()))
            ->latest()->paginate(20)->withQueryString();

        $summaryCounts = [
            'active_templates' => RoutineTaskTemplate::where('is_active', true)->count(),
            'due_today' => RoutineTaskOccurrence::whereBetween('due_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->whereNotIn('status', $closedStatuses)->count(),
            'overdue' => RoutineTaskOccurrence::unresolvedOverdue()->count(),
            'inspection_schedules' => RoutineTaskTemplate::where('task_type', 'INSPECTION')->where('is_active', true)->count(),
        ];
        $personnel = $request->user()->can('routine_tasks.assign')
            ? FmoPersonnel::with(['user', 'skills'])->withCount([
                'routineTemplateAssignments as active_routine_tasks_count' => fn ($query) => $query->whereNull('unassigned_at'),
            ])->orderBy('personnel_identifier')->get()
            : collect();
        $campuses = Campus::where('is_active', true)->orderBy('name')->get();
        $buildings = Building::where('is_active', true)->orderBy('name')->get();

        return view('routine-operations.templates.index', compact('templates', 'personnel', 'summaryCounts', 'campuses', 'buildings'));
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('routine_tasks.manage'), 403);

        return view('routine-operations.templates.form', $this->formData(new RoutineTaskTemplate));
    }

    public function store(Request $request, RoutineOccurrenceService $occurrences, InspectionAssignmentService $inspectionAssignments, RoutineNoticeService $notices)
    {
        abort_unless($request->user()->can('routine_tasks.manage'), 403);
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $request, $inspectionAssignments, &$template): void {
            $template = RoutineTaskTemplate::create($this->attributes($data) + ['created_by' => $request->user()->id]);
            $this->syncAssignees($template, $data['assignee_ids'], $request->user()->id);
            $this->syncInspectionScope($template, $data, $request, $inspectionAssignments);
        });
        $this->generateCurrentWindow($template, $occurrences, $notices);

        return redirect()->route('routine-templates.index')->with('status', 'Recurring task template created.');
    }

    public function edit(Request $request, RoutineTaskTemplate $routineTemplate)
    {
        abort_unless($request->user()->can('routine_tasks.manage'), 403);
        $routineTemplate->load(['activeAssignees', 'selectedAssets', 'assetAssignmentOverrides']);

        return view('routine-operations.templates.form', $this->formData($routineTemplate));
    }

    public function update(Request $request, RoutineTaskTemplate $routineTemplate, InspectionAssignmentService $inspectionAssignments, RoutineOccurrenceService $occurrences, RoutineNoticeService $notices)
    {
        abort_unless($request->user()->can('routine_tasks.manage'), 403);
        $data = $this->validated($request);
        DB::transaction(function () use ($routineTemplate, $data, $request, $inspectionAssignments): void {
            $routineTemplate->update($this->attributes($data));
            $this->syncAssignees($routineTemplate, $data['assignee_ids'], $request->user()->id);
            $this->syncInspectionScope($routineTemplate, $data, $request, $inspectionAssignments);
        });
        $this->generateCurrentWindow($routineTemplate->fresh(), $occurrences, $notices);

        return redirect()->route('routine-templates.index')->with('status', 'Recurring task template updated.');
    }

    public function deactivate(Request $request, RoutineTaskTemplate $routineTemplate)
    {
        abort_unless($request->user()->can('routine_tasks.manage'), 403);
        $routineTemplate->update(['is_active' => false]);

        return back()->with('status', 'Recurring task template deactivated. Existing occurrences remain in history.');
    }

    public function assign(Request $request, RoutineTaskTemplate $routineTemplate)
    {
        abort_unless($request->user()->can('routine_tasks.assign'), 403);
        $data = $request->validate([
            'assignment_template_id' => ['nullable', Rule::in([$routineTemplate->id])],
            'assignee_ids' => ['required', 'array', 'min:1'],
            'assignee_ids.*' => ['distinct', Rule::exists('fmo_personnel', 'id')->where('personnel_status', 'ACTIVE')->whereNull('archived_at')],
            'assignment_note' => ['nullable', 'string', 'max:2000'],
        ]);
        foreach (FmoPersonnel::with('user')->whereIn('id', $data['assignee_ids'])->get() as $person) {
            if (! $person->isAssignable()) {
                throw ValidationException::withMessages(['assignee_ids' => 'Every assigned personnel member must have an active, approved staff profile.']);
            }
        }
        DB::transaction(function () use ($routineTemplate, $data, $request): void {
            $routineTemplate->update(['assignment_note' => $data['assignment_note'] ?? null]);
            $this->syncAssignees($routineTemplate, $data['assignee_ids'], $request->user()->id);
        });

        return back()->with('status', 'Routine task assignment updated.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'task_type' => ['required', Rule::in(['GENERAL', 'INSPECTION'])], 'frequency' => ['required', Rule::in(['DAILY', 'WEEKLY', 'MONTHLY'])],
            'weekday' => ['nullable', 'integer', 'between:1,7'], 'month_day' => ['nullable', 'integer', 'between:1,28'],
            'campus_id' => ['nullable', 'exists:campuses,id'], 'building_id' => ['nullable', 'exists:buildings,id'],
            'floor_id' => ['nullable', 'exists:floors,id'], 'building_location_id' => ['nullable', 'exists:building_locations,id'],
            'exact_placement' => ['nullable', 'string', 'max:255'], 'asset_type_id' => ['nullable', 'required_if:task_type,INSPECTION', 'exists:asset_types,id'],
            'inspection_asset_scope' => ['nullable', 'required_if:task_type,INSPECTION', Rule::in(['SPECIFIC', 'ALL_TYPE', 'TYPE_LOCATION'])],
            'customize_asset_assignments' => ['nullable', 'boolean'],
            'selected_asset_ids' => ['nullable', 'array'], 'selected_asset_ids.*' => ['distinct', 'exists:inspectable_assets,id'],
            'asset_assignees' => ['nullable', 'array'], 'asset_assignees.*' => ['array', 'min:1'],
            'asset_assignees.*.*' => [Rule::exists('fmo_personnel', 'id')->where('personnel_status', 'ACTIVE')->whereNull('archived_at')],
            'guided_description_profile_id' => ['nullable', 'exists:guided_description_profiles,id'],
            'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'], 'due_time' => ['required', 'date_format:H:i'],
            'evidence_required' => ['nullable', 'boolean'], 'requires_review' => ['nullable', 'boolean'], 'is_active' => ['nullable', 'boolean'],
            'assignee_ids' => ['required', 'array', 'min:1'], 'assignee_ids.*' => ['distinct', Rule::exists('fmo_personnel', 'id')->where('personnel_status', 'ACTIVE')->whereNull('archived_at')],
        ]);
        $errors = [];
        if ($data['task_type'] === 'GENERAL' && empty($data['campus_id'])) {
            $errors['campus_id'] = 'Select a campus for this routine task.';
        }
        if ($data['task_type'] === 'INSPECTION' && ($data['inspection_asset_scope'] ?? null) === 'TYPE_LOCATION' && empty($data['campus_id'])) {
            $errors['campus_id'] = 'Select a campus for this location-scoped inspection.';
        }
        if ($data['task_type'] === 'INSPECTION' && ($data['inspection_asset_scope'] ?? null) === 'SPECIFIC' && empty($data['selected_asset_ids'])) {
            $errors['selected_asset_ids'] = 'Select at least one asset for this inspection schedule.';
        }
        if (($data['building_id'] ?? null) && ! Building::whereKey($data['building_id'])->where('campus_id', $data['campus_id'])->where('is_active', true)->exists()) {
            $errors['building_id'] = 'The selected building does not belong to the selected campus.';
        }
        if (($data['floor_id'] ?? null) && (! ($data['building_id'] ?? null) || ! Floor::whereKey($data['floor_id'])->where('building_id', $data['building_id'])->where('is_active', true)->exists())) {
            $errors['floor_id'] = 'The selected floor does not belong to the selected building.';
        }
        if (($data['building_location_id'] ?? null) && (! ($data['building_id'] ?? null) || ! BuildingLocation::whereKey($data['building_location_id'])->where('building_id', $data['building_id'])->where('is_active', true)->where(fn ($query) => ($data['floor_id'] ?? null) ? $query->whereNull('floor_id')->orWhere('floor_id', $data['floor_id']) : $query->whereNull('floor_id'))->exists())) {
            $errors['building_location_id'] = 'The selected area does not belong to the selected building and floor.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $this->validateAssignablePersonnel(collect($data['assignee_ids'])->merge(collect($data['asset_assignees'] ?? [])->flatten())->unique()->all());
        if ($data['task_type'] === 'INSPECTION') {
            $allowedAssets = $this->inspectionAssetQuery($data)->pluck('id');
            if ($allowedAssets->isEmpty()) {
                throw ValidationException::withMessages(['asset_type_id' => 'No active assets match this inspection scope.']);
            }
            if (($data['inspection_asset_scope'] ?? null) === 'SPECIFIC' && $allowedAssets->count() !== count(array_unique($data['selected_asset_ids'] ?? []))) {
                throw ValidationException::withMessages(['selected_asset_ids' => 'Every selected asset must be active and match the selected asset type.']);
            }
            $invalidOverrides = collect(array_keys($data['asset_assignees'] ?? []))->diff($allowedAssets);
            if ($invalidOverrides->isNotEmpty()) {
                throw ValidationException::withMessages(['asset_assignees' => 'Per-asset assignments must belong to the configured inspection scope.']);
            }
        }

        return $data;
    }

    private function attributes(array $data): array
    {
        $recurrence = match ($data['frequency']) {
            'WEEKLY' => ['weekday' => (int) ($data['weekday'] ?? 1)],
            'MONTHLY' => ['day' => (int) ($data['month_day'] ?? 1)],
            default => [],
        };

        return collect($data)->except(['weekday', 'month_day', 'assignee_ids', 'selected_asset_ids', 'asset_assignees'])->all() + [
            'recurrence_config' => $recurrence, 'evidence_required' => (bool) ($data['evidence_required'] ?? false),
            'requires_review' => (bool) ($data['requires_review'] ?? false), 'is_active' => (bool) ($data['is_active'] ?? true),
            'customize_asset_assignments' => (bool) ($data['customize_asset_assignments'] ?? false),
        ];
    }

    private function syncAssignees(RoutineTaskTemplate $template, array $personnelIds, int $userId): void
    {
        $active = $template->assignments()->whereNull('unassigned_at')->pluck('fmo_personnel_id');
        $removed = $active->diff($personnelIds);
        $template->assignments()->whereNull('unassigned_at')->whereIn('fmo_personnel_id', $removed)->update(['unassigned_at' => now(), 'active_marker' => null]);
        foreach (collect($personnelIds)->diff($active) as $id) {
            RoutineTaskTemplateAssignment::create(['routine_task_template_id' => $template->id, 'fmo_personnel_id' => $id, 'assigned_by' => $userId, 'assigned_at' => now(), 'active_marker' => 1]);
        }
    }

    private function formData(RoutineTaskTemplate $template): array
    {
        $template->loadMissing(['activeAssignees', 'selectedAssets', 'assetAssignmentOverrides']);

        return ['template' => $template, 'campuses' => Campus::where('is_active', true)->orderBy('name')->get(),
            'personnel' => FmoPersonnel::with(['user', 'skills'])->withCount([
                'routineTemplateAssignments as active_routine_tasks_count' => fn ($query) => $query->whereNull('unassigned_at'),
                'inspectionItemAssignments as active_inspection_items_count' => fn ($query) => $query->whereNull('unassigned_at')->whereHas('item', fn ($items) => $items->where('status', '!=', 'INSPECTED')),
            ])->orderBy('personnel_identifier')->get(),
            'profiles' => GuidedDescriptionProfile::where('is_active', true)->orderBy('name')->get(),
            'assetTypes' => AssetType::where('is_active', true)->orderBy('name')->get(),
            'assets' => InspectableAsset::with(['assetType', 'campus', 'building', 'floor', 'location'])
                ->where('lifecycle_status', 'ACTIVE')->orderBy('asset_code')->get()];
    }

    private function syncInspectionScope(RoutineTaskTemplate $template, array $data, Request $request, InspectionAssignmentService $assignments): void
    {
        if ($data['task_type'] !== 'INSPECTION') {
            $template->selectedAssets()->detach();
            $assignments->syncTemplateOverrides($template, [], $request->user());

            return;
        }
        $template->selectedAssets()->sync(($data['inspection_asset_scope'] ?? null) === 'SPECIFIC' ? ($data['selected_asset_ids'] ?? []) : []);
        $assignments->syncTemplateOverrides(
            $template,
            ($data['customize_asset_assignments'] ?? false) ? ($data['asset_assignees'] ?? []) : [],
            $request->user(),
        );
    }

    private function inspectionAssetQuery(array $data)
    {
        return InspectableAsset::query()->where('asset_type_id', $data['asset_type_id'])->where('lifecycle_status', 'ACTIVE')
            ->when(($data['inspection_asset_scope'] ?? null) === 'SPECIFIC', fn ($query) => $query->whereIn('id', $data['selected_asset_ids'] ?? []))
            ->when(($data['inspection_asset_scope'] ?? null) === 'TYPE_LOCATION', fn ($query) => $query
                ->where('campus_id', $data['campus_id'])
                ->when($data['building_id'] ?? null, fn ($q) => $q->where('building_id', $data['building_id']))
                ->when($data['floor_id'] ?? null, fn ($q) => $q->where('floor_id', $data['floor_id']))
                ->when($data['building_location_id'] ?? null, fn ($q) => $q->where('building_location_id', $data['building_location_id'])));
    }

    /** @param array<int, string> $personnelIds */
    private function validateAssignablePersonnel(array $personnelIds): void
    {
        foreach (FmoPersonnel::with('user')->whereIn('id', $personnelIds)->get() as $person) {
            if (! $person->isAssignable()) {
                throw ValidationException::withMessages(['assignee_ids' => 'Every assigned inspector must have an active, approved FMO personnel profile.']);
            }
        }
    }

    private function generateCurrentWindow(RoutineTaskTemplate $template, RoutineOccurrenceService $occurrences, RoutineNoticeService $notices): void
    {
        if (! $template->is_active) {
            return;
        }
        $today = now(config('app.timezone'))->toImmutable()->startOfDay();
        $from = match ($template->frequency->value) {
            'MONTHLY' => $today->startOfMonth(),
            'WEEKLY' => $today->startOfWeek(),
            default => $today,
        };
        $occurrences->generate($from, $today->addDays(7));
        $notices->sendDueNotices();
    }
}
