<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\Skill;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use Database\Seeders\AuthorizationSeeder;
use App\Services\WorkOrderAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WebPageSmokeTest extends TestCase
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

    private function order(User $requester, WorkOrderStatus $status = WorkOrderStatus::Submitted): WorkOrder
    {
        $campus = Campus::create(['code' => 'DC', 'name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::create(['campus_id' => $campus->id, 'name' => 'Academic', 'is_active' => true]);
        $category = WorkOrderCategory::create(['name' => 'Electrical', 'is_active' => true]);

        return WorkOrder::create([
            'work_order_number' => 'WO-2026-000001',
            'requester_id' => $requester->id,
            'campus_id' => $campus->id,
            'building_id' => $building->id,
            'work_order_category_id' => $category->id,
            'subject' => 'Repair light',
            'description' => 'The light is broken.',
            'urgency' => 'NORMAL',
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }

    public function test_public_and_requester_pages_render_and_keep_scope(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
        $this->get('/work-orders')->assertRedirect('/login');

        $requester = $this->user('Requester');
        $other = $this->user('Requester');
        $order = $this->order($requester);
        $this->actingAs($requester);

        foreach ([
            '/registration/status', '/dashboard', '/work-orders',
            '/work-orders?search=Repair&status=SUBMITTED&page=1',
            '/work-orders?search=missing', '/work-orders/create',
            '/work-orders/'.$order->id, '/work-orders/'.$order->id.'/edit',
            '/notifications', '/reports/work-orders',
            '/reports/work-orders?status=SUBMITTED&building_id='.$order->building_id,
            '/reports/work-orders/'.$order->id.'/print', '/profile',
        ] as $path) {
            $this->assertSame(200, $this->get($path)->status(), $path);
        }

        $this->get('/work-orders')->assertSee($order->work_order_number)->assertSee('New Work Order');
        $this->get('/work-orders?search=missing')->assertDontSee($order->work_order_number);
        $this->get('/skills')->assertForbidden();
        $this->get('/work-order-categories')->assertOk()->assertDontSee('Add category');
        $this->get('/admin/users')->assertForbidden();
        $this->actingAs($other)->get('/work-orders/'.$order->id)->assertForbidden();
        $this->actingAs($other)->get('/work-orders')->assertDontSee($order->work_order_number);
    }

    public function test_staff_and_management_pages_render_with_real_records(): void
    {
        $requester = $this->user('Requester');
        $staff = $this->user('FMO Staff');
        $head = $this->user('FMO Head');
        $admin = $this->user('System Administrator');
        $pending = User::factory()->create(['status' => 'PENDING']);
        $order = $this->order($requester, WorkOrderStatus::Approved);
        $personnel = FmoPersonnel::create([
            'user_id' => $staff->id,
            'personnel_identifier' => 'FMO-001',
            'designation' => 'Technician',
            'personnel_status' => 'ACTIVE',
        ]);
        Skill::create(['name' => 'Electrical Repair', 'is_active' => true]);

        $this->actingAs($staff);
        foreach (['/dashboard', '/work-orders/my-tasks', '/notifications', '/reports/work-orders', '/profile'] as $path) {
            $this->assertSame(200, $this->get($path)->status(), $path);
        }
        $this->get('/work-orders/'.$order->id)->assertForbidden();
        $this->get('/work-orders')->assertOk()->assertDontSee($order->work_order_number);
        $this->get('/personnel')->assertForbidden();

        app(WorkOrderAssignmentService::class)->add($order, $head, [$personnel->id]);
        $this->get('/work-orders/'.$order->id)->assertOk();
        $this->get('/work-orders/my-tasks')->assertSee($order->work_order_number);

        $this->actingAs($head);
        foreach ([
            '/dashboard', '/work-orders', '/work-orders?search=Repair&status=APPROVED',
            '/work-orders/'.$order->id, '/work-orders/queue/screening',
            '/work-orders/queue/approval', '/work-orders/assignment-queue',
            '/work-orders/assessment-review', '/work-orders/execution/active',
            '/work-orders/execution/verification', '/personnel', '/personnel/create',
            '/personnel/'.$personnel->id, '/personnel/'.$personnel->id.'/edit',
            '/skills', '/skills?search=Electrical', '/work-order-categories',
            '/work-order-categories?search=Electrical', '/locations/campuses',
            '/locations/buildings', '/locations/buildings/'.$order->building_id,
            '/locations', '/admin/registrations', '/admin/registrations/'.$pending->id,
            '/reports/work-orders', '/reports/work-orders/'.$order->id.'/print',
            '/notifications',
        ] as $path) {
            $this->assertSame(200, $this->get($path)->status(), $path);
        }

        $this->get('/skills')->assertSee('Add skill');
        $this->get('/skills?search=Electrical')->assertSee('Electrical Repair');
        $this->get('/skills?search=missing')->assertDontSee('Electrical Repair');
        $this->get('/work-order-categories')->assertSee('Electrical')->assertSee('Add category');
        $this->get('/work-orders')->assertSee($order->work_order_number);
        $this->get('/profile')->assertForbidden();
        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->get('/admin/users/'.$requester->id)->assertOk();
        $this->get('/admin/roles')->assertOk();
        $this->get('/admin/roles/'.Role::findByName('Requester')->id)->assertOk();
    }

    public function test_other_authorized_roles_render_their_queues_without_gaining_admin_access(): void
    {
        $this->order($this->user('Requester'));

        foreach (['FMO Dispatcher', 'Campus Director', 'Director for Instruction', 'FMO Oversight'] as $role) {
            $this->actingAs($this->user($role));
            foreach (['/dashboard', '/work-orders', '/reports/work-orders', '/notifications'] as $path) {
                $this->assertSame(200, $this->get($path)->status(), $role.' '.$path);
            }
            $this->get('/admin/roles')->assertForbidden();
        }

        $this->actingAs($this->user('FMO Dispatcher'));
        $this->get('/work-orders/queue/screening')->assertOk();
        $this->get('/work-orders/assignment-queue')->assertOk();

        $this->actingAs($this->user('Campus Director'));
        $this->get('/admin/registrations')->assertOk();
        $this->get('/work-orders/assessment-review')->assertOk();
    }
}
