<?php

namespace Tests\Feature;

use App\Models\AssetActionRequest;
use App\Models\AssetType;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\InspectableAsset;
use App\Models\RoutineTaskTemplate;
use App\Models\User;
use App\Services\AssetLifecycleService;
use App\Services\RoutineOccurrenceService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\RoutineOperationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AssetInspectionStabilizationTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $staffA;

    private User $staffB;

    private FmoPersonnel $personA;

    private Campus $campus;

    private Building $building;

    private AssetType $fanType;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 18:00:00', 'Asia/Manila'));
        $this->seed([AuthorizationSeeder::class, RoutineOperationsSeeder::class]);
        $this->head = $this->user('FMO Head');
        $this->staffA = $this->user('FMO Staff');
        $this->staffB = $this->user('FMO Staff');
        $this->personA = $this->person($this->staffA, 'FMO-WF-001');
        $this->person($this->staffB, 'FMO-WF-002');
        $this->campus = Campus::create(['code' => 'DC', 'name' => 'Del Carmen Campus', 'is_active' => true]);
        $this->building = Building::create(['campus_id' => $this->campus->id, 'code' => 'LRC', 'name' => 'LRC Building', 'is_active' => true]);
        $this->fanType = AssetType::where('code', 'WF')->firstOrFail();
        $this->fanType->update([
            'name' => 'Wall Fan', 'description' => 'Wall-mounted ventilation equipment',
            'default_inspection_frequency' => 'MONTHLY', 'checklist' => [['code' => 'CHECK_01', 'label' => 'Fan operates safely']],
            'result_options' => ['GOOD', 'NEEDS_ATTENTION', 'NEEDS_REPAIR'], 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_asset_registry_filters_combine_and_render_responsive_controls(): void
    {
        $target = $this->asset('WF-TEST-001', 'Registrar wall fan', ['condition' => 'FAIR', 'serial_number' => 'WF-SERIAL-001']);
        $otherCampus = Campus::create(['code' => 'MC', 'name' => 'Main Campus', 'is_active' => true]);
        $otherBuilding = Building::create(['campus_id' => $otherCampus->id, 'code' => 'ADM', 'name' => 'Administration Building', 'is_active' => true]);
        $other = $this->asset('WF-TEST-002', 'Canteen wall fan', ['campus_id' => $otherCampus->id, 'building_id' => $otherBuilding->id, 'condition' => 'GOOD']);
        AssetActionRequest::create([
            'inspectable_asset_id' => $target->id, 'type' => 'REPAIR', 'status' => 'PENDING', 'requested_by' => $this->staffA->id,
            'requested_at' => now(), 'problem_description' => 'Guard is loose.', 'recommended_action' => 'Assess and repair.',
        ]);

        $this->actingAs($this->head)->get(route('assets.index', [
            'q' => 'WF-SERIAL-001', 'type' => $this->fanType->id, 'campus' => $this->campus->id,
            'building' => $this->building->id, 'condition' => 'FAIR', 'lifecycle' => 'ACTIVE', 'pending' => 1,
        ]))->assertOk()->assertSee('WF-TEST-001')->assertDontSee('WF-TEST-002')
            ->assertSee('Search code, type, name or location...')->assertSee('Pending Review')
            ->assertSee('data-asset-filters', false)->assertSee('data-campus="'.$this->campus->id.'"', false)
            ->assertSee('Not yet inspected')->assertSee('View Asset');

        $this->assertNotSame($target->id, $other->id);
    }

    public function test_asset_type_search_status_and_frequency_filters_work_together(): void
    {
        AssetType::create(['code' => 'WP', 'name' => 'Water Pump', 'description' => 'Primary water equipment',
            'default_inspection_frequency' => 'DAILY', 'checklist' => [['code' => 'CHECK_01', 'label' => 'Pump operates']], 'is_active' => true]);
        AssetType::create(['code' => 'OLD', 'name' => 'Old Water Meter', 'description' => 'Inactive water equipment',
            'default_inspection_frequency' => 'MONTHLY', 'checklist' => [['code' => 'CHECK_01', 'label' => 'Meter is readable']], 'is_active' => false]);

        $this->actingAs($this->head)->get(route('asset-types.index', ['q' => 'water', 'status' => 'ACTIVE', 'frequency' => 'DAILY']))
            ->assertOk()->assertSee('Water Pump')->assertDontSee('Old Water Meter')
            ->assertSee('Search asset types...')->assertSee('Assets Registered')->assertSee('checklist item');
    }

    public function test_lifecycle_is_workflow_controlled_and_invalid_direct_transition_is_rejected(): void
    {
        $asset = $this->asset('WF-TEST-001', 'Registrar wall fan');
        $payload = $this->assetPayload($asset) + ['lifecycle_status' => 'DISPOSED'];
        $this->actingAs($this->head)->put(route('assets.update', $asset), $payload)->assertRedirect(route('assets.show', $asset));
        $this->assertSame('ACTIVE', $asset->fresh()->lifecycle_status->value);
        $this->actingAs($this->staffA)->put(route('assets.update', $asset), $payload)->assertForbidden();

        $this->expectException(ValidationException::class);
        app(AssetLifecycleService::class)->transition($asset->fresh(), $this->head, 'INVALID_DIRECT_DISPOSAL', 'DISPOSED');
    }

    public function test_current_monthly_inspection_is_generated_visible_scoped_notified_and_idempotent(): void
    {
        $asset = $this->asset('WF-TEST-001', 'Registrar wall fan');
        $response = $this->actingAs($this->head)->post(route('routine-templates.store'), [
            'title' => 'Monthly Wall Fan Inspection', 'task_type' => 'INSPECTION', 'frequency' => 'MONTHLY',
            'month_day' => 1, 'start_date' => '2026-10-01', 'due_time' => '08:00',
            'asset_type_id' => $this->fanType->id, 'inspection_asset_scope' => 'SPECIFIC',
            'selected_asset_ids' => [$asset->id], 'assignee_ids' => [$this->personA->id], 'is_active' => '1',
        ]);
        $response->assertRedirect(route('routine-templates.index'))->assertSessionHasNoErrors();
        $template = RoutineTaskTemplate::where('title', 'Monthly Wall Fan Inspection')->firstOrFail();
        $occurrence = $template->occurrences()->with('inspectionRun.items.activeAssignees')->firstOrFail();
        $run = $occurrence->inspectionRun;
        $item = $run->items->firstOrFail();
        $this->assertSame('2026-10', $occurrence->period_key);
        $this->assertSame($asset->id, $item->inspectable_asset_id);
        $this->assertEqualsCanonicalizing([$this->personA->id], $item->activeAssignees->modelKeys());

        $this->actingAs($this->staffA)->get(route('routine-tasks.index'))->assertOk()
            ->assertSee('Monthly Wall Fan Inspection')->assertSee('MONTHLY')->assertSee('1 asset assigned to you')->assertSee('Continue Inspection')->assertSee('Overdue');
        $this->actingAs($this->staffA)->get(route('inspections.index'))->assertOk()
            ->assertSeeInOrder(['Overdue', 'Monthly Wall Fan Inspection'])->assertSee('1 asset assigned to you');
        $this->actingAs($this->staffB)->get(route('routine-tasks.index'))->assertOk()->assertDontSee('Monthly Wall Fan Inspection');
        $this->actingAs($this->staffB)->get(route('inspections.index'))->assertOk()->assertDontSee('Monthly Wall Fan Inspection');
        $this->actingAs($this->staffB)->post(route('inspections.items.record', [$run, $item]), [])->assertForbidden();
        $this->assertSame('INSPECTION_OVERDUE', $this->staffA->notifications()->firstOrFail()->data['event_key']);
        $this->assertCount(0, $this->staffB->notifications);

        $arguments = ['--from' => '2026-10-01', '--to' => '2026-10-16'];
        $this->assertSame(0, Artisan::call('routine:generate', $arguments));
        $this->assertSame(1, $template->occurrences()->count());
        $this->assertSame(1, $run->items()->count());
        $this->assertSame(1, $item->assignments()->whereNull('unassigned_at')->count());
        $this->assertSame(1, $this->staffA->notifications()->count());
    }

    public function test_future_and_inactive_inspection_templates_do_not_generate_current_runs(): void
    {
        $this->asset('WF-TEST-001', 'Registrar wall fan');
        $future = $this->template('Future Wall Fan Inspection', '2026-11-01', true);
        $inactive = $this->template('Inactive Wall Fan Inspection', '2026-10-01', false);
        app(RoutineOccurrenceService::class)->generate(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        $this->assertSame(0, $future->occurrences()->count());
        $this->assertSame(0, $inactive->occurrences()->count());
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'APPROVED']);
        $user->assignRole($role);

        return $user;
    }

    private function person(User $user, string $identifier): FmoPersonnel
    {
        return FmoPersonnel::create(['user_id' => $user->id, 'personnel_identifier' => $identifier,
            'designation' => 'Facilities Staff', 'personnel_status' => 'ACTIVE']);
    }

    private function asset(string $code, string $name, array $overrides = []): InspectableAsset
    {
        return InspectableAsset::create(array_merge([
            'asset_code' => $code, 'asset_type_id' => $this->fanType->id, 'name' => $name,
            'campus_id' => $this->campus->id, 'building_id' => $this->building->id,
            'exact_placement' => 'Registrar Entrance', 'inspection_frequency' => 'MONTHLY',
            'status' => 'ACTIVE', 'condition' => 'GOOD', 'lifecycle_status' => 'ACTIVE',
        ], $overrides));
    }

    private function assetPayload(InspectableAsset $asset): array
    {
        return [
            'asset_code' => $asset->asset_code, 'asset_type_id' => $asset->asset_type_id, 'name' => $asset->name,
            'campus_id' => $asset->campus_id, 'building_id' => $asset->building_id,
            'exact_placement' => $asset->exact_placement, 'inspection_frequency' => 'MONTHLY', 'condition' => 'GOOD',
        ];
    }

    private function template(string $title, string $startDate, bool $active): RoutineTaskTemplate
    {
        return RoutineTaskTemplate::create([
            'title' => $title, 'task_type' => 'INSPECTION', 'frequency' => 'MONTHLY', 'recurrence_config' => ['day' => 1],
            'asset_type_id' => $this->fanType->id, 'inspection_asset_scope' => 'ALL_TYPE', 'start_date' => $startDate,
            'due_time' => '08:00', 'evidence_required' => false, 'requires_review' => false, 'is_active' => $active,
            'created_by' => $this->head->id,
        ]);
    }
}
