<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkOrderRequestEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    private function user(string $role, string $type = 'staff'): User
    {
        $user = User::factory()->create(['user_type' => $type]);
        $user->assignRole($role);

        return $user;
    }

    private function personnel(User $user, string $status = 'ACTIVE', bool $archived = false): void
    {
        FmoPersonnel::create([
            'user_id' => $user->id,
            'personnel_identifier' => 'FMO-'.$user->id,
            'designation' => 'Maintenance Worker',
            'personnel_status' => $status,
            'archived_at' => $archived ? now() : null,
        ]);
    }

    private function payload(): array
    {
        $campus = Campus::firstOrCreate(['code' => 'DC'], ['name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::firstOrCreate(['campus_id' => $campus->id, 'name' => 'Main'], ['is_active' => true]);
        $category = WorkOrderCategory::firstOrCreate(['name' => 'Repair'], ['is_active' => true]);

        return ['campus_id' => $campus->id, 'building_id' => $building->id,
            'work_order_category_id' => $category->id, 'subject' => 'Broken light',
            'description' => 'Please repair the hallway light.', 'urgency' => 'NORMAL'];
    }

    public function test_non_fmo_requesters_can_see_the_action_and_submit(): void
    {
        foreach ([['Requester', 'student'], ['Requester', 'faculty'], ['Requester', 'staff'],
            ['Campus Director', 'staff'], ['Director for Instruction', 'staff'],
            ['System Administrator', 'staff']] as [$role, $type]) {
            $user = $this->user($role, $type);
            $listing = $role === 'Requester' ? '/my-work-orders' : '/work-orders';
            $this->actingAs($user)->get($listing)->assertOk()->assertSee('+ New Work Order');
            $this->actingAs($user)->get('/work-orders/create')->assertOk();
            $this->actingAs($user)->post('/work-orders', $this->payload())->assertRedirect();
            $order = WorkOrder::where('requester_id', $user->id)->firstOrFail();
            $this->assertSame(WorkOrderStatus::Submitted, $order->status);
        }
    }

    public function test_fmo_roles_are_blocked_even_with_delegated_create_permission(): void
    {
        foreach (['FMO Staff', 'FMO Dispatcher', 'FMO Head'] as $role) {
            $user = $this->user($role);
            $user->givePermissionTo('work_orders.create');
            $this->personnel($user);
            $listing = $role === 'FMO Staff' ? '/my-work-orders' : '/work-orders';
            $this->actingAs($user)->get($listing)->assertOk()->assertDontSee('+ New Work Order');
            $this->actingAs($user)->get('/work-orders/create')->assertForbidden();
            $this->actingAs($user)->post('/work-orders', $this->payload())->assertForbidden();
            $this->withToken($user->createToken('eligibility')->plainTextToken)
                ->postJson('/api/v1/work-orders', $this->payload())->assertForbidden();
        }

        $this->assertDatabaseCount('work_orders', 0);
        $this->actingAs($this->user('FMO Staff'))->get('/work-orders/my-tasks')->assertOk();
    }

    public function test_role_only_fmo_accounts_and_any_retained_personnel_profile_are_blocked(): void
    {
        foreach (['FMO Head', 'FMO Dispatcher', 'FMO Staff'] as $role) {
            $user = $this->user($role);
            $user->givePermissionTo('work_orders.create');
            $this->actingAs($user)->get('/work-orders/create')->assertForbidden();
        }

        foreach (['ACTIVE', 'ON_LEAVE', 'UNAVAILABLE', 'INACTIVE'] as $status) {
            $user = $this->user('Requester');
            $this->personnel($user, $status);
            $this->actingAs($user)->post('/work-orders', $this->payload())->assertForbidden();
        }

        $archived = $this->user('Requester');
        $this->personnel($archived, 'INACTIVE', true);
        $this->actingAs($archived)->post('/work-orders', $this->payload())->assertForbidden();

        $multiRole = $this->user('Requester');
        $multiRole->assignRole('FMO Staff');
        $this->personnel($multiRole);
        $this->actingAs($multiRole)->post('/work-orders', $this->payload())->assertForbidden();

        $admin = $this->user('System Administrator');
        $this->personnel($admin);
        $this->actingAs($admin)->post('/work-orders', $this->payload())->assertForbidden();

        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_unauthorized_post_does_not_consume_a_number_store_media_or_write_history(): void
    {
        Storage::fake('local');
        $user = $this->user('FMO Staff');
        $user->givePermissionTo('work_orders.create');
        $this->personnel($user);
        $this->actingAs($user)->post('/work-orders', [...$this->payload(),
            'attachments' => [UploadedFile::fake()->image('evidence.png')],
        ])->assertForbidden();

        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_attachments', 0);
        $this->assertDatabaseCount('work_order_workflow_events', 0);
        $this->assertSame(0, DB::table('work_order_number_sequences')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('work-orders'));
    }

    public function test_campus_director_direct_action_stays_distinct_from_normal_request(): void
    {
        $director = $this->user('Campus Director');
        $this->actingAs($director)->get('/work-orders/create')
            ->assertOk()->assertSee('Create direct authorized Work Order');
        $this->actingAs($director)->post('/work-orders', $this->payload())->assertRedirect();
        $ordinary = WorkOrder::where('requester_id', $director->id)->firstOrFail();
        $this->assertSame(WorkOrderStatus::Submitted, $ordinary->status);
        $this->assertNull($ordinary->decided_by);

        $this->actingAs($director)->post('/work-orders/direct', $this->payload())->assertRedirect();
        $direct = WorkOrder::where('requester_id', $director->id)->where('id', '!=', $ordinary->id)->firstOrFail();
        $this->assertSame(WorkOrderStatus::Approved, $direct->status);
        $this->assertSame($director->id, $direct->decided_by);

        $instructionDirector = $this->user('Director for Instruction');
        $this->actingAs($instructionDirector)->get('/work-orders/create')
            ->assertOk()->assertDontSee('Create direct authorized Work Order');
        $this->actingAs($instructionDirector)->post('/work-orders/direct', $this->payload())->assertForbidden();
    }

    public function test_reseeding_removes_obsolete_fmo_role_grants_without_touching_direct_grants(): void
    {
        $user = $this->user('FMO Staff');
        $user->givePermissionTo('work_orders.create');
        foreach (['FMO Head', 'FMO Dispatcher', 'FMO Staff'] as $role) {
            \Spatie\Permission\Models\Role::findByName($role, 'web')->givePermissionTo('work_orders.create');
        }

        $this->seed(AuthorizationSeeder::class);

        foreach (['FMO Head', 'FMO Dispatcher', 'FMO Staff'] as $role) {
            $this->assertFalse(\Spatie\Permission\Models\Role::findByName($role, 'web')->hasPermissionTo('work_orders.create'));
        }
        $this->assertTrue($user->fresh()->hasDirectPermission('work_orders.create'));
        $this->actingAs($user)->get('/work-orders/create')->assertForbidden();
    }
}
