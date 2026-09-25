<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Models\WorkOrderWorkflowEvent;
use App\Services\WorkflowNotificationService;
use App\Services\WorkOrderAssignmentService;
use App\Services\WorkOrderExecutionService;
use App\Services\WorkOrderWorkflowService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingNotificationsTest extends TestCase
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

    private function person(User $user): FmoPersonnel
    {
        return FmoPersonnel::create(['user_id' => $user->id, 'personnel_identifier' => 'P-'.$user->id, 'designation' => 'Technician', 'personnel_status' => 'ACTIVE']);
    }

    private function order(User $requester, WorkOrderStatus $status = WorkOrderStatus::Submitted, ?Building $building = null, ?WorkOrderCategory $category = null): WorkOrder
    {
        $campus = Campus::firstOrCreate(['code' => 'DC'], ['name' => 'Del Carmen', 'is_active' => true]);
        $building ??= Building::firstOrCreate(['campus_id' => $campus->id, 'name' => 'Main'], ['is_active' => true]);
        $category ??= WorkOrderCategory::firstOrCreate(['name' => 'Electrical'], ['is_active' => true]);

        return WorkOrder::create([
            'work_order_number' => 'WO-2026-'.str_pad((string) (WorkOrder::count() + 1), 6, '0', STR_PAD_LEFT),
            'requester_id' => $requester->id, 'campus_id' => $campus->id, 'building_id' => $building->id,
            'work_order_category_id' => $category->id, 'subject' => 'Repair light', 'description' => 'Light is broken',
            'urgency' => 'NORMAL', 'status' => $status, 'submitted_at' => now()->subDays(2),
            'verified_at' => $status === WorkOrderStatus::Completed ? now() : null,
        ]);
    }

    public function test_workflow_notifications_are_scoped_and_readable_only_by_recipient(): void
    {
        $requester = $this->user('Requester');
        $outsider = $this->user('FMO Staff');
        $dispatcher = $this->user('FMO Dispatcher');
        $head = $this->user('FMO Head');
        $order = $this->order($requester);
        app(WorkOrderWorkflowService::class)->recordCreation($order, $requester);

        $this->assertSame(1, $requester->unreadNotifications()->count());
        $this->assertSame(1, $dispatcher->unreadNotifications()->count());
        $this->assertSame(1, $head->unreadNotifications()->count());
        $this->assertSame(0, $outsider->unreadNotifications()->count());
        $notification = $requester->notifications()->firstOrFail();
        $this->actingAs($outsider)->post("/notifications/{$notification->id}/read")->assertNotFound();
        $this->actingAs($requester)->get('/notifications')->assertOk()->assertSee('Work Order received');
        $this->actingAs($requester)->post("/notifications/{$notification->id}/read")->assertRedirect();
        $this->assertSame(0, $requester->unreadNotifications()->count());
        $this->actingAs($requester)->get("/work-orders/{$order->id}")->assertOk();
        $this->actingAs($this->user('Requester'))->get("/work-orders/{$order->id}")->assertForbidden();
    }

    public function test_assignment_notifies_only_assigned_staff_and_read_all_api_is_own_scoped(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $other = $this->user('FMO Staff');
        $order = $this->order($this->user('Requester'), WorkOrderStatus::Approved);
        app(WorkOrderAssignmentService::class)->add($order, $head, [$this->person($staff)->id]);
        $this->assertSame(1, $staff->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());
        $token = $staff->createToken('reports-test')->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread_count', 1);
        app('auth')->forgetGuards();
        $this->withToken($other->createToken('reports-test')->plainTextToken)
            ->postJson('/api/v1/notifications/'.$staff->notifications()->first()->id.'/read')->assertNotFound();
        app('auth')->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->assertSame(0, $staff->unreadNotifications()->count());
    }

    public function test_dashboard_counts_are_scoped_and_management_sees_active_sessions(): void
    {
        $head = $this->user('FMO Head');
        $requester = $this->user('Requester');
        $other = $this->user('Requester');
        $staff = $this->user('FMO Staff');
        $this->order($requester, WorkOrderStatus::Submitted);
        $this->order($other, WorkOrderStatus::ForApproval);
        $ready = $this->order($requester, WorkOrderStatus::ReadyForWork);
        app(WorkOrderAssignmentService::class)->add($ready, $head, [$this->person($staff)->id]);
        app(WorkOrderExecutionService::class)->start($ready, $staff);

        $this->actingAs($head)->get('/dashboard')->assertOk()
            ->assertViewHas('counts', fn ($counts) => (int) $counts['SUBMITTED'] === 1 && (int) $counts['FOR_APPROVAL'] === 1 && (int) $counts['IN_PROGRESS'] === 1)
            ->assertSee($staff->name);
        $this->actingAs($requester)->get('/dashboard')->assertOk()
            ->assertViewHas('counts', fn ($counts) => (int) $counts['SUBMITTED'] === 1 && ! isset($counts['FOR_APPROVAL']));
    }

    public function test_history_filters_csv_and_print_follow_authorized_scope(): void
    {
        $head = $this->user('FMO Head');
        $requester = $this->user('Requester');
        $other = $this->user('Requester');
        $staff = $this->user('FMO Staff');
        $building = Building::create(['campus_id' => Campus::firstOrCreate(['code' => 'DC'], ['name' => 'Del Carmen', 'is_active' => true])->id, 'name' => 'Annex', 'is_active' => true]);
        $own = $this->order($requester, WorkOrderStatus::Completed, $building);
        $hidden = $this->order($other, WorkOrderStatus::Submitted);
        $this->actingAs($requester)->get('/reports/work-orders')->assertOk()->assertSee($own->work_order_number)->assertDontSee($hidden->work_order_number);
        $ownCsv = $this->actingAs($requester)->get('/reports/work-orders.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString($own->work_order_number, $ownCsv);
        $this->assertStringNotContainsString($hidden->work_order_number, $ownCsv);
        $this->actingAs($requester)->get('/reports/work-orders/'.$hidden->id.'/print')->assertForbidden();
        $this->actingAs($staff)->get('/reports/work-orders')->assertOk()->assertDontSee($own->work_order_number);
        $staffCsv = $this->actingAs($staff)->get('/reports/work-orders.csv')->assertOk()->streamedContent();
        $this->assertStringNotContainsString($own->work_order_number, $staffCsv);
        $this->assertStringNotContainsString($hidden->work_order_number, $staffCsv);
        $this->actingAs($staff)->get('/reports/work-orders/'.$own->id.'/print')->assertForbidden();
        $this->actingAs($head)->get('/reports/work-orders?building_id='.$building->id.'&status=COMPLETED')->assertOk()
            ->assertSee($own->work_order_number)->assertDontSee($hidden->work_order_number);
        $managementCsv = $this->actingAs($head)->get('/reports/work-orders.csv?building_id='.$building->id)->assertOk()->streamedContent();
        $this->assertStringContainsString($own->work_order_number, $managementCsv);
        $this->assertStringNotContainsString($hidden->work_order_number, $managementCsv);
        $this->actingAs($head)->get('/reports/work-orders/'.$own->id.'/print')->assertOk()->assertSee('Repair light')->assertSee('Verified by');
    }

    public function test_combined_history_filters_and_metric_period_are_consistent(): void
    {
        $head = $this->user('FMO Head');
        $requester = $this->user('Requester');
        $staff = $this->user('FMO Staff');
        $person = $this->person($staff);
        $order = $this->order($requester, WorkOrderStatus::Approved);
        app(WorkOrderAssignmentService::class)->add($order, $head, [$person->id]);
        $order->forceFill(['status' => WorkOrderStatus::Completed, 'submitted_at' => now()->subHours(48), 'verified_at' => now()])->save();
        $other = $this->order($requester, WorkOrderStatus::Submitted);

        $query = http_build_query([
            'status' => 'COMPLETED', 'category_id' => $order->work_order_category_id,
            'building_id' => $order->building_id, 'personnel_id' => $person->id,
            'from' => now()->subDays(3)->format('Y-m-d'), 'to' => now()->format('Y-m-d'),
        ]);
        $this->actingAs($head)->get('/reports/work-orders?'.$query)->assertOk()
            ->assertSee($order->work_order_number)->assertDontSee($other->work_order_number);
        $this->actingAs($head)->get('/dashboard')->assertOk()
            ->assertViewHas('periodCompleted', 1)
            ->assertViewHas('averageHours', fn ($hours) => abs((float) $hours - 48) < 0.1);
        $this->actingAs($staff)->get('/reports/work-orders')->assertOk()->assertSee($order->work_order_number)->assertDontSee($other->work_order_number);
        $this->actingAs($staff)->get('/reports/work-orders.csv')->assertOk();
        $order->assignments()->update(['unassigned_at' => now()]);
        $this->actingAs($staff)->get('/reports/work-orders')->assertOk()->assertSee($order->work_order_number);
        $this->actingAs($staff)->get('/dashboard')->assertOk()->assertViewHas('counts', fn ($counts) => ! isset($counts['COMPLETED']));
    }

    public function test_mobile_retry_does_not_duplicate_workflow_notification(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $requester = $this->user('Requester');
        $order = $this->order($requester, WorkOrderStatus::ForAssessment);
        app(WorkOrderAssignmentService::class)->add($order, $head, [$this->person($staff)->id]);
        $payload = [
            'client_operation_id' => '33aca06f-90c3-4593-b9b8-e9551d9ea030',
            'type' => 'SUBMIT_ASSESSMENT', 'work_order_id' => $order->id,
            'payload' => ['outcome' => 'READY_FOR_WORK', 'findings' => 'Repair can proceed'],
        ];
        $token = $staff->createToken('retry-test')->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/mobile/operations', $payload)->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/mobile/operations', $payload)->assertOk();
        $this->assertSame(1, $requester->notifications()->where('data->event_key', 'ASSESSMENT_READY')->count());
    }

    public function test_notification_failure_does_not_abort_workflow_record(): void
    {
        $this->app->bind(WorkflowNotificationService::class, fn () => new class extends WorkflowNotificationService
        {
            public function workflow(WorkOrderWorkflowEvent $event): void
            {
                throw new \RuntimeException('Simulated notification outage');
            }
        });
        $requester = $this->user('Requester');
        $order = $this->order($requester);
        app(WorkOrderWorkflowService::class)->recordCreation($order, $requester);
        $this->assertDatabaseHas('work_order_workflow_events', ['work_order_id' => $order->id, 'action' => 'SUBMITTED']);
    }
}
