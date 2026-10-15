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
use App\Models\RoutineTaskTemplate;
use App\Models\RoutineTaskTemplateAssignment;
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
        $templates = RoutineTaskTemplate::with(['campus', 'building', 'location', 'assetType', 'activeAssignees.user', 'activeAssignees.skills'])
            ->withCount([
                'occurrences',
                'occurrences as overdue_occurrences_count' => fn ($query) => $query->unresolvedOverdue(),
            ])->latest()->paginate(20)->withQueryString();
        $personnel = $request->user()->can('routine_tasks.assign')
            ? FmoPersonnel::with(['user', 'skills'])->withCount([
                'routineTemplateAssignments as active_routine_tasks_count' => fn ($query) => $query->whereNull('unassigned_at'),
            ])->orderBy('personnel_identifier')->get()
            : collect();

        return view('routine-operations.templates.index', compact('templates', 'personnel'));
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('routine_tasks.manage'), 403);

        return view('routine-operations.templates.form', $this->formData(new RoutineTaskTemplate));
    }

    public function store(Request $request, RoutineOccurrenceService $occurrences)
    {
        abort_unless($request->user()->can('routine_tasks.manage'), 403);
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $request, &$template): void {
            $template = RoutineTaskTemplate::create($this->attributes($data) + ['created_by' => $request->user()->id]);
            $this->syncAssignees($template, $data['assignee_ids'], $request->user()->id);
        });
        $occurrences->generate(now(config('app.timezone'))->toImmutable()->startOfDay(), now(config('app.timezone'))->toImmutable()->addDays(7));

        return redirect()->route('routine-templates.index')->with('status', 'Recurring task template created.');
    }

    public function edit(Request $request, RoutineTaskTemplate $routineTemplate)
    {
        abort_unless($request->user()->can('routine_tasks.manage'), 403);
        $routineTemplate->load('activeAssignees');

        return view('routine-operations.templates.form', $this->formData($routineTemplate));
    }

    public function update(Request $request, RoutineTaskTemplate $routineTemplate)
    {
        abort_unless($request->user()->can('routine_tasks.manage'), 403);
        $data = $this->validated($request);
        DB::transaction(function () use ($routineTemplate, $data, $request): void {
            $routineTemplate->update($this->attributes($data));
            $this->syncAssignees($routineTemplate, $data['assignee_ids'], $request->user()->id);
        });

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
            'campus_id' => ['required', 'exists:campuses,id'], 'building_id' => ['nullable', 'exists:buildings,id'],
            'floor_id' => ['nullable', 'exists:floors,id'], 'building_location_id' => ['nullable', 'exists:building_locations,id'],
            'exact_placement' => ['nullable', 'string', 'max:255'], 'asset_type_id' => ['nullable', 'required_if:task_type,INSPECTION', 'exists:asset_types,id'],
            'guided_description_profile_id' => ['nullable', 'exists:guided_description_profiles,id'],
            'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'], 'due_time' => ['required', 'date_format:H:i'],
            'evidence_required' => ['nullable', 'boolean'], 'requires_review' => ['nullable', 'boolean'], 'is_active' => ['nullable', 'boolean'],
            'assignee_ids' => ['required', 'array', 'min:1'], 'assignee_ids.*' => ['distinct', Rule::exists('fmo_personnel', 'id')->where('personnel_status', 'ACTIVE')->whereNull('archived_at')],
        ]);
        $errors = [];
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

        return $data;
    }

    private function attributes(array $data): array
    {
        $recurrence = match ($data['frequency']) {
            'WEEKLY' => ['weekday' => (int) ($data['weekday'] ?? 1)],
            'MONTHLY' => ['day' => (int) ($data['month_day'] ?? 1)],
            default => [],
        };

        return collect($data)->except(['weekday', 'month_day', 'assignee_ids'])->all() + [
            'recurrence_config' => $recurrence, 'evidence_required' => (bool) ($data['evidence_required'] ?? false),
            'requires_review' => (bool) ($data['requires_review'] ?? false), 'is_active' => (bool) ($data['is_active'] ?? true),
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
        return ['template' => $template, 'campuses' => Campus::where('is_active', true)->orderBy('name')->get(),
            'personnel' => FmoPersonnel::with('user')->where('personnel_status', 'ACTIVE')->whereNull('archived_at')->orderBy('personnel_identifier')->get(),
            'profiles' => GuidedDescriptionProfile::where('is_active', true)->orderBy('name')->get(),
            'assetTypes' => AssetType::where('is_active', true)->orderBy('name')->get()];
    }
}
