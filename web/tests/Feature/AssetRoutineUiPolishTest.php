<?php

namespace Tests\Feature;

use App\Models\AssetType;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\InspectableAsset;
use App\Models\RoutineTaskOccurrence;
use App\Models\RoutineTaskTemplate;
use App\Models\RoutineTaskTemplateAssignment;
use App\Models\User;
use App\Services\RoutineOccurrenceService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\RoutineOperationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetRoutineUiPolishTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $staffA;

    private User $staffB;

    private FmoPersonnel $personA;

    private FmoPersonnel $personB;

    private Campus $campus;

    private Building $building;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-17 10:00:00', 'Asia/Manila'));
        $this->seed([AuthorizationSeeder::class, RoutineOperationsSeeder::class]);
        $this->head = $this->user('FMO Head');
        $this->staffA = $this->user('FMO Staff');
        $this->staffB = $this->user('FMO Staff');
        $this->personA = $this->person($this->staffA, 'FMO-UI-001');
        $this->personB = $this->person($this->staffB, 'FMO-UI-002');
        $this->campus = Campus::create(['code' => 'UI', 'name' => 'UI Test Campus', 'is_active' => true]);
        $this->building = Building::create(['campus_id' => $this->campus->id, 'code' => 'ADM', 'name' => 'Administration Building', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_asset_pages_use_the_shared_unclipped_action_menu(): void
    {
        $type = AssetType::where('code', 'WF')->firstOrFail();
        $asset = $this->asset($type, 'WF-UI-001');

        $this->actingAs($this->head)->get(route('assets.index'))->assertOk()
            ->assertSee('data-action-menu', false)->assertSee('More actions for WF-UI-001')
            ->assertSee('Edit Asset')->assertSee('Print QR Label')->assertSee('Manage Inspection Assignment');

        $this->actingAs($this->head)->get(route('assets.show', $asset))->assertOk()
            ->assertSee('sm:flex-nowrap', false)->assertSee('More asset actions')
            ->assertSee('Regenerate QR')->assertSee('View Inspection History')
            ->assertSee('data-action-menu-panel', false)->assertDontSee('<details class="relative">', false);
    }

    public function test_recurring_task_filters_work_together_and_render_compact_actions(): void
    {
        $target = $this->generalTemplate('Routine Filter Target', $this->personA, ['frequency' => 'DAILY']);
        $this->generalTemplate('Unrelated Inactive Schedule', $this->personB, ['frequency' => 'MONTHLY', 'is_active' => false]);

        $this->actingAs($this->head)->get(route('routine-templates.index', [
            'q' => 'Routine Filter Target', 'type' => 'GENERAL', 'frequency' => 'DAILY', 'status' => 'ACTIVE',
            'campus' => $this->campus->id, 'building' => $this->building->id,
        ]))->assertOk()->assertSee($target->title)->assertDontSee('Unrelated Inactive Schedule')
            ->assertSee('Active Templates')->assertSee('Due Today')->assertSee('Inspection Schedules')
            ->assertSee('New Recurring Task')->assertSee('View Task History')
            ->assertSee('Edit Template')->assertSee('Assign Team')->assertSee('View Occurrences')->assertSee('Deactivate')
            ->assertSee('data-action-menu', false);
    }

    public function test_my_routine_tasks_are_grouped_and_isolated_to_the_assigned_staff_member(): void
    {
        $overdue = $this->generalTemplate('Overdue assigned work', $this->personA);
        $due = $this->generalTemplate('Due today assigned work', $this->personA);
        $upcoming = $this->generalTemplate('Upcoming assigned work', $this->personA);
        $completed = $this->generalTemplate('Completed today assigned work', $this->personA);
        $outsider = $this->generalTemplate('Staff B private work', $this->personB);

        $this->occurrence($overdue, '2026-10-16', '2026-10-16 08:00:00', 'OVERDUE');
        $this->occurrence($due, '2026-10-17', '2026-10-17 16:00:00', 'DUE');
        $this->occurrence($upcoming, '2026-10-18', '2026-10-18 16:00:00', 'UPCOMING');
        $this->occurrence($completed, '2026-10-17-complete', '2026-10-17 09:00:00', 'COMPLETED', now());
        $this->occurrence($outsider, '2026-10-17-private', '2026-10-17 16:00:00', 'DUE');

        $this->actingAs($this->staffA)->get(route('routine-tasks.index'))->assertOk()
            ->assertSeeInOrder(['Overdue — Update Required', 'Overdue assigned work', 'Due Today', 'Due today assigned work', 'Upcoming', 'Upcoming assigned work', 'Completed Today', 'Completed today assigned work'])
            ->assertSee('Update Overdue Task')->assertSee('Start Task')->assertSee('View Completed Task')
            ->assertDontSee('Staff B private work');

        $this->actingAs($this->staffB)->get(route('routine-tasks.index'))->assertOk()
            ->assertSee('Staff B private work')->assertDontSee('Overdue assigned work');
    }

    public function test_assigned_inspection_card_shows_scoped_two_of_five_progress(): void
    {
        $type = AssetType::where('code', 'WF')->firstOrFail();
        foreach (range(1, 5) as $number) {
            $this->asset($type, 'WF-PROGRESS-00'.$number);
        }
        $template = RoutineTaskTemplate::create([
            'title' => 'Monthly Wall Fan Inspection', 'task_type' => 'INSPECTION', 'frequency' => 'MONTHLY',
            'recurrence_config' => ['day' => 17], 'campus_id' => $this->campus->id, 'building_id' => $this->building->id,
            'asset_type_id' => $type->id, 'inspection_asset_scope' => 'ALL_TYPE', 'start_date' => '2026-10-01',
            'due_time' => '16:00', 'evidence_required' => false, 'requires_review' => false, 'is_active' => true, 'created_by' => $this->head->id,
        ]);
        $this->assign($template, $this->personA);
        app(RoutineOccurrenceService::class)->generate(CarbonImmutable::parse('2026-10-17'), CarbonImmutable::parse('2026-10-17'));
        $run = $template->occurrences()->firstOrFail()->inspectionRun;
        $run->items()->limit(2)->get()->each->update([
            'status' => 'INSPECTED', 'inspector_personnel_id' => $this->personA->id,
            'inspector_user_id' => $this->staffA->id, 'inspected_at' => now(), 'result' => 'GOOD',
        ]);

        $this->actingAs($this->staffA)->get(route('routine-tasks.index'))->assertOk()
            ->assertSee('Monthly Wall Fan Inspection')->assertSee('5 assets assigned to you')
            ->assertSee('2 / 5 inspected')->assertSee('3 remaining')->assertSee('Continue Inspection')
            ->assertSee('role="progressbar"', false);
        $this->actingAs($this->staffB)->get(route('routine-tasks.index'))->assertOk()->assertDontSee('Monthly Wall Fan Inspection');
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

    private function asset(AssetType $type, string $code): InspectableAsset
    {
        return InspectableAsset::create([
            'asset_code' => $code, 'asset_type_id' => $type->id, 'name' => 'Wall Fan', 'campus_id' => $this->campus->id,
            'building_id' => $this->building->id, 'exact_placement' => 'Registrar Entrance', 'inspection_frequency' => 'MONTHLY',
            'status' => 'ACTIVE', 'condition' => 'GOOD', 'lifecycle_status' => 'ACTIVE',
        ]);
    }

    private function generalTemplate(string $title, FmoPersonnel $person, array $overrides = []): RoutineTaskTemplate
    {
        $template = RoutineTaskTemplate::create(array_merge([
            'title' => $title, 'task_type' => 'GENERAL', 'frequency' => 'DAILY', 'recurrence_config' => [],
            'campus_id' => $this->campus->id, 'building_id' => $this->building->id, 'start_date' => '2026-10-01',
            'due_time' => '16:00', 'evidence_required' => false, 'requires_review' => false, 'is_active' => true, 'created_by' => $this->head->id,
        ], $overrides));
        $this->assign($template, $person);

        return $template;
    }

    private function assign(RoutineTaskTemplate $template, FmoPersonnel $person): void
    {
        RoutineTaskTemplateAssignment::create([
            'routine_task_template_id' => $template->id, 'fmo_personnel_id' => $person->id,
            'assigned_by' => $this->head->id, 'assigned_at' => now(), 'active_marker' => 1,
        ]);
    }

    private function occurrence(RoutineTaskTemplate $template, string $period, string $dueAt, string $status, mixed $completedAt = null): RoutineTaskOccurrence
    {
        return RoutineTaskOccurrence::create([
            'routine_task_template_id' => $template->id, 'period_key' => $period,
            'expected_date' => substr($dueAt, 0, 10), 'due_at' => $dueAt, 'status' => $status, 'completed_at' => $completedAt,
        ]);
    }
}
