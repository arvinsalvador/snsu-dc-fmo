<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\BuildingLocation;
use App\Models\Campus;
use App\Models\Floor;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    private function user(string $role = 'Requester'): User
    {
        $u = User::factory()->create(['status' => 'APPROVED']);
        $u->assignRole($role);

        return $u;
    }

    public function test_head_can_manage_hierarchy_and_requester_cannot(): void
    {
        $head = $this->user('FMO Head');
        $this->actingAs($head)->post('/locations/campuses', ['code' => 'DC', 'name' => 'Del Carmen', 'is_active' => true])->assertRedirect();
        $campus = Campus::first();
        $this->actingAs($head)->post('/locations/buildings', ['campus_id' => $campus->id, 'code' => 'ADMIN', 'name' => 'Administration', 'is_active' => true])->assertRedirect();
        $building = Building::first();
        $this->actingAs($head)->post('/locations/buildings/'.$building->id.'/floors', ['name' => 'Ground Floor', 'is_active' => true])->assertRedirect();
        $floor = Floor::first();
        $this->actingAs($head)->post('/locations', ['building_id' => $building->id, 'floor_id' => $floor->id, 'type' => 'OFFICE', 'name' => 'Registrar', 'is_active' => true])->assertRedirect();
        $location = BuildingLocation::first();
        $this->assertTrue($location->isOperational());
        $this->actingAs($this->user())->post('/locations/buildings', ['campus_id' => $campus->id, 'name' => 'Bad', 'is_active' => true])->assertForbidden();
        $this->actingAs($head)->post('/locations', ['building_id' => $building->id, 'floor_id' => $floor->id, 'type' => 'OFFICE', 'name' => 'Other', 'is_active' => true])->assertRedirect();
    }

    public function test_cross_building_floor_is_rejected_and_parent_delete_is_safe(): void
    {
        $head = $this->user('FMO Head');
        $a = Campus::create(['code' => 'A', 'name' => 'A', 'is_active' => true]);
        $b = Campus::create(['code' => 'B', 'name' => 'B', 'is_active' => true]);
        $one = Building::create(['campus_id' => $a->id, 'name' => 'One', 'is_active' => true]);
        $two = Building::create(['campus_id' => $b->id, 'name' => 'Two', 'is_active' => true]);
        $floor = Floor::create(['building_id' => $one->id, 'name' => 'Floor', 'is_active' => true]);
        $this->actingAs($head)->post('/locations', ['building_id' => $two->id, 'floor_id' => $floor->id, 'type' => 'ROOM', 'name' => 'Invalid', 'is_active' => true])->assertSessionHasErrors('floor_id');
        $this->actingAs($head)->delete('/locations/campuses/'.$a->id)->assertStatus(409);
        $this->actingAs($head)->delete('/locations/buildings/'.$one->id)->assertStatus(409);
    }

    public function test_active_api_hides_children_of_inactive_parents(): void
    {
        $requester = $this->user();
        $campus = Campus::create(['code' => 'A', 'name' => 'A', 'is_active' => true]);
        $building = Building::create(['campus_id' => $campus->id, 'name' => 'One', 'is_active' => true]);
        $location = BuildingLocation::create(['building_id' => $building->id, 'type' => 'COMMON_AREA', 'name' => 'Court', 'is_active' => true]);
        $token = $requester->createToken('t')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/locations?active=1')->assertJsonFragment(['name' => 'Court']);
        $campus->update(['is_active' => false]);
        $this->assertFalse($location->fresh()->isOperational());
        $this->withToken($token)->getJson('/api/v1/locations?active=1')->assertJsonMissing(['name' => 'Court']);
    }

    public function test_building_detail_exposes_floors_and_building_level_areas(): void
    {
        $head = $this->user('FMO Head');
        $campus = Campus::create(['code' => 'DC', 'name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::create(['campus_id' => $campus->id, 'name' => 'Academic', 'is_active' => true]);
        $this->actingAs($head)->post('/locations/buildings/'.$building->id.'/floors', ['name' => 'Ground Floor', 'is_active' => true])->assertRedirect();
        $ground = Floor::firstOrFail();
        $this->actingAs($head)->post('/locations/buildings/'.$building->id.'/floors', ['name' => 'Second Floor', 'is_active' => true])->assertRedirect();
        $this->actingAs($head)->post('/locations', ['building_id' => $building->id, 'floor_id' => $ground->id, 'type' => 'LABORATORY', 'name' => 'Computer Laboratory', 'is_active' => true])->assertRedirect();
        $this->actingAs($head)->post('/locations', ['building_id' => $building->id, 'type' => 'OUTDOOR_AREA', 'name' => 'Perimeter Fence', 'is_active' => true])->assertRedirect();

        $this->actingAs($head)->get('/locations')->assertOk()->assertSee('Location Management')->assertSee('Offices / Rooms / Areas');
        $this->actingAs($head)->get('/locations/buildings/'.$building->id)->assertOk()->assertSee('Ground Floor')->assertSee('Computer Laboratory')->assertSee('Perimeter Fence')->assertSee('+ Add Floor')->assertSee('+ Add Location');
        $this->actingAs($head)->put('/locations/floors/'.$ground->id, ['name' => 'Ground Floor', 'is_active' => false])->assertRedirect();
        $this->assertFalse($ground->fresh()->is_active);
    }

    /** @return array{0: Building, 1: Building, 2: Floor, 3: Floor, 4: Floor} */
    private function buildingsWithFloors(): array
    {
        $campus = Campus::create(['code' => 'DC', 'name' => 'Del Carmen', 'is_active' => true]);
        $a = Building::create(['campus_id' => $campus->id, 'name' => 'Building A', 'is_active' => true]);
        $b = Building::create(['campus_id' => $campus->id, 'name' => 'Building B', 'is_active' => true]);
        $a2 = Floor::create(['building_id' => $a->id, 'name' => 'Second Floor', 'display_order' => 2, 'is_active' => true]);
        $a1 = Floor::create(['building_id' => $a->id, 'name' => 'First Floor', 'display_order' => 1, 'is_active' => true]);
        $b1 = Floor::create(['building_id' => $b->id, 'name' => 'B First Floor', 'display_order' => 1, 'is_active' => true]);

        return [$a, $b, $a1, $a2, $b1];
    }

    public function test_floor_lookup_returns_only_the_buildings_floors_in_display_order(): void
    {
        [$a, $b, $a1, $a2, $b1] = $this->buildingsWithFloors();
        $head = $this->user('FMO Head');

        $this->actingAs($head)->getJson('/work-orders/location-options/floors?building_id='.$a->id)
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $a1->id)->assertJsonPath('data.1.id', $a2->id);
        $this->actingAs($head)->getJson('/work-orders/location-options/floors?building_id='.$b->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b1->id);
    }

    public function test_floor_lookup_for_building_without_floors_is_an_empty_success(): void
    {
        [$a] = $this->buildingsWithFloors();
        $empty = Building::create(['campus_id' => $a->campus_id, 'name' => 'Canteen', 'is_active' => true]);

        $this->actingAs($this->user('FMO Head'))->getJson('/work-orders/location-options/floors?building_id='.$empty->id)
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_location_accepts_matching_floor_or_building_level(): void
    {
        [$a, , $a1] = $this->buildingsWithFloors();
        $head = $this->user('FMO Head');

        $this->actingAs($head)->post('/locations/areas', ['building_id' => $a->id, 'floor_id' => $a1->id, 'type' => 'OFFICE', 'name' => 'Guidance', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->actingAs($head)->post('/locations/areas', ['building_id' => $a->id, 'floor_id' => '', 'type' => 'OFFICE', 'name' => 'Lobby', 'is_active' => 1])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('building_locations', ['name' => 'Guidance', 'floor_id' => $a1->id]);
        $this->assertDatabaseHas('building_locations', ['name' => 'Lobby', 'floor_id' => null]);
    }

    public function test_location_rejects_floor_from_another_building_on_create_and_update(): void
    {
        [$a, , $a1, , $b1] = $this->buildingsWithFloors();
        $head = $this->user('FMO Head');

        $this->actingAs($head)->post('/locations/areas', ['building_id' => $a->id, 'floor_id' => $b1->id, 'type' => 'OFFICE', 'name' => 'Guidance', 'is_active' => 1])
            ->assertSessionHasErrors('floor_id');
        $this->assertDatabaseCount('building_locations', 0);

        $location = BuildingLocation::create(['building_id' => $a->id, 'floor_id' => $a1->id, 'type' => 'OFFICE', 'name' => 'Guidance', 'is_active' => true]);
        $this->actingAs($head)->put('/locations/areas/'.$location->id, ['building_id' => $a->id, 'floor_id' => $b1->id, 'type' => 'OFFICE', 'name' => 'Guidance', 'is_active' => 1])
            ->assertSessionHasErrors('floor_id');
        $this->assertSame($a1->id, $location->fresh()->floor_id);
    }

    public function test_locations_page_exposes_current_floor_for_edit_form(): void
    {
        [$a, , , $a2] = $this->buildingsWithFloors();
        BuildingLocation::create(['building_id' => $a->id, 'floor_id' => $a2->id, 'type' => 'OFFICE', 'name' => 'Guidance', 'is_active' => true]);

        $this->actingAs($this->user('FMO Head'))->get('/locations/areas')->assertOk()
            ->assertSee("building: '".$a->id."', floor: '".$a2->id."'", false)
            ->assertSee('Second Floor');
    }
}
