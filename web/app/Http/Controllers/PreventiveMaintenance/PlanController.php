<?php

namespace App\Http\Controllers\PreventiveMaintenance;

use App\Enums\PreventiveMaintenanceFrequency;
use App\Enums\PreventiveMaintenanceStatus;
use App\Http\Controllers\Controller;
use App\Models\FmoPersonnel;
use App\Models\InspectableAsset;
use App\Models\PreventiveMaintenanceOccurrence;
use App\Models\PreventiveMaintenancePlan;
use App\Models\PreventiveMaintenanceTemplate;
use App\Services\PreventiveMaintenanceOccurrenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('preventive_maintenance.view_all'), 403);
        $plans = PreventiveMaintenancePlan::with(['asset.assetType', 'asset.building', 'activeAssignees.user'])
            ->withCount('occurrences')
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$request->string('q').'%')
                ->orWhereHas('asset', fn ($asset) => $asset->where('name', 'like', '%'.$request->string('q').'%')->orWhere('asset_code', 'like', '%'.$request->string('q').'%'))))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->string('status')->value() === 'ACTIVE'))
            ->latest()->paginate($this->perPage($request))->withQueryString();

        return view('preventive-maintenance.plans.index', compact('plans'));
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('preventive_maintenance.manage_plans'), 403);

        $plan = new PreventiveMaintenancePlan;
        if ($request->filled('template')) {
            $template = PreventiveMaintenanceTemplate::where('is_active', true)->findOrFail($request->string('template')->value());
            $plan->fill([
                'template_id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'maintenance_type' => $template->maintenance_type,
                'frequency_type' => $template->frequency_type,
                'interval_value' => $template->interval_value,
                'recurrence_config' => $template->recurrence_config,
                'checklist_template' => $template->checklist_template,
                'reading_template' => $template->reading_template,
                'evidence_required' => $template->evidence_required,
            ]);
        }

        return view('preventive-maintenance.plans.form', $this->formData($plan, $request));
    }

    public function store(Request $request, PreventiveMaintenanceOccurrenceService $generator)
    {
        abort_unless($request->user()->can('preventive_maintenance.manage_plans'), 403);
        $data = $this->validated($request);
        $plan = DB::transaction(function () use ($data, $request): PreventiveMaintenancePlan {
            if ($request->boolean('save_as_template')) {
                $template = PreventiveMaintenanceTemplate::create([
                    'name' => $data['template_name'],
                    'description' => $data['description'] ?? null,
                    'maintenance_type' => $data['maintenance_type'],
                    'frequency_type' => $data['frequency_type'],
                    'interval_value' => $data['interval_value'],
                    'recurrence_config' => $data['recurrence_config'],
                    'checklist_template' => $data['checklist_template'],
                    'reading_template' => $data['reading_template'],
                    'recommended_skill_ids' => FmoPersonnel::whereKey($data['assignee_ids'])->with('skills')->get()
                        ->flatMap->skills->pluck('id')->unique()->values()->all(),
                    'evidence_required' => $data['evidence_required'],
                    'is_active' => true,
                    'created_by' => $request->user()->id,
                ]);
                $data['template_id'] = $template->id;
            }
            unset($data['save_as_template'], $data['template_name']);
            $plan = PreventiveMaintenancePlan::create($data + ['created_by' => $request->user()->id]);
            $this->syncAssignments($plan, $data['assignee_ids'], $request->user()->id);

            return $plan;
        });
        $now = now(config('app.timezone'))->toImmutable();
        $from = $generator->currentPeriodStart($plan, $now);
        $generator->generate($from, $now->addDays(30)->max($from));

        return redirect()->route('preventive-maintenance.plans.index')->with('status', 'Preventive maintenance plan created and current tasks generated.');
    }

    public function edit(Request $request, PreventiveMaintenancePlan $plan)
    {
        abort_unless($request->user()->can('preventive_maintenance.manage_plans'), 403);
        $plan->load('activeAssignees');

        return view('preventive-maintenance.plans.form', $this->formData($plan, $request));
    }

    public function update(Request $request, PreventiveMaintenancePlan $plan, PreventiveMaintenanceOccurrenceService $generator)
    {
        abort_unless($request->user()->can('preventive_maintenance.manage_plans'), 403);
        $data = $this->validated($request);
        DB::transaction(function () use ($plan, $data, $request): void {
            $plan->update($data);
            $this->syncAssignments($plan, $data['assignee_ids'], $request->user()->id);
        });
        $now = now(config('app.timezone'))->toImmutable();
        $generator->generate($generator->currentPeriodStart($plan->fresh(), $now), $now->addDays(30));

        return redirect()->route('preventive-maintenance.plans.index')->with('status', 'Plan updated. Completed maintenance records were not changed.');
    }

    public function deactivate(Request $request, PreventiveMaintenancePlan $plan)
    {
        abort_unless($request->user()->can('preventive_maintenance.manage_plans'), 403);
        DB::transaction(function () use ($plan): void {
            $plan->update(['is_active' => false]);
            $plan->occurrences()->where('status', 'UPCOMING')->update(['status' => 'CANCELLED']);
        });

        return back()->with('status', 'Plan deactivated. Existing history was preserved.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'inspectable_asset_id' => ['required', 'exists:inspectable_assets,id'],
            'template_id' => ['nullable', 'exists:preventive_maintenance_templates,id'],
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'maintenance_type' => ['required', 'string', 'max:40'],
            'frequency_type' => ['required', Rule::enum(PreventiveMaintenanceFrequency::class)],
            'interval_value' => ['required', 'integer', 'min:1', 'max:365'],
            'month_day' => ['nullable', 'integer', 'min:1', 'max:31'], 'weekday' => ['nullable', 'integer', 'min:1', 'max:7'],
            'custom_unit' => ['nullable', Rule::in(['DAY', 'WEEK', 'MONTH'])],
            'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'available_time' => ['required', 'date_format:H:i'], 'due_time' => ['required', 'date_format:H:i', 'after:available_time'],
            'checklist_label' => ['required', 'array', 'min:1'], 'checklist_label.*' => ['required', 'string', 'max:255'],
            'reading_label' => ['nullable', 'array'], 'reading_label.*' => ['nullable', 'string', 'max:255'],
            'reading_unit' => ['nullable', 'array'], 'reading_unit.*' => ['nullable', 'string', 'max:30'],
            'assignee_ids' => ['required', 'array', 'min:1'],
            'assignee_ids.*' => [Rule::exists('fmo_personnel', 'id')->where(fn ($query) => $query->where('personnel_status', 'ACTIVE')->whereNull('archived_at'))],
            'evidence_required' => ['nullable', 'boolean'], 'verification_required' => ['nullable', 'boolean'], 'is_active' => ['nullable', 'boolean'],
            'save_as_template' => ['nullable', 'boolean'],
            'template_name' => ['nullable', 'required_if:save_as_template,1', 'string', 'max:255'],
        ]);
        $data['recurrence_config'] = array_filter(['day' => $data['month_day'] ?? null, 'weekday' => $data['weekday'] ?? null, 'unit' => $data['custom_unit'] ?? null], fn ($value) => $value !== null);
        $data['checklist_template'] = collect($data['checklist_label'])->values()->map(fn ($label, $index) => ['key' => 'ITEM_'.($index + 1), 'label' => $label])->all();
        $data['reading_template'] = collect($data['reading_label'] ?? [])->filter()->values()->map(fn ($label, $index) => ['key' => 'READING_'.($index + 1), 'label' => $label, 'unit' => $data['reading_unit'][$index] ?? null])->all();
        $data['evidence_required'] = $request->boolean('evidence_required');
        $data['verification_required'] = $request->boolean('verification_required');
        $data['is_active'] = $request->boolean('is_active', true);
        $data['save_as_template'] = $request->boolean('save_as_template');
        unset($data['month_day'], $data['weekday'], $data['custom_unit'], $data['checklist_label'], $data['reading_label'], $data['reading_unit']);

        return $data;
    }

    private function syncAssignments(PreventiveMaintenancePlan $plan, array $ids, int $actorId): void
    {
        $plan->activeAssignees()->newPivotStatement()->where('preventive_maintenance_plan_id', $plan->id)->whereNotIn('fmo_personnel_id', $ids)->whereNull('unassigned_at')->update(['unassigned_at' => now(), 'active_marker' => null]);
        foreach ($ids as $id) {
            $plan->activeAssignees()->newPivotStatement()->updateOrInsert(
                ['preventive_maintenance_plan_id' => $plan->id, 'fmo_personnel_id' => $id, 'active_marker' => 1],
                ['assigned_by' => $actorId, 'assigned_at' => now(), 'unassigned_at' => null, 'created_at' => now(), 'updated_at' => now()]
            );
        }
        $plan->occurrences()->open()->whereNull('completed_at')->get()->each(function (PreventiveMaintenanceOccurrence $occurrence) use ($ids): void {
            if ($occurrence->status === PreventiveMaintenanceStatus::InProgress) {
                $occurrence->assignees()->syncWithoutDetaching($ids);

                return;
            }
            $occurrence->assignees()->sync($ids);
        });
    }

    private function formData(PreventiveMaintenancePlan $plan, Request $request): array
    {
        return [
            'plan' => $plan,
            'assets' => InspectableAsset::with(['assetType', 'building'])->where('lifecycle_status', 'ACTIVE')->orderBy('asset_code')->get(),
            'personnel' => FmoPersonnel::with(['user', 'skills'])->where('personnel_status', 'ACTIVE')->whereNull('archived_at')->orderBy('personnel_identifier')->get(),
            'templates' => PreventiveMaintenanceTemplate::where('is_active', true)->orderBy('name')->get(),
            'selectedAsset' => $request->filled('asset') ? InspectableAsset::find($request->string('asset')->value()) : null,
        ];
    }

    private function perPage(Request $request): int
    {
        return in_array($request->integer('per_page'), [20, 50, 100], true) ? $request->integer('per_page') : 20;
    }
}
