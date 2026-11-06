<?php

namespace Tests\Feature;

use App\Enums\PreventiveMaintenanceStatus;
use App\Models\AssetType;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\InspectableAsset;
use App\Models\PreventiveMaintenancePlan;
use App\Models\Skill;
use App\Models\TransportRequest;
use App\Models\User;
use App\Services\AssetMaintenanceHistoryService;
use App\Services\PreventiveMaintenanceOccurrenceService;
use App\Services\VehicleOdometerService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\RoutineOperationsSeeder;
use Database\Seeders\SkillSeeder;
use Database\Seeders\WorkOrderCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OdometerMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $mechanic;

    private User $driverUser;

    private User $requester;

    private User $dispatcher;

    private FmoPersonnel $mechanicPerson;

    private FmoPersonnel $driver;

    private InspectableAsset $van;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', config('database.connections.'.config('database.default').'.database'));
        Storage::fake('local');
        $this->travelTo('2026-11-10 09:00:00');
        $this->seed([AuthorizationSeeder::class, SkillSeeder::class, RoutineOperationsSeeder::class, WorkOrderCategorySeeder::class]);
        $this->head = $this->user('FMO Head');
        $this->mechanic = $this->user('FMO Staff');
        $this->driverUser = $this->user('FMO Staff');
        $this->requester = $this->user('Requester');
        $this->dispatcher = $this->user('FMO Dispatcher');
        $this->mechanicPerson = FmoPersonnel::create(['user_id' => $this->mechanic->id, 'personnel_identifier' => 'FMO-M-1', 'designation' => 'Mechanic', 'personnel_status' => 'ACTIVE']);
        $this->driver = FmoPersonnel::create(['user_id' => $this->driverUser->id, 'personnel_identifier' => 'FMO-D-1', 'designation' => 'Driver', 'personnel_status' => 'ACTIVE']);
        $this->driver->skills()->attach(Skill::where('name', 'Driver')->value('id'), ['is_primary' => true]);
        $campus = Campus::create(['code' => 'DC', 'name' => 'Del Carmen Campus', 'is_active' => true]);
        $building = Building::create(['campus_id' => $campus->id, 'code' => 'GAR', 'name' => 'Motor Pool', 'is_active' => true]);
        $this->van = InspectableAsset::create([
            'asset_code' => 'VEH-VAN-001', 'asset_type_id' => AssetType::where('code', 'VAN')->value('id'), 'asset_class' => 'VEHICLE', 'name' => 'University Van',
            'campus_id' => $campus->id, 'building_id' => $building->id, 'inspection_frequency' => 'MONTHLY',
            'status' => 'ACTIVE', 'condition' => 'GOOD', 'lifecycle_status' => 'ACTIVE', 'current_odometer_km' => 48250,
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_odometer_plan_computes_the_next_due_mileage_and_creates_no_calendar_occurrences(): void
    {
        $this->actingAs($this->head)->post(route('preventive-maintenance.plans.store'), $this->odometerPlan())->assertRedirect(route('preventive-maintenance.plans.index'));

        $plan = PreventiveMaintenancePlan::sole();
        $this->assertTrue($plan->isOdometerBased());
        $this->assertSame(50000, $plan->next_due_odometer_km);
        $this->assertSame(['state' => 'UPCOMING', 'remaining' => 1750], $plan->odometerStatus());
        $this->assertSame(0, $plan->occurrences()->count(), 'Far from due, so nothing is scheduled yet.');

        app(PreventiveMaintenanceOccurrenceService::class)->generate(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-12-31'));
        $this->assertSame(0, $plan->occurrences()->count(), 'The calendar scheduler ignores odometer plans.');
        $this->actingAs($this->head)->get(route('preventive-maintenance.plans.index', ['trigger' => 'ODOMETER']))->assertOk()->assertSee('50,000 km')->assertSee('1,750 km remaining');
        $this->actingAs($this->head)->get(route('assets.show', $this->van))->assertOk()->assertSee('Mileage-based maintenance')->assertSee('1,750 km remaining');
    }

    public function test_odometer_plans_are_limited_to_vehicles_and_default_the_baseline_to_the_current_reading(): void
    {
        $aircon = InspectableAsset::create([
            'asset_code' => 'AC-1', 'asset_type_id' => AssetType::where('code', 'AC')->value('id'), 'asset_class' => 'EQUIPMENT', 'name' => 'Aircon',
            'campus_id' => $this->van->campus_id, 'building_id' => $this->van->building_id, 'inspection_frequency' => 'MONTHLY',
            'status' => 'ACTIVE', 'condition' => 'GOOD', 'lifecycle_status' => 'ACTIVE',
        ]);
        $this->actingAs($this->head)->post(route('preventive-maintenance.plans.store'), $this->odometerPlan(['inspectable_asset_id' => $aircon->id]))->assertSessionHasErrors('inspectable_asset_id');

        $payload = $this->odometerPlan();
        unset($payload['last_service_odometer_km']);
        $this->actingAs($this->head)->post(route('preventive-maintenance.plans.store'), $payload)->assertRedirect();
        $this->assertSame(53250, PreventiveMaintenancePlan::sole()->next_due_odometer_km);
    }

    public function test_calendar_plans_still_require_a_schedule(): void
    {
        $payload = $this->odometerPlan(['trigger_type' => 'CALENDAR']);

        $this->actingAs($this->head)->post(route('preventive-maintenance.plans.store'), $payload)->assertSessionHasErrors(['frequency_type', 'start_date']);
        $this->actingAs($this->head)->post(route('preventive-maintenance.plans.store'), $payload + [
            'frequency_type' => 'MONTHLY', 'interval_value' => 1, 'start_date' => '2026-11-01', 'available_time' => '08:00', 'due_time' => '17:00',
        ])->assertRedirect();
        $plan = PreventiveMaintenancePlan::sole();
        $this->assertSame('CALENDAR', $plan->trigger_type);
        $this->assertNull($plan->next_due_odometer_km);
    }

    public function test_completed_trips_move_the_plan_from_remaining_to_due_soon_to_overdue(): void
    {
        $plan = $this->plan();
        $first = $this->completedTrip(48250, 49850);

        $this->assertSame(49850, $this->van->fresh()->current_odometer_km);
        $this->assertSame(['state' => 'DUE_SOON', 'remaining' => 150], $plan->fresh()->odometerStatus());
        $occurrence = $plan->occurrences()->sole();
        $this->assertSame(50000, $occurrence->due_odometer_km);
        $this->assertNull($occurrence->due_at);
        $this->assertSame(PreventiveMaintenanceStatus::Available, $occurrence->status);
        $this->assertEqualsCanonicalizing([$this->mechanicPerson->id], $occurrence->assignees()->pluck('fmo_personnel.id')->all());
        $this->assertSame(1, $this->mechanic->notifications()->where('data->event_key', 'PM_DUE')->count());

        $this->completedTrip(49850, 50030);
        $this->assertSame(['state' => 'OVERDUE', 'remaining' => -30], $plan->fresh()->odometerStatus());
        $this->assertSame(1, $plan->occurrences()->count(), 'The same service stays open rather than duplicating.');
        $this->assertSame(PreventiveMaintenanceStatus::Overdue, $occurrence->fresh()->status);
        $this->assertTrue($occurrence->fresh()->is_overdue);
        $this->assertSame(1, $this->mechanic->notifications()->where('data->event_key', 'PM_OVERDUE')->count());
        $this->actingAs($this->mechanic)->get(route('preventive-maintenance.tasks.index'))->assertOk()->assertSee('50,000 km');
        $this->assertNotNull($first->fresh()->completed_at);
    }

    public function test_completing_the_service_schedules_the_next_one_and_records_history(): void
    {
        $plan = $this->plan();
        $this->completedTrip(48250, 50030);
        $occurrence = $plan->occurrences()->sole();

        $this->actingAs($this->mechanic)->post(route('preventive-maintenance.tasks.start', $occurrence))->assertRedirect();
        $this->actingAs($this->mechanic)->post(route('preventive-maintenance.tasks.complete', $occurrence), [
            'checklist' => ['ITEM_1' => 'OK', 'ITEM_2' => 'OK'], 'condition_after' => 'GOOD',
            'guided_actions' => ['CLEAN_FILTER'], 'guided_outcome' => 'RESOLVED',
        ])->assertSessionHasErrors('service_odometer_km');
        $this->actingAs($this->mechanic)->post(route('preventive-maintenance.tasks.complete', $occurrence), [
            'checklist' => ['ITEM_1' => 'OK', 'ITEM_2' => 'OK'], 'condition_after' => 'GOOD', 'service_odometer_km' => 50040,
            'guided_actions' => ['CLEAN_FILTER'], 'guided_outcome' => 'RESOLVED',
        ])->assertRedirect();

        $plan = $plan->fresh();
        $this->assertSame(50040, $plan->last_service_odometer_km);
        $this->assertSame(55040, $plan->next_due_odometer_km);
        $this->assertSame(['state' => 'UPCOMING', 'remaining' => 4999 + 1], $plan->odometerStatus());
        $this->assertSame(50040, $this->van->fresh()->current_odometer_km);
        $this->assertSame(PreventiveMaintenanceStatus::Completed, $occurrence->fresh()->status);
        $this->assertSame(50040, $occurrence->fresh()->service_odometer_km);
        $this->assertSame(1, $plan->occurrences()->count());

        $types = collect(app(AssetMaintenanceHistoryService::class)->paginate($this->van)->items())->pluck('type')->all();
        $this->assertContains('PM_COMPLETED', $types);
        $this->assertContains('TRIP_COMPLETED', $types);
    }

    public function test_the_odometer_never_goes_backwards_from_a_service_or_trip_reading(): void
    {
        $this->van->update(['current_odometer_km' => 60000]);

        $this->assertFalse(app(VehicleOdometerService::class)->record($this->van, 59000));
        $this->assertTrue(app(VehicleOdometerService::class)->record($this->van, 60500));
        $this->assertSame(60500, $this->van->fresh()->current_odometer_km);
    }

    public function test_requesters_and_drivers_cannot_manage_maintenance_plans(): void
    {
        $this->actingAs($this->requester)->get(route('preventive-maintenance.plans.create'))->assertForbidden();
        $this->actingAs($this->requester)->post(route('preventive-maintenance.plans.store'), $this->odometerPlan())->assertForbidden();
        $this->actingAs($this->driverUser)->get(route('preventive-maintenance.plans.index'))->assertForbidden();
        $this->assertSame(0, PreventiveMaintenancePlan::count());
        $this->actingAs($this->head)->get(route('preventive-maintenance.plans.create'))->assertOk()->assertSee('Odometer (distance-based, vehicles)');
    }

    public function test_vehicle_form_saves_specifications_and_odometer(): void
    {
        $this->actingAs($this->head)->put(route('assets.update', $this->van), [
            'asset_code' => 'VEH-VAN-001', 'asset_type_id' => $this->van->asset_type_id, 'asset_class' => 'VEHICLE', 'name' => 'University Van',
            'campus_id' => $this->van->campus_id, 'building_id' => $this->van->building_id, 'inspection_frequency' => 'MONTHLY', 'condition' => 'GOOD',
            'brand' => 'Toyota', 'model' => 'HiAce', 'plate_number' => 'ABC 1234', 'vehicle_year' => 2022, 'passenger_capacity' => 12, 'current_odometer_km' => 48300,
        ])->assertRedirect();

        $van = $this->van->fresh();
        $this->assertEqualsCanonicalizing(['plate_number' => 'ABC 1234', 'year' => 2022, 'passenger_capacity' => 12], $van->specifications);
        $this->assertSame(48300, $van->current_odometer_km);
        $this->actingAs($this->head)->get(route('assets.show', $van))->assertOk()->assertSee('ABC 1234')->assertSee('48,300 km');
    }

    /** @param array<string, mixed> $overrides */
    private function odometerPlan(array $overrides = []): array
    {
        return $overrides + [
            'inspectable_asset_id' => $this->van->id, 'name' => 'Engine Oil Change', 'maintenance_type' => 'SERVICING', 'trigger_type' => 'ODOMETER',
            'odometer_interval_km' => 5000, 'last_service_odometer_km' => 45000, 'due_soon_km' => 500,
            'checklist_label' => ['Record current odometer', 'Change engine oil'], 'assignee_ids' => [$this->mechanicPerson->id], 'is_active' => 1,
        ];
    }

    private function plan(): PreventiveMaintenancePlan
    {
        $this->actingAs($this->head)->post(route('preventive-maintenance.plans.store'), $this->odometerPlan())->assertRedirect();

        return PreventiveMaintenancePlan::sole();
    }

    private function completedTrip(int $before, int $after): TransportRequest
    {
        $this->actingAs($this->requester)->post(route('transport.store'), [
            'purpose' => 'Official meeting', 'origin' => 'Del Carmen', 'destination' => 'Dapa', 'travel_date' => '2026-11-12', 'passenger_count' => 3,
        ])->assertRedirect();
        $transport = TransportRequest::latest('created_at')->latest('id')->firstOrFail();
        $this->actingAs($this->head)->post(route('transport.approve', $transport))->assertRedirect();
        $this->actingAs($this->dispatcher)->post(route('transport.assign', $transport), ['driver_personnel_id' => $this->driver->id, 'inspectable_asset_id' => $this->van->id])->assertRedirect();
        $this->actingAs($this->driverUser)->post(route('transport.trip.complete', $transport), [
            'actual_departed_at' => '2026-11-12T08:00', 'actual_arrived_at' => '2026-11-12T16:00', 'odometer_before' => $before, 'odometer_after' => $after,
            'fuel_before' => 'FULL', 'fuel_after' => 'HALF',
        ])->assertRedirect();
        $this->travel(1)->minutes();

        return $transport->fresh();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'APPROVED']);
        $user->assignRole($role);

        return $user;
    }
}
