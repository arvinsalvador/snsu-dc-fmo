<?php

namespace Tests\Feature;

use App\Enums\PreventiveMaintenanceStatus;
use App\Models\AssetType;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\InspectableAsset;
use App\Models\PreventiveMaintenancePlan;
use App\Models\PreventiveMaintenanceTemplate;
use App\Models\User;
use App\Services\AssetMaintenanceHistoryService;
use App\Services\PreventiveMaintenanceAccessService;
use App\Services\PreventiveMaintenanceNoticeService;
use App\Services\PreventiveMaintenanceOccurrenceService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\RoutineOperationsSeeder;
use Database\Seeders\WorkOrderCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PreventiveMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $staffA;

    private User $staffB;

    private FmoPersonnel $personA;

    private InspectableAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('testing', config('database.connections.'.config('database.default').'.database'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-04 09:00:00', 'Asia/Manila'));
        $this->seed([AuthorizationSeeder::class, RoutineOperationsSeeder::class, WorkOrderCategorySeeder::class]);
        $this->head = $this->user('FMO Head');
        $this->staffA = $this->user('FMO Staff');
        $this->staffB = $this->user('FMO Staff');
        $this->personA = $this->person($this->staffA, 'FMO-PM-001');
        $this->person($this->staffB, 'FMO-PM-002');
        $campus = Campus::create(['code' => 'DC', 'name' => 'Del Carmen Campus', 'is_active' => true]);
        $building = Building::create(['campus_id' => $campus->id, 'code' => 'ADM', 'name' => 'Admin Building', 'is_active' => true]);
        $type = AssetType::firstOrCreate(['code' => 'AC'], ['name' => 'Air Conditioner', 'checklist' => [['code' => 'CHECK', 'label' => 'Operational']], 'is_active' => true]);
        $this->asset = InspectableAsset::create([
            'asset_code' => 'AC-ADM-001', 'asset_type_id' => $type->id, 'asset_class' => 'EQUIPMENT', 'name' => 'Guidance Office Air Conditioner',
            'campus_id' => $campus->id, 'building_id' => $building->id, 'inspection_frequency' => 'MONTHLY',
            'status' => 'ACTIVE', 'condition' => 'GOOD', 'lifecycle_status' => 'ACTIVE',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_plan_generates_one_current_occurrence_idempotently(): void
    {
        $plan = $this->plan('2026-11-04', 'MONTHLY');
        $service = app(PreventiveMaintenanceOccurrenceService::class);
        $service->generate(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
        $service->generate(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));

        $this->assertSame(1, $plan->occurrences()->count());
        $occurrence = $plan->occurrences()->firstOrFail();
        $this->assertSame('2026-11', $occurrence->period_key);
        $this->assertSame([$this->personA->id], $occurrence->assignees()->pluck('fmo_personnel.id')->all());
        $this->assertSame(2, count($occurrence->checklist_snapshot));
    }

    public function test_manager_can_create_plan_and_reusable_template_and_open_management_pages(): void
    {
        $response = $this->actingAs($this->head)->post(route('preventive-maintenance.plans.store'), [
            'inspectable_asset_id' => $this->asset->id,
            'name' => 'Monthly Air Conditioner PM',
            'description' => 'Reusable air-conditioner service procedure.',
            'maintenance_type' => 'PREVENTIVE',
            'frequency_type' => 'MONTHLY', 'interval_value' => 1, 'month_day' => 1,
            'start_date' => '2026-10-01', 'available_time' => '08:00', 'due_time' => '17:00',
            'checklist_label' => ['Clean filter', 'Test cooling performance'],
            'reading_label' => ['Temperature'], 'reading_unit' => ['°C'],
            'assignee_ids' => [$this->personA->id], 'evidence_required' => 1, 'is_active' => 1,
            'save_as_template' => 1, 'template_name' => 'Reusable Monthly AC PM',
        ]);

        $response->assertRedirect(route('preventive-maintenance.plans.index'));
        $template = PreventiveMaintenanceTemplate::where('name', 'Reusable Monthly AC PM')->firstOrFail();
        $plan = PreventiveMaintenancePlan::where('template_id', $template->id)->firstOrFail();
        $this->assertTrue($plan->evidence_required);
        $this->assertSame(1, $plan->occurrences()->where('period_key', '2026-11')->count());
        $this->assertSame(0, $plan->occurrences()->where('period_key', '2026-10')->count(), 'Plan creation should not backfill historical periods.');
        $this->assertSame(2, $plan->occurrences()->count(), 'Plan creation should generate the current period and planning horizon.');
        $this->actingAs($this->head)->get(route('preventive-maintenance.plans.create', ['template' => $template->id]))
            ->assertOk()->assertSee('Reusable Monthly AC PM')->assertSee('Clean filter');
        $this->actingAs($this->head)->get(route('preventive-maintenance.dashboard'))->assertOk()->assertSee('PM Dashboard');
        $this->actingAs($this->head)->get(route('preventive-maintenance.history'))->assertOk()->assertSee('Preventive Maintenance History');
        $this->actingAs($this->head)->get(route('preventive-maintenance.history.export'))->assertOk()
            ->assertDownload('preventive-maintenance-history.csv');
    }

    public function test_weekend_moves_to_monday_and_upcoming_cannot_start(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-30 09:00:00', 'Asia/Manila'));
        $plan = $this->plan('2026-11-01', 'MONTHLY', ['day' => 1]);
        app(PreventiveMaintenanceOccurrenceService::class)->generate(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-01'));
        $occurrence = $plan->occurrences()->firstOrFail();

        $this->assertSame('2026-11-02 08:00:00', $occurrence->available_at->timezone('Asia/Manila')->format('Y-m-d H:i:s'));
        $this->assertSame(PreventiveMaintenanceStatus::Upcoming, $occurrence->status);
        $this->actingAs($this->staffA)->get(route('preventive-maintenance.tasks.show', $occurrence))->assertOk()->assertSee('This maintenance cannot be started yet.')->assertDontSee('Start Maintenance');
        $this->actingAs($this->staffA)->post(route('preventive-maintenance.tasks.start', $occurrence))->assertSessionHasErrors('task');
        $this->assertNull($occurrence->fresh()->started_at);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 08:30:00', 'Asia/Manila'));
        $this->actingAs($this->staffA)->post(route('preventive-maintenance.tasks.start', $occurrence))->assertRedirect();
        $this->assertSame(PreventiveMaintenanceStatus::InProgress, $occurrence->fresh()->status);
    }

    public function test_daily_plan_skips_weekends_and_weekend_start_catch_up_does_not_duplicate_monday(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-06 07:00:00', 'Asia/Manila'));
        $daily = $this->plan('2026-11-06', 'DAILY');
        $monthly = $this->plan('2026-10-31', 'MONTHLY', ['day' => 1]);
        $service = app(PreventiveMaintenanceOccurrenceService::class);

        $this->assertSame(3, $service->generate(CarbonImmutable::parse('2026-10-31'), CarbonImmutable::parse('2026-11-09')));
        $this->assertSame(0, $service->generate(CarbonImmutable::parse('2026-10-31'), CarbonImmutable::parse('2026-11-09')));

        $this->assertSame(['2026-11-06', '2026-11-09'], $daily->occurrences()->orderBy('available_at')->pluck('period_key')->all());
        $this->assertSame(1, $daily->occurrences()->whereDate('available_at', '2026-11-09')->count());
        $monthlyOccurrence = $monthly->occurrences()->sole();
        $this->assertSame('2026-11', $monthlyOccurrence->period_key);
        $this->assertSame('2026-11-02 08:00:00', $monthlyOccurrence->available_at->timezone('Asia/Manila')->format('Y-m-d H:i:s'));
    }

    public function test_corrective_work_order_requires_assignment_completion_and_is_created_once(): void
    {
        $plan = $this->plan('2026-11-04', 'DAILY');
        app(PreventiveMaintenanceOccurrenceService::class)->generate(CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-04'));
        $occurrence = $plan->occurrences()->firstOrFail();
        $this->actingAs($this->staffA)->post(route('preventive-maintenance.tasks.start', $occurrence))->assertRedirect();
        $occurrence->execution()->update(['findings' => 'Drain line partially blocked.']);
        $payload = ['subject' => 'Repair Air Conditioner Drain Line'];

        $this->actingAs($this->staffA)->post(route('preventive-maintenance.tasks.corrective-work-order', $occurrence), $payload)->assertSessionHasErrors('subject');
        $occurrence->update(['status' => PreventiveMaintenanceStatus::Completed, 'completed_at' => now()]);
        $this->actingAs($this->staffB)->post(route('preventive-maintenance.tasks.corrective-work-order', $occurrence), $payload)->assertForbidden();
        $this->assertSame(0, $occurrence->correctiveWorkOrders()->count());

        $this->actingAs($this->staffA)->post(route('preventive-maintenance.tasks.corrective-work-order', $occurrence), $payload)->assertRedirect();
        $workOrder = $occurrence->correctiveWorkOrders()->sole();
        $this->actingAs($this->staffA)->post(route('preventive-maintenance.tasks.corrective-work-order', $occurrence), $payload)
            ->assertRedirect(route('preventive-maintenance.tasks.show', $occurrence));
        $this->actingAs($this->head)->post(route('preventive-maintenance.tasks.corrective-work-order', $occurrence), $payload)
            ->assertRedirect(route('preventive-maintenance.tasks.show', $occurrence));
        $this->assertSame($workOrder->id, $occurrence->correctiveWorkOrders()->sole()->id);
    }

    public function test_staff_scope_and_qr_only_allow_assigned_personnel_to_start(): void
    {
        $plan = $this->plan('2026-11-04', 'DAILY');
        app(PreventiveMaintenanceOccurrenceService::class)->generate(CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-04'));
        $occurrence = $plan->occurrences()->firstOrFail();

        $this->actingAs($this->staffA)->get(route('preventive-maintenance.tasks.index'))->assertOk()->assertSee('Guidance Office Air Conditioner');
        $this->actingAs($this->staffB)->get(route('preventive-maintenance.tasks.index'))->assertOk()->assertDontSee('Guidance Office Air Conditioner');
        $this->actingAs($this->staffB)->post(route('preventive-maintenance.tasks.start', $occurrence))->assertForbidden();
        $this->actingAs($this->staffA)->get(route('assets.scan', $this->asset->qr_token))->assertOk()->assertSee('Start Preventive Maintenance');
        $this->actingAs($this->staffB)->get(route('assets.scan', $this->asset->qr_token))->assertOk()->assertSee('only assigned personnel')->assertDontSee('Start Preventive Maintenance');
    }

    public function test_execution_updates_condition_records_history_and_creates_corrective_work_order(): void
    {
        Storage::fake('local');
        $plan = $this->plan('2026-11-04', 'DAILY', [], true);
        app(PreventiveMaintenanceOccurrenceService::class)->generate(CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-04'));
        $occurrence = $plan->occurrences()->firstOrFail();
        $this->actingAs($this->staffA)->post(route('preventive-maintenance.tasks.start', $occurrence))->assertRedirect();
        $this->actingAs($this->staffA)->post(route('preventive-maintenance.tasks.complete', $occurrence), [
            'checklist' => ['ITEM_1' => 'OK', 'ITEM_2' => 'NEEDS_REPAIR'],
            'guided_actions' => ['CLEAN_FILTER', 'TEST_OPERATION'], 'guided_outcome' => 'RESOLVED', 'condition_after' => 'NEEDS_REPAIR',
            'findings' => 'Drain line partially blocked.', 'action_performed' => 'Cleaned filter and tested cooling operation.',
            'recommended_corrective_action' => 'Clear or replace the drain line.',
            'replacement_component' => ['Air filter'], 'replacement_old_condition' => ['Clogged'], 'replacement_remarks' => ['Installed matching replacement filter.'],
            'before_photos' => [UploadedFile::fake()->image('before.jpg', 900, 700)],
            'after_photos' => [UploadedFile::fake()->image('after.jpg', 900, 700)],
        ])->assertRedirect();

        $occurrence->refresh();
        $this->assertSame(PreventiveMaintenanceStatus::Completed, $occurrence->status);
        $this->assertSame('NEEDS_REPAIR', $this->asset->fresh()->condition->value);
        $this->assertSame(2, $occurrence->execution->checklistResults()->count());
        $this->assertStringContainsString('Cleaned the filter', $occurrence->execution->generated_summary);
        $this->assertSame(2, $occurrence->execution->attachments()->count());
        $this->assertEqualsCanonicalizing(['PM_BEFORE', 'PM_AFTER'], $occurrence->execution->attachments()->pluck('purpose')->all());
        $maintenanceHistory = collect(app(AssetMaintenanceHistoryService::class)->paginate($this->asset)->items());
        $this->assertTrue($maintenanceHistory->contains('type', 'PM_COMPLETED'));
        $this->assertTrue($maintenanceHistory->contains('type', 'COMPONENT_REPLACED'));

        $this->actingAs($this->staffA)->post(route('preventive-maintenance.tasks.corrective-work-order', $occurrence), [
            'subject' => 'Repair Air Conditioner Drain Line',
        ])->assertRedirect();
        $workOrder = $occurrence->correctiveWorkOrders()->firstOrFail();
        $this->assertSame($this->asset->id, $workOrder->inspectable_asset_id);
        $this->assertSame($occurrence->id, $workOrder->preventive_maintenance_occurrence_id);
        $this->assertSame('SUBMITTED', $workOrder->status->value);
        $this->actingAs($this->head)->get(route('work-orders.show', $workOrder))->assertOk()->assertSee('Originating Preventive Maintenance');
    }

    public function test_parent_system_history_and_due_notification_include_child_pm(): void
    {
        $parent = $this->asset->replicate(['asset_code', 'qr_token']);
        $parent->fill(['asset_code' => 'WATER-SYS-001', 'name' => 'Water Desalination System', 'asset_class' => 'UTILITY_SYSTEM', 'qr_token' => null])->save();
        $this->asset->update(['parent_asset_id' => $parent->id]);
        $plan = $this->plan('2026-11-04', 'DAILY');
        app(PreventiveMaintenanceOccurrenceService::class)->generate(CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-04'));
        $occurrence = $plan->occurrences()->firstOrFail();
        app(PreventiveMaintenanceNoticeService::class)->send();
        app(PreventiveMaintenanceNoticeService::class)->send();

        $this->assertSame(1, $this->staffA->notifications()->where('data->event_key', 'PM_DUE')->count());
        $this->assertSame(0, $this->staffB->notifications()->where('data->event_key', 'PM_DUE')->count());
        $occurrence->update(['status' => PreventiveMaintenanceStatus::InProgress]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 09:00:00', 'Asia/Manila'));
        app(PreventiveMaintenanceNoticeService::class)->send();
        app(PreventiveMaintenanceNoticeService::class)->send();
        $this->assertSame(1, $this->staffA->notifications()->where('data->event_key', 'PM_OVERDUE')->count());
        $this->assertSame(0, $this->staffB->notifications()->where('data->event_key', 'PM_OVERDUE')->count());
        $this->assertTrue(collect(app(AssetMaintenanceHistoryService::class)->paginate($parent)->items())->contains(fn ($event) => $event['asset'] === $this->asset->name));
        $this->actingAs($this->head)->get(route('assets.show', $parent))->assertOk()->assertSee('Water Desalination System')->assertSee('Guidance Office Air Conditioner');
        $this->assertSame(PreventiveMaintenanceStatus::InProgress, $occurrence->fresh()->status);
    }

    public function test_requester_is_forbidden_and_api_enforces_assignment_and_availability(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-30 09:00:00', 'Asia/Manila'));
        $requester = $this->user('Requester');
        $plan = $this->plan('2026-11-01', 'MONTHLY', ['day' => 1]);
        app(PreventiveMaintenanceOccurrenceService::class)->generate(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-01'));
        $occurrence = $plan->occurrences()->firstOrFail();

        $this->actingAs($requester)->get(route('preventive-maintenance.dashboard'))->assertForbidden();
        $this->actingAs($requester)->get(route('preventive-maintenance.tasks.index'))->assertForbidden();
        app('auth')->forgetGuards();
        $tokenA = $this->staffA->createToken('pm-assigned-test')->plainTextToken;
        $this->withToken($tokenA)->getJson('/api/v1/preventive-maintenance/tasks')
            ->assertOk()->assertJsonPath('data.data.0.id', $occurrence->id);
        $this->withToken($tokenA)->postJson('/api/v1/preventive-maintenance/tasks/'.$occurrence->id.'/start')
            ->assertUnprocessable()->assertJsonValidationErrors('task');
        app('auth')->forgetGuards();
        $tokenB = $this->staffB->createToken('pm-unassigned-test')->plainTextToken;
        $this->withToken($tokenB)->getJson('/api/v1/preventive-maintenance/tasks/'.$occurrence->id)->assertForbidden();
        $this->withToken($tokenB)->postJson('/api/v1/preventive-maintenance/tasks/'.$occurrence->id.'/start')->assertForbidden();
    }

    public function test_plan_edit_keeps_in_progress_performer_and_never_rewrites_completed_assignees(): void
    {
        $plan = $this->plan('2026-11-03', 'DAILY');
        $service = app(PreventiveMaintenanceOccurrenceService::class);
        $service->generate(CarbonImmutable::parse('2026-11-03'), CarbonImmutable::parse('2026-11-05'));
        $completed = $plan->occurrences()->where('period_key', '2026-11-03')->sole();
        $completed->update(['status' => PreventiveMaintenanceStatus::Completed, 'completed_at' => now()]);
        $inProgress = $plan->occurrences()->where('period_key', '2026-11-04')->sole();
        $this->actingAs($this->staffA)->post(route('preventive-maintenance.tasks.start', $inProgress))->assertRedirect();
        $upcoming = $plan->occurrences()->where('period_key', '2026-11-05')->sole();
        $personB = $this->staffB->personnelProfile;

        $this->actingAs($this->head)->put(route('preventive-maintenance.plans.update', $plan), [
            'inspectable_asset_id' => $this->asset->id, 'name' => $plan->name, 'maintenance_type' => 'PREVENTIVE',
            'frequency_type' => 'DAILY', 'interval_value' => 1, 'start_date' => '2026-11-03',
            'available_time' => '08:00', 'due_time' => '17:00', 'checklist_label' => ['Clean filter', 'Test cooling performance'],
            'assignee_ids' => [$personB->id], 'is_active' => 1,
        ])->assertRedirect(route('preventive-maintenance.plans.index'));
        $service->generate(CarbonImmutable::parse('2026-11-03'), CarbonImmutable::parse('2026-11-05'));

        $this->assertEqualsCanonicalizing([$this->personA->id], $completed->assignees()->pluck('fmo_personnel.id')->all());
        $this->assertEqualsCanonicalizing([$this->personA->id, $personB->id], $inProgress->assignees()->pluck('fmo_personnel.id')->all());
        $this->assertEqualsCanonicalizing([$personB->id], $upcoming->assignees()->pluck('fmo_personnel.id')->all());
        $this->assertTrue(app(PreventiveMaintenanceAccessService::class)->canPerform($inProgress->fresh(), $this->staffA));
    }

    public function test_compliance_counts_only_occurrences_already_due(): void
    {
        $plan = $this->plan('2026-11-03', 'DAILY');
        app(PreventiveMaintenanceOccurrenceService::class)->generate(CarbonImmutable::parse('2026-11-03'), CarbonImmutable::parse('2026-11-04'));
        $plan->occurrences()->where('period_key', '2026-11-04')->update(['status' => PreventiveMaintenanceStatus::Completed->value, 'completed_at' => now()]);

        $this->actingAs($this->head)->get(route('preventive-maintenance.dashboard'))->assertOk()->assertViewHas('compliance', fn (?float $compliance): bool => $compliance === 0.0);
    }

    public function test_history_export_includes_every_row_beyond_the_first_batch(): void
    {
        $plan = $this->plan('2026-11-04', 'DAILY');
        app(PreventiveMaintenanceOccurrenceService::class)->generate(CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2027-11-04'));
        $total = $plan->occurrences()->count();
        $this->assertGreaterThan(200, $total);

        $csv = $this->actingAs($this->head)->get(route('preventive-maintenance.history.export'))->assertOk()->streamedContent();

        $this->assertSame($total + 1, count(array_filter(explode("\n", $csv))));
    }

    public function test_asset_history_excludes_future_pm_and_duplicate_lifecycle_entries(): void
    {
        $plan = $this->plan('2026-11-04', 'DAILY');
        app(PreventiveMaintenanceOccurrenceService::class)->generate(CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-10'));
        $occurrence = $plan->occurrences()->where('period_key', '2026-11-04')->sole();
        $this->actingAs($this->staffA)->post(route('preventive-maintenance.tasks.start', $occurrence))->assertRedirect();
        $this->actingAs($this->staffA)->post(route('preventive-maintenance.tasks.complete', $occurrence), [
            'checklist' => ['ITEM_1' => 'OK', 'ITEM_2' => 'OK'], 'condition_after' => 'GOOD',
            'guided_actions' => ['CLEAN_FILTER'], 'guided_outcome' => 'RESOLVED',
        ])->assertRedirect();

        $history = app(AssetMaintenanceHistoryService::class)->paginate($this->asset);

        $this->assertSame(1, $history->total());
        $this->assertSame(['PM_COMPLETED'], collect($history->items())->pluck('type')->all());
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'APPROVED']);
        $user->assignRole($role);

        return $user;
    }

    private function person(User $user, string $identifier): FmoPersonnel
    {
        return FmoPersonnel::create(['user_id' => $user->id, 'personnel_identifier' => $identifier, 'designation' => 'Facilities Staff', 'personnel_status' => 'ACTIVE']);
    }

    private function plan(string $start, string $frequency, array $recurrence = [], bool $evidenceRequired = false): PreventiveMaintenancePlan
    {
        $plan = PreventiveMaintenancePlan::create([
            'inspectable_asset_id' => $this->asset->id, 'name' => 'Quarterly Air Conditioner Preventive Maintenance',
            'maintenance_type' => 'PREVENTIVE', 'frequency_type' => $frequency, 'interval_value' => 1,
            'recurrence_config' => $recurrence, 'start_date' => $start, 'available_time' => '08:00', 'due_time' => '17:00',
            'checklist_template' => [['key' => 'ITEM_1', 'label' => 'Clean filter'], ['key' => 'ITEM_2', 'label' => 'Test cooling performance']],
            'reading_template' => [['key' => 'READING_1', 'label' => 'Temperature', 'unit' => '°C']],
            'evidence_required' => $evidenceRequired, 'verification_required' => false, 'is_active' => true, 'created_by' => $this->head->id,
        ]);
        $plan->activeAssignees()->attach($this->personA->id, ['assigned_by' => $this->head->id, 'assigned_at' => now(), 'active_marker' => 1]);

        return $plan;
    }
}
