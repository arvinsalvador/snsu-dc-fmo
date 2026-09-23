<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\Campus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    private function user(string $role = 'Requester'): User
    {
        $user = User::factory()->create(['status' => 'APPROVED']);
        $user->assignRole($role);

        return $user;
    }

    private function fields(): array
    {
        $campus = Campus::firstOrCreate(['code' => 'DC'], ['name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::firstOrCreate(['campus_id' => $campus->id, 'name' => 'Academic'], ['is_active' => true]);
        $category = WorkOrderCategory::firstOrCreate(['name' => 'Electrical'], ['is_active' => true]);

        return ['campus_id' => $campus->id, 'building_id' => $building->id, 'work_order_category_id' => $category->id, 'subject' => 'Broken light', 'description' => 'The light needs repair.', 'urgency' => 'NORMAL'];
    }

    private function submitted(User $requester): WorkOrder
    {
        $this->actingAs($requester)->post('/work-orders', $this->fields())->assertRedirect();

        return WorkOrder::latest('submitted_at')->firstOrFail();
    }

    public function test_screening_correction_and_private_notes(): void
    {
        $requester = $this->user();
        $other = $this->user();
        $dispatcher = $this->user('FMO Dispatcher');
        $order = $this->submitted($requester);
        $this->actingAs($requester)->post("/work-orders/{$order->id}/workflow/begin-screening")->assertForbidden();
        $this->actingAs($requester)->post("/work-orders/{$order->id}/workflow/request-information")->assertForbidden();
        $this->actingAs($dispatcher)->post("/work-orders/{$order->id}/workflow/begin-screening")->assertRedirect();
        $this->assertSame(WorkOrderStatus::ForScreening, $order->fresh()->status);
        $this->actingAs($dispatcher)->post("/work-orders/{$order->id}/workflow/request-information", ['requester_message' => 'Please specify the room.', 'internal_note' => 'Coordinate with facilities.'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::NeedsInformation, $order->fresh()->status);
        $this->actingAs($requester)->get("/work-orders/{$order->id}")->assertOk()->assertSee('Please specify the room.')->assertDontSee('Coordinate with facilities.');
        $this->actingAs($other)->post("/work-orders/{$order->id}/workflow/resubmit", [...$this->fields(), 'requester_message' => 'Room 12'])->assertForbidden();
        $this->actingAs($requester)->post("/work-orders/{$order->id}/workflow/resubmit", [...$this->fields(), 'subject' => 'Room 12 light', 'requester_message' => 'It is in Room 12.', 'status' => 'APPROVED', 'requester_id' => $other->id, 'internal_note' => 'Injected'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::ForScreening, $order->fresh()->status);
        $this->assertSame('Room 12 light', $order->fresh()->subject);
        $this->assertSame($requester->id, $order->fresh()->requester_id);
        $this->assertDatabaseMissing('work_order_workflow_events', ['internal_note' => 'Injected']);
        $this->assertDatabaseCount('work_order_workflow_events', 4);
    }

    public function test_recommendation_is_separate_from_final_decision_and_stale_decisions_conflict(): void
    {
        $requester = $this->user();
        $dispatcher = $this->user('FMO Dispatcher');
        $head = $this->user('FMO Head');
        $order = $this->submitted($requester);
        $this->actingAs($dispatcher)->post("/work-orders/{$order->id}/workflow/begin-screening")->assertRedirect();
        $this->actingAs($dispatcher)->post("/work-orders/{$order->id}/workflow/recommend-disapproval", ['internal_note' => 'May be out of scope.'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::ForApproval, $order->fresh()->status);
        $this->assertSame('DISAPPROVE', $order->fresh()->recommendation);
        $this->actingAs($dispatcher)->post("/work-orders/{$order->id}/workflow/approve")->assertForbidden();
        $this->actingAs($head)->post("/work-orders/{$order->id}/workflow/disapprove", ['requester_message' => 'Outside FMO scope.'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::Disapproved, $order->fresh()->status);
        $this->assertSame($head->id, $order->fresh()->decided_by);
        $this->assertNotNull($order->fresh()->decided_at);
        $this->actingAs($head)->post("/work-orders/{$order->id}/workflow/approve")->assertStatus(409);
        $this->actingAs($requester)->get("/work-orders/{$order->id}")->assertOk()->assertSee('Outside FMO scope.')->assertDontSee('May be out of scope.');
        $this->actingAs($requester)->put("/work-orders/{$order->id}", $this->fields())->assertForbidden();
    }

    public function test_approval_and_direct_campus_director_request(): void
    {
        $requester = $this->user();
        $head = $this->user('FMO Head');
        $director = $this->user('Campus Director');
        $order = $this->submitted($requester);
        $this->actingAs($head)->post("/work-orders/{$order->id}/workflow/begin-screening")->assertRedirect();
        $this->actingAs($head)->post("/work-orders/{$order->id}/workflow/recommend-approval")->assertRedirect();
        $this->actingAs($head)->post("/work-orders/{$order->id}/workflow/approve")->assertRedirect();
        $this->assertSame(WorkOrderStatus::Approved, $order->fresh()->status);
        $this->actingAs($requester)->post('/work-orders/direct', [...$this->fields(), 'direct_request' => true])->assertForbidden();
        $this->actingAs($requester)->post('/work-orders', [...$this->fields(), 'direct_request' => true])->assertRedirect();
        $this->assertDatabaseHas('work_orders', ['requester_id' => $requester->id, 'status' => 'SUBMITTED']);
        $this->actingAs($director)->post('/work-orders/direct', $this->fields())->assertRedirect();
        $direct = WorkOrder::where('requester_id', $director->id)->firstOrFail();
        $this->assertSame(WorkOrderStatus::Approved, $direct->status);
        $this->assertSame($director->id, $direct->decided_by);
        $this->assertDatabaseHas('work_order_workflow_events', ['work_order_id' => $direct->id, 'action' => 'DIRECT_AUTHORIZATION']);
    }

    public function test_delegation_and_instruction_oversight(): void
    {
        $staff = $this->user('FMO Staff');
        $instruction = $this->user('Director for Instruction');
        $head = $this->user('FMO Head');
        $order = $this->submitted($this->user());
        $this->actingAs($staff)->post("/work-orders/{$order->id}/workflow/begin-screening")->assertForbidden();
        $staff->givePermissionTo('work_orders.screen');
        $this->actingAs($staff)->post("/work-orders/{$order->id}/workflow/begin-screening")->assertRedirect();
        $this->actingAs($staff)->get("/work-orders/{$order->id}")->assertOk();
        $this->actingAs($instruction)->get('/work-orders')->assertSee($order->work_order_number);
        $this->actingAs($instruction)->post("/work-orders/{$order->id}/workflow/recommend-approval")->assertForbidden();
        $this->actingAs($head)->post("/work-orders/{$order->id}/workflow/recommend-approval")->assertRedirect();
        $this->actingAs($instruction)->post("/work-orders/{$order->id}/workflow/approve")->assertForbidden();
    }

    public function test_api_workflow_and_history_scope(): void
    {
        $requester = $this->user();
        $head = $this->user('FMO Head');
        $order = $this->submitted($requester);
        $this->post('/logout');
        $headToken = $head->createToken('head')->plainTextToken;
        $this->withToken($headToken)->postJson("/api/v1/work-orders/{$order->id}/workflow/begin-screening")->assertOk()->assertJsonPath('data.status', 'FOR_SCREENING');
        $this->withToken($headToken)->postJson("/api/v1/work-orders/{$order->id}/workflow/request-information", ['requester_message' => 'Which room?', 'internal_note' => 'Internal only.'])->assertOk();
        $this->withToken($headToken)->getJson("/api/v1/work-orders/{$order->id}")->assertSee('Internal only.');
        $requesterToken = $requester->createToken('requester')->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($requesterToken)->getJson("/api/v1/work-orders/{$order->id}")->assertSee('Which room?')->assertDontSee('Internal only.');
    }

    public function test_queues_and_correction_form_render_and_required_reasons_are_enforced(): void
    {
        $requester = $this->user();
        $dispatcher = $this->user('FMO Dispatcher');
        $head = $this->user('FMO Head');
        $order = $this->submitted($requester);
        $this->actingAs($dispatcher)->get('/work-orders/queue/screening')->assertOk()->assertSee($order->work_order_number);
        $this->actingAs($requester)->get('/work-orders/queue/screening')->assertForbidden();
        $this->actingAs($dispatcher)->post("/work-orders/{$order->id}/workflow/begin-screening")->assertRedirect();
        $this->actingAs($dispatcher)->post("/work-orders/{$order->id}/workflow/request-information")->assertSessionHasErrors('requester_message');
        $this->actingAs($dispatcher)->post("/work-orders/{$order->id}/workflow/request-information", ['requester_message' => 'Which room?'])->assertRedirect();
        $this->actingAs($requester)->get("/work-orders/{$order->id}/edit")->assertOk()->assertSee('FMO requested additional information');
        $this->actingAs($requester)->post("/work-orders/{$order->id}/workflow/resubmit", [...$this->fields(), 'requester_message' => 'Room 12'])->assertRedirect();
        $this->actingAs($dispatcher)->post("/work-orders/{$order->id}/workflow/recommend-approval")->assertRedirect();
        $this->actingAs($head)->get('/work-orders/queue/approval')->assertOk()->assertSee($order->work_order_number);
        $this->actingAs($head)->post("/work-orders/{$order->id}/workflow/disapprove")->assertSessionHasErrors('requester_message');
        $this->actingAs($head)->post("/work-orders/{$order->id}/workflow/return-to-screening", ['internal_note' => 'Review the location again.'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::ForScreening, $order->fresh()->status);
    }
}
