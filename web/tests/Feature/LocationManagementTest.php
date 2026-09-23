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
        $this->actingAs($head)->post('/locations', ['building_id' => $two->id, 'floor_id' => $floor->id, 'type' => 'ROOM', 'name' => 'Invalid', 'is_active' => true])->assertStatus(422);
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
}
