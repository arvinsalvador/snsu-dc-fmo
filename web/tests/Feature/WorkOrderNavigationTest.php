<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Services\WorkOrderAssignmentService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'APPROVED']);
        $user->assignRole($role);

        return $user;
    }

    private function order(User $requester, string $number, WorkOrderStatus $status = WorkOrderStatus::Submitted): WorkOrder
    {
        $campus = Campus::firstOrCreate(['code' => 'DC'], ['name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::firstOrCreate(['campus_id' => $campus->id, 'name' => 'Academic'], ['is_active' => true]);
        $category = WorkOrderCategory::firstOrCreate(['name' => 'Electrical'], ['is_active' => true]);

        return WorkOrder::create([
            'work_order_number' => $number,
            'requester_id' => $requester->id,
            'campus_id' => $campus->id,
            'building_id' => $building->id,
            'work_order_category_id' => $category->id,
            'subject' => 'Repair light',
            'description' => 'Light is broken.',
            'urgency' => 'NORMAL',
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }

    private function assertOnlyActiveNavigation(string $html, string $activeRoute): void
    {
        foreach (['work-orders.mine', 'work-orders.index', 'work-orders.my-tasks'] as $route) {
            $active = 'class="nav-link nav-link-active" href="'.route($route).'"';
            if ($route === $activeRoute) {
                $this->assertStringContainsString($active, $html);
            } else {
                $this->assertStringNotContainsString($active, $html);
            }
        }
    }

    public function test_admin_personal_and_management_lists_have_distinct_scopes_and_active_links(): void
    {
        $admin = $this->user('System Administrator');
        $requesterA = $this->user('Requester');
        $requesterB = $this->user('Requester');
        $this->order($admin, 'WO-2026-000001');
        $this->order($requesterA, 'WO-2026-000002');
        $this->order($requesterB, 'WO-2026-000003');

        $mine = $this->actingAs($admin)->get('/my-work-orders')->assertOk()
            ->assertSee('My Work Orders')
            ->assertSee('View and track the facilities requests you have submitted.')
            ->assertSee('WO-2026-000001')
            ->assertDontSee('WO-2026-000002')
            ->assertDontSee('WO-2026-000003');
        $this->assertOnlyActiveNavigation($mine->getContent(), 'work-orders.mine');

        $all = $this->get('/work-orders')->assertOk()
            ->assertSee('All Work Orders')
            ->assertSee('Review authorized facilities requests across the campus.')
            ->assertSee('WO-2026-000001')
            ->assertSee('WO-2026-000002')
            ->assertSee('WO-2026-000003');
        $this->assertOnlyActiveNavigation($all->getContent(), 'work-orders.index');

        $this->actingAs($requesterA)->get('/my-work-orders')->assertOk()
            ->assertSee('WO-2026-000002')
            ->assertDontSee('WO-2026-000001')
            ->assertDontSee('WO-2026-000003');
        $this->get('/work-orders')->assertForbidden();
    }

    public function test_staff_personal_requests_and_assigned_tasks_are_separate(): void
    {
        $staff = $this->user('FMO Staff');
        $head = $this->user('FMO Head');
        $requester = $this->user('Requester');
        $this->order($staff, 'WO-2026-000001');
        $assigned = $this->order($requester, 'WO-2026-000002', WorkOrderStatus::Approved);
        $personnel = FmoPersonnel::create([
            'user_id' => $staff->id,
            'personnel_identifier' => 'FMO-001',
            'designation' => 'Technician',
            'personnel_status' => 'ACTIVE',
        ]);
        app(WorkOrderAssignmentService::class)->add($assigned, $head, [$personnel->id]);

        $mine = $this->actingAs($staff)->get('/my-work-orders')->assertOk()
            ->assertSee('WO-2026-000001')
            ->assertDontSee('WO-2026-000002');
        $this->assertOnlyActiveNavigation($mine->getContent(), 'work-orders.mine');

        $tasks = $this->get('/work-orders/my-tasks')->assertOk()
            ->assertSee('My Tasks')
            ->assertSee('WO-2026-000002')
            ->assertDontSee('WO-2026-000001');
        $this->assertOnlyActiveNavigation($tasks->getContent(), 'work-orders.my-tasks');
        $this->get('/work-orders')->assertForbidden();
    }
}
