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

class WorkOrderLocationLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_requesters_and_directors_can_read_scoped_active_choices_without_management_authority(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $a = Campus::create(['code' => 'A', 'name' => 'Campus A', 'is_active' => true]);
        $b = Campus::create(['code' => 'B', 'name' => 'Campus B', 'is_active' => true]);
        $empty = Campus::create(['code' => 'C', 'name' => 'Empty campus', 'is_active' => true]);
        $one = Building::create(['campus_id' => $a->id, 'name' => 'Academic Building', 'is_active' => true, 'notes' => 'Private note']);
        Building::create(['campus_id' => $a->id, 'name' => 'Administration Building', 'is_active' => true]);
        Building::create(['campus_id' => $a->id, 'name' => 'Inactive Building', 'is_active' => false]);
        $other = Building::create(['campus_id' => $b->id, 'name' => 'Building B1', 'is_active' => true]);
        $ground = Floor::create(['building_id' => $one->id, 'name' => 'Ground Floor', 'is_active' => true]);
        $inactive = Floor::create(['building_id' => $one->id, 'name' => 'Inactive Floor', 'is_active' => false]);
        Floor::create(['building_id' => $other->id, 'name' => 'Other Floor', 'is_active' => true]);
        BuildingLocation::create(['building_id' => $one->id, 'floor_id' => $ground->id, 'name' => 'Computer Laboratory', 'type' => 'LABORATORY', 'is_active' => true]);
        BuildingLocation::create(['building_id' => $one->id, 'name' => 'Main Gate', 'type' => 'OUTDOOR_AREA', 'is_active' => true]);
        BuildingLocation::create(['building_id' => $one->id, 'floor_id' => $inactive->id, 'name' => 'Hidden Room', 'type' => 'ROOM', 'is_active' => true]);

        foreach (['Requester', 'Campus Director', 'Director for Instruction'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->actingAs($user);
            $this->getJson('/work-orders/location-options/buildings?campus_id='.$a->id)
                ->assertOk()->assertJsonCount(2, 'data')
                ->assertJsonFragment(['name' => 'Academic Building'])
                ->assertJsonFragment(['name' => 'Administration Building'])
                ->assertDontSee('Private note')->assertDontSee('Building B1');
            $this->getJson('/work-orders/location-options/buildings?campus_id='.$b->id)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonFragment(['name' => 'Building B1']);
            $this->getJson('/work-orders/location-options/buildings?campus_id='.$empty->id)->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/work-orders/location-options/floors?building_id='.$one->id)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonFragment(['name' => 'Ground Floor']);
            $this->getJson('/work-orders/location-options/areas?building_id='.$one->id)
                ->assertOk()->assertJsonCount(2, 'data')->assertDontSee('Hidden Room');
            $this->post('/locations/buildings', ['campus_id' => $a->id, 'name' => 'Unauthorized', 'is_active' => true])->assertForbidden();
        }

        $a->update(['is_active' => false]);
        $this->getJson('/work-orders/location-options/buildings?campus_id='.$a->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/work-orders/location-options/floors?building_id='.$one->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/work-orders/location-options/areas?building_id='.$one->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_lookup_routes_require_an_approved_user_with_read_permission(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $this->getJson('/work-orders/location-options/buildings?campus_id=1')->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/work-orders/location-options/buildings?campus_id=1')->assertForbidden();
        $user->assignRole('Requester');
        $user->forceFill(['status' => 'SUSPENDED'])->save();
        $this->getJson('/work-orders/location-options/buildings?campus_id=1')->assertRedirect('/registration/status');
    }
}
