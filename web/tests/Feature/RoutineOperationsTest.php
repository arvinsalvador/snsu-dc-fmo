<?php

namespace Tests\Feature;

use App\Enums\RoutineOccurrenceStatus;
use App\Enums\WorkOrderStatus;
use App\Models\AssetType;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\GuidedDescriptionProfile;
use App\Models\InspectableAsset;
use App\Models\RoutineTaskTemplate;
use App\Models\RoutineTaskTemplateAssignment;
use App\Models\Skill;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Services\GuidedDescriptionService;
use App\Services\RoutineOccurrenceService;
use App\Services\WorkOrderAssignmentService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\RoutineOperationsSeeder;
use Database\Seeders\WorkOrderCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoutineOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    private Building $building;

    private User $head;

    private User $staff;

    private FmoPersonnel $personnel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AuthorizationSeeder::class, WorkOrderCategorySeeder::class, RoutineOperationsSeeder::class]);
        $this->campus = Campus::create(['code' => 'DC', 'name' => 'Del Carmen', 'is_active' => true]);
        $this->building = Building::create(['campus_id' => $this->campus->id, 'code' => 'ADM', 'name' => 'Administration', 'is_active' => true]);
        $this->head = $this->user('FMO Head');
        $this->staff = $this->user('FMO Staff');
        $this->personnel = $this->person($this->staff, 'FMO-001');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_management_can_create_assign_and_deactivate_a_template(): void
    {
        $profile = GuidedDescriptionProfile::where('code', 'CLEANING_SURROUNDINGS')->firstOrFail();
        $response = $this->actingAs($this->head)->post('/routine-templates', [
            'title' => 'Clean surroundings', 'task_type' => 'GENERAL', 'frequency' => 'DAILY',
            'campus_id' => $this->campus->id, 'building_id' => $this->building->id,
            'guided_description_profile_id' => $profile->id, 'start_date' => now()->toDateString(), 'due_time' => '16:00',
            'assignee_ids' => [$this->personnel->id], 'evidence_required' => '1', 'is_active' => '1',
        ]);
        $response->assertRedirect(route('routine-templates.index'));
        $template = RoutineTaskTemplate::where('title', 'Clean surroundings')->firstOrFail();
        $this->assertTrue($template->activeAssignees()->whereKey($this->personnel->id)->exists());
        $dispatcher = $this->user('FMO Dispatcher');
        $replacement = $this->person($this->user('FMO Staff'), 'FMO-003');
        $this->actingAs($dispatcher)->post('/routine-templates/'.$template->id.'/assignees', ['assignee_ids' => [$replacement->id]])->assertRedirect();
        $this->assertTrue($template->activeAssignees()->whereKey($replacement->id)->exists());
        $this->assertFalse($template->activeAssignees()->whereKey($this->personnel->id)->exists());
        $this->actingAs($this->head)->delete('/routine-templates/'.$template->id)->assertRedirect();
        $this->assertFalse($template->fresh()->is_active);
    }

    public function test_daily_weekly_monthly_generation_is_idempotent_and_backfills(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'Asia/Manila'));
        $daily = $this->template('Daily cleaning', 'DAILY', '2026-10-12');
        $weekly = $this->template('Weekly check', 'WEEKLY', '2026-10-01', ['weekday' => 3]);
        $monthly = $this->template('Monthly check', 'MONTHLY', '2026-01-14', ['day' => 14]);
        $service = app(RoutineOccurrenceService::class);
        $this->assertSame(5, $service->generate(CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-14')));
        $this->assertSame(0, $service->generate(CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-14')));
        $this->assertSame(3, $daily->occurrences()->count());
        $this->assertSame(1, $weekly->occurrences()->count());
        $this->assertSame(1, $monthly->occurrences()->count());
        $this->assertSame(RoutineOccurrenceStatus::Overdue, $daily->occurrences()->whereDate('expected_date', '2026-10-12')->firstOrFail()->status);
    }

    public function test_overdue_task_requires_accountability_evidence_and_assigned_scope(): void
    {
        Storage::fake('local');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'Asia/Manila'));
        $profile = GuidedDescriptionProfile::where('code', 'CLEANING_SURROUNDINGS')->firstOrFail();
        $template = $this->template('Clean grounds', 'DAILY', '2026-10-13', [], ['due_time' => '08:00', 'evidence_required' => true, 'guided_description_profile_id' => $profile->id]);
        app(RoutineOccurrenceService::class)->generate(CarbonImmutable::parse('2026-10-13'), CarbonImmutable::parse('2026-10-13'));
        $occurrence = $template->occurrences()->firstOrFail();
        $outsider = $this->user('FMO Staff');
        $this->person($outsider, 'FMO-002');
        $this->actingAs($outsider)->post('/routine-tasks/'.$occurrence->id.'/resolve', [])->assertForbidden();
        $payload = ['resolution' => 'COMPLETED_LATE', 'guided_actions' => ['SWEEP_AREA', 'REMOVE_LITTER'], 'guided_outcome' => 'RESOLVED',
            'generated_summary' => 'Swept the grounds and removed litter after an urgent response.', 'actual_completion_at' => '2026-10-14 10:00'];
        $this->actingAs($this->staff)->post('/routine-tasks/'.$occurrence->id.'/resolve', $payload)->assertSessionHasErrors('evidence');
        $this->actingAs($this->staff)->post('/routine-tasks/'.$occurrence->id.'/resolve', $payload + ['evidence' => [UploadedFile::fake()->image('completed.jpg', 900, 700)]])->assertRedirect();
        $occurrence->refresh();
        $this->assertSame(RoutineOccurrenceStatus::CompletedLate, $occurrence->status);
        $this->assertSame($this->personnel->id, $occurrence->performed_by_personnel_id);
        $this->assertSame(['SWEEP_AREA', 'REMOVE_LITTER'], $occurrence->structured_actions);
        $this->assertCount(1, $occurrence->attachments);
        Storage::disk('local')->assertExists($occurrence->attachments->first()->thumbnail_path);
    }

    public function test_inspection_snapshots_assets_and_completes_only_after_every_item(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Asia/Manila'));
        $type = AssetType::where('code', 'FE')->firstOrFail();
        foreach (range(1, 3) as $number) {
            InspectableAsset::create(['asset_code' => 'FE-DC-00'.$number, 'asset_type_id' => $type->id, 'name' => 'Fire Extinguisher',
                'campus_id' => $this->campus->id, 'building_id' => $this->building->id, 'inspection_frequency' => 'MONTHLY', 'status' => 'ACTIVE']);
        }
        $template = $this->template('Monthly Fire Extinguisher Inspection', 'MONTHLY', '2026-01-14', ['day' => 14], ['task_type' => 'INSPECTION', 'asset_type_id' => $type->id]);
        app(RoutineOccurrenceService::class)->generate(CarbonImmutable::parse('2026-10-14'), CarbonImmutable::parse('2026-10-14'));
        $run = $template->occurrences()->firstOrFail()->inspectionRun;
        $this->assertSame(3, $run->items()->count());
        $checklist = collect($type->checklist)->mapWithKeys(fn ($check) => [$check['code'] => 'YES'])->all();
        foreach ($run->items()->get() as $index => $item) {
            $result = $index === 0 ? 'NEEDS_ATTENTION' : 'GOOD';
            $this->actingAs($this->staff)->post("/inspections/{$run->id}/items/{$item->id}", ['result' => $result, 'checklist' => $checklist])->assertRedirect();
            $this->assertSame($index + 1, $run->items()->where('status', 'INSPECTED')->count());
            if ($index === 0) {
                $this->assertSame('INSPECTION_ATTENTION', $this->head->notifications()->firstOrFail()->data['event_key']);
            }
            if ($index < 2) {
                $this->assertNotSame('COMPLETED', $run->fresh()->status);
            }
        }
        $this->assertSame('COMPLETED', $run->fresh()->status);
        $this->assertSame(RoutineOccurrenceStatus::Completed, $run->occurrence->fresh()->status);
    }

    public function test_guided_summary_is_deterministic_and_work_order_stores_codes_and_editable_text(): void
    {
        $profile = GuidedDescriptionProfile::where('code', 'ELECTRICAL')->firstOrFail();
        $summary = app(GuidedDescriptionService::class)->generate($profile, ['INSPECT_AREA', 'REPLACE_OUTLET', 'TEST_REPAIR'], 'RESOLVED');
        $this->assertSame('Inspected the affected area, replaced the damaged electrical outlet, and tested the installation after repair. The reported issue is now working properly.', $summary);

        $requester = $this->user('Requester');
        $category = WorkOrderCategory::where('name', 'Electrical')->firstOrFail();
        $order = WorkOrder::create(['work_order_number' => 'WO-2026-900001', 'requester_id' => $requester->id, 'campus_id' => $this->campus->id,
            'building_id' => $this->building->id, 'work_order_category_id' => $category->id, 'subject' => 'Damaged outlet', 'description' => 'Outlet is damaged',
            'urgency' => 'NORMAL', 'status' => WorkOrderStatus::ReadyForWork, 'submitted_at' => now()]);
        app(WorkOrderAssignmentService::class)->add($order, $this->head, [$this->personnel->id]);
        $this->actingAs($this->staff)->post('/work-orders/'.$order->id.'/start-work')->assertRedirect();
        $session = $order->activeSessions()->firstOrFail();
        $edited = 'Inspected and replaced the outlet, then tested it with a load tester.';
        $this->actingAs($this->staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/updates", [
            'type' => 'PROGRESS', 'guided_actions' => ['INSPECT_AREA', 'REPLACE_OUTLET', 'TEST_REPAIR'], 'guided_outcome' => 'RESOLVED', 'description' => $edited,
        ])->assertRedirect();
        $update = $order->updates()->latest('recorded_at')->firstOrFail();
        $this->assertSame($edited, $update->description);
        $this->assertSame(['INSPECT_AREA', 'REPLACE_OUTLET', 'TEST_REPAIR'], $update->structured_actions);
        $this->assertSame($summary, $update->generated_summary);
    }

    public function test_routine_asset_inspection_pages_and_api_render_with_scoped_data(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 09:00:00', 'Asia/Manila'));
        $type = AssetType::where('code', 'FE')->firstOrFail();
        $asset = InspectableAsset::create(['asset_code' => 'FE-DC-099', 'asset_type_id' => $type->id, 'name' => 'Fire Extinguisher',
            'campus_id' => $this->campus->id, 'building_id' => $this->building->id, 'inspection_frequency' => 'MONTHLY', 'status' => 'ACTIVE']);
        $template = $this->template('Render inspection', 'MONTHLY', '2026-01-14', ['day' => 14], ['task_type' => 'INSPECTION', 'asset_type_id' => $type->id]);
        app(RoutineOccurrenceService::class)->generate(CarbonImmutable::parse('2026-10-14'), CarbonImmutable::parse('2026-10-14'));
        $occurrence = $template->occurrences()->firstOrFail();
        $run = $occurrence->inspectionRun;

        $this->actingAs($this->head)->get('/routine-templates')->assertOk()->assertSee('Render inspection');
        $this->actingAs($this->head)->get('/routine-templates/create')->assertOk()->assertSee('New Recurring Task');
        $this->actingAs($this->head)->get('/assets')->assertOk()->assertSee('FE-DC-099');
        $this->actingAs($this->head)->get('/assets/create')->assertOk()->assertSee('Add Inspectable Asset');
        $this->actingAs($this->head)->get('/assets/'.$asset->id)->assertOk()->assertSee('Inspection history');
        $this->actingAs($this->staff)->get('/routine-tasks')->assertOk()->assertSee('Render inspection');
        $this->actingAs($this->staff)->get('/inspections/'.$run->id)->assertOk()->assertSee('FE-DC-099');

        app('auth')->forgetGuards();
        $token = $this->staff->createToken('routine-test')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/routine-tasks')->assertOk()->assertJsonPath('data.0.id', $occurrence->id);
        $this->withToken($token)->getJson('/api/v1/inspection-runs/'.$run->id)->assertOk()->assertJsonPath('data.id', $run->id);
        $item = $run->items()->firstOrFail();
        $checklist = collect($type->checklist)->mapWithKeys(fn ($check) => [$check['code'] => 'YES'])->all();
        $this->withToken($token)->postJson("/api/v1/inspection-runs/{$run->id}/items/{$item->id}", ['result' => 'GOOD', 'checklist' => $checklist])
            ->assertOk()->assertJsonPath('progress.inspected', 1)->assertJsonPath('progress.total', 1);
        $this->withToken($token)->postJson('/api/v1/routine-tasks/'.$occurrence->id.'/resolve', ['resolution' => 'COMPLETED', 'generated_summary' => 'Bypass'])
            ->assertUnprocessable();
    }

    public function test_generation_command_sends_deduplicated_due_and_management_overdue_notices(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 18:00:00', 'Asia/Manila'));
        $this->template('End-of-day cleaning', 'DAILY', '2026-10-14', [], ['due_time' => '08:00']);
        $arguments = ['--from' => '2026-10-14', '--to' => '2026-10-14'];
        $this->assertSame(0, Artisan::call('routine:generate', $arguments));
        $this->assertDatabaseCount('routine_task_occurrences', 1);
        $this->assertSame('ROUTINE_OVERDUE', $this->staff->notifications()->firstOrFail()->data['event_key']);
        $this->assertSame('ROUTINE_OVERDUE', $this->head->notifications()->firstOrFail()->data['event_key']);
        $this->assertSame(0, Artisan::call('routine:generate', $arguments));
        $this->assertSame(1, $this->staff->notifications()->count());
        $this->assertSame(1, $this->head->notifications()->count());
    }

    public function test_staff_scope_follows_current_active_template_assignments_and_reassignment(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 09:00:00', 'Asia/Manila'));
        $staffB = $this->user('FMO Staff');
        $personnelB = $this->person($staffB, 'FMO-002');
        $staffC = $this->user('FMO Staff');
        $this->person($staffC, 'FMO-003');
        $taskA = $this->template('Staff A routine task', 'DAILY', '2026-10-15', [], ['end_date' => '2026-10-15']);
        $taskB = $this->template('Staff B routine task', 'DAILY', '2026-10-15', [], ['end_date' => '2026-10-15']);
        $taskB->assignments()->whereNull('unassigned_at')->update(['unassigned_at' => now(), 'active_marker' => null]);
        RoutineTaskTemplateAssignment::create(['routine_task_template_id' => $taskB->id, 'fmo_personnel_id' => $personnelB->id,
            'assigned_by' => $this->head->id, 'assigned_at' => now(), 'active_marker' => 1]);
        app(RoutineOccurrenceService::class)->generate(CarbonImmutable::parse('2026-10-15'), CarbonImmutable::parse('2026-10-15'));
        $occurrenceA = $taskA->occurrences()->firstOrFail();
        $occurrenceB = $taskB->occurrences()->firstOrFail();

        $this->actingAs($this->staff)->get('/routine-tasks')->assertOk()->assertSee('Staff A routine task')->assertDontSee('Staff B routine task');
        $this->actingAs($this->staff)->get('/routine-tasks/'.$occurrenceA->id)->assertOk();
        $this->actingAs($this->staff)->get('/routine-tasks/'.$occurrenceB->id)->assertForbidden();
        $this->actingAs($this->head)->get('/routine-tasks')->assertOk()->assertSee('Staff A routine task')->assertSee('Staff B routine task');

        $dispatcher = $this->user('FMO Dispatcher');
        $this->actingAs($dispatcher)->post('/routine-templates/'.$taskA->id.'/assignees', [
            'assignment_template_id' => $taskA->id,
            'assignee_ids' => [$this->personnel->id, $personnelB->id],
        ])->assertSessionHasNoErrors();
        $this->actingAs($staffB)->get('/routine-tasks')->assertOk()->assertSee('Staff A routine task')->assertSee('Staff B routine task');
        $this->actingAs($staffC)->get('/routine-tasks/'.$occurrenceA->id)->assertForbidden();

        $this->actingAs($dispatcher)->post('/routine-templates/'.$taskA->id.'/assignees', [
            'assignment_template_id' => $taskA->id,
            'assignee_ids' => [$personnelB->id],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->get('/routine-tasks')->assertOk()->assertDontSee('Staff A routine task');
        $this->actingAs($this->staff)->get('/routine-tasks/'.$occurrenceA->id)->assertForbidden();
        $this->actingAs($staffB)->get('/routine-tasks/'.$occurrenceA->id)->assertOk();

        app('auth')->forgetGuards();
        $token = $this->staff->createToken('removed-assignee')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/routine-tasks/'.$occurrenceA->id)->assertForbidden();
    }

    public function test_overdue_occurrences_are_prominent_counted_and_removed_when_resolved(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Manila'));
        $overdueTemplate = $this->template('Yesterday grounds check', 'DAILY', '2026-10-14', [], ['end_date' => '2026-10-14', 'due_time' => '08:00']);
        $todayTemplate = $this->template('Today grounds check', 'DAILY', '2026-10-15', [], ['end_date' => '2026-10-15', 'due_time' => '17:00']);
        app(RoutineOccurrenceService::class)->generate(CarbonImmutable::parse('2026-10-14'), CarbonImmutable::parse('2026-10-15'));
        $overdue = $overdueTemplate->occurrences()->firstOrFail();

        $this->actingAs($this->staff)->get('/routine-tasks')->assertOk()
            ->assertSeeInOrder(['Overdue — Update Required', 'Yesterday grounds check', 'Due Today', 'Today grounds check'])
            ->assertSee('1 day overdue')->assertSee('Update Task')->assertSee('border-rose-300', false);
        $this->actingAs($this->head)->get('/routine-templates')->assertOk()
            ->assertSee('Yesterday grounds check')->assertSee('1 occurrence')->assertSee('1 overdue');

        $this->actingAs($this->staff)->post('/routine-tasks/'.$overdue->id.'/resolve', [
            'resolution' => 'COMPLETED', 'generated_summary' => 'Completed the grounds check and recorded the result.',
        ])->assertRedirect();
        $this->assertSame(RoutineOccurrenceStatus::CompletedLate, $overdue->fresh()->status);
        $this->actingAs($this->staff)->get('/routine-tasks?status=OVERDUE')->assertOk()->assertDontSee('Yesterday grounds check');
        $this->assertSame(0, $overdueTemplate->occurrences()->unresolvedOverdue()->count());
    }

    public function test_assign_team_modal_exposes_safe_staff_context_and_validates_team(): void
    {
        $skill = Skill::create(['name' => 'Electrical', 'is_active' => true]);
        $this->personnel->skills()->attach($skill->id, ['is_primary' => true]);
        $staffB = $this->user('FMO Staff');
        $personnelB = $this->person($staffB, 'FMO-002');
        $inactive = $this->person($this->user('FMO Staff'), 'FMO-003');
        $inactive->update(['personnel_status' => 'INACTIVE']);
        $template = $this->template('Electrical room check', 'WEEKLY', now()->toDateString(), ['weekday' => 4]);

        $this->actingAs($this->head)->get('/routine-templates')->assertOk()
            ->assertSee('role="dialog"', false)->assertSee('aria-modal="true"', false)
            ->assertSee('data-assignment-modal', false)->assertSee('max-w-2xl', false)
            ->assertSee('Electrical')->assertSee('active routine task')->assertSee('Unavailable')
            ->assertSee($this->personnel->id);

        $this->actingAs($this->head)->post('/routine-templates/'.$template->id.'/assignees', [
            'assignment_template_id' => $template->id,
            'assignee_ids' => [$this->personnel->id, $personnelB->id],
            'assignment_note' => 'Pair for weekly safety coverage.',
        ])->assertRedirect()->assertSessionHas('status', 'Routine task assignment updated.');
        $this->assertSame(2, $template->activeAssignees()->count());
        $this->assertSame('Pair for weekly safety coverage.', $template->fresh()->assignment_note);

        $this->actingAs($this->head)->from('/routine-templates')->post('/routine-templates/'.$template->id.'/assignees', [
            'assignment_template_id' => $template->id,
            'assignee_ids' => [$inactive->id],
        ])->assertRedirect('/routine-templates')->assertSessionHasErrors('assignee_ids.0');
        $this->assertSame(2, $template->activeAssignees()->count());
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

    private function template(string $title, string $frequency, string $start, array $recurrence = [], array $overrides = []): RoutineTaskTemplate
    {
        $template = RoutineTaskTemplate::create(array_merge(['title' => $title, 'task_type' => 'GENERAL', 'frequency' => $frequency, 'recurrence_config' => $recurrence,
            'campus_id' => $this->campus->id, 'building_id' => $this->building->id, 'start_date' => $start, 'due_time' => '17:00',
            'evidence_required' => false, 'requires_review' => false, 'is_active' => true, 'created_by' => $this->head->id], $overrides));
        RoutineTaskTemplateAssignment::create(['routine_task_template_id' => $template->id, 'fmo_personnel_id' => $this->personnel->id,
            'assigned_by' => $this->head->id, 'assigned_at' => now(), 'active_marker' => 1]);

        return $template;
    }
}
