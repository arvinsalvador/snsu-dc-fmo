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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkOrderApprovalFlowAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('testing', config('database.connections.'.config('database.default').'.database'));
        $this->seed(AuthorizationSeeder::class);
    }

    private function user(string $role = 'Requester', ?string $name = null): User
    {
        $user = User::factory()->create(['status' => 'APPROVED', ...($name ? ['name' => $name] : [])]);
        $user->assignRole($role);

        return $user;
    }

    private function fields(array $overrides = []): array
    {
        $campus = Campus::firstOrCreate(['code' => 'DC'], ['name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::firstOrCreate(['campus_id' => $campus->id, 'name' => 'Academic'], ['is_active' => true]);
        $category = WorkOrderCategory::firstOrCreate(['name' => 'Electrical'], ['is_active' => true]);

        return [...['campus_id' => $campus->id, 'building_id' => $building->id, 'work_order_category_id' => $category->id, 'subject' => 'Broken light', 'description' => 'The light needs repair.', 'urgency' => 'NORMAL'], ...$overrides];
    }

    private function submitAs(User $requester, array $overrides = []): WorkOrder
    {
        $this->actingAs($requester)->post('/work-orders', $this->fields($overrides))->assertRedirect();

        return WorkOrder::where('requester_id', $requester->id)->latest('submitted_at')->latest('id')->firstOrFail();
    }

    private function act(User $actor, WorkOrder $order, string $action, array $data = [])
    {
        return $this->actingAs($actor)->post("/work-orders/{$order->id}/workflow/{$action}", $data);
    }

    public function test_approval_stages_cannot_be_skipped_repeated_or_reopened(): void
    {
        $requester = $this->user();
        $head = $this->user('FMO Head');
        $order = $this->submitAs($requester);

        $this->act($head, $order, 'approve')->assertStatus(409);
        $this->act($head, $order, 'recommend-approval')->assertStatus(409);
        $this->act($head, $order, 'begin-screening')->assertRedirect();
        $this->act($head, $order, 'approve')->assertStatus(409);
        $this->act($head, $order, 'recommend-approval')->assertRedirect();
        $this->act($head, $order, 'approve')->assertRedirect();
        $this->act($head, $order, 'approve')->assertStatus(409);

        $order->refresh();
        $this->assertSame(WorkOrderStatus::Approved, $order->status);
        $this->assertSame($head->id, $order->decided_by);
        $this->assertSame(1, $order->workflowEvents()->where('action', 'APPROVE')->count());
        $this->assertSame(1, $requester->notifications()->where('data->event_key', 'APPROVE')->where('data->work_order_id', $order->id)->count());

        $rejected = $this->submitAs($requester, ['subject' => 'Second']);
        $this->act($head, $rejected, 'begin-screening')->assertRedirect();
        $this->act($head, $rejected, 'recommend-disapproval', ['internal_note' => 'Out of scope'])->assertRedirect();
        $this->act($head, $rejected, 'disapprove', ['requester_message' => 'Not FMO work.'])->assertRedirect();
        $this->act($head, $rejected, 'approve')->assertStatus(409);
        $this->act($head, $rejected, 'return-to-screening', ['internal_note' => 'x'])->assertStatus(409);
        $this->assertSame(WorkOrderStatus::Disapproved, $rejected->fresh()->status);
    }

    public function test_nobody_can_screen_or_decide_their_own_request(): void
    {
        $admin = $this->user('System Administrator');
        $order = $this->submitAs($admin);
        foreach (['begin-screening', 'approve', 'disapprove'] as $action) {
            $this->act($admin, $order, $action, ['requester_message' => 'x'])->assertForbidden();
        }
        $this->assertSame(WorkOrderStatus::Submitted, $order->fresh()->status);

        $director = $this->user('Director for Instruction');
        $director->givePermissionTo(['work_orders.screen', 'work_orders.recommend', 'work_orders.approve']);
        $own = $this->submitAs($director);
        $this->act($director, $own, 'begin-screening')->assertForbidden();
        $head = $this->user('FMO Head');
        $this->act($head, $own, 'begin-screening')->assertRedirect();
        $this->act($head, $own, 'recommend-approval')->assertRedirect();
        $this->act($director, $own, 'approve')->assertForbidden();
        $this->withToken($director->createToken('t')->plainTextToken)
            ->postJson("/api/v1/work-orders/{$own->id}/workflow/approve")->assertForbidden();
        $this->assertSame(WorkOrderStatus::ForApproval, $own->fresh()->status);
        $this->act($head, $own, 'approve')->assertRedirect();
    }

    public function test_requester_who_is_also_management_never_sees_internal_workflow_notes_on_their_own_request(): void
    {
        $director = $this->user('Director for Instruction');
        $head = $this->user('FMO Head');
        $own = $this->submitAs($director, ['subject' => 'Own request']);
        $this->act($head, $own, 'begin-screening')->assertRedirect();
        $this->act($head, $own, 'request-information', ['requester_message' => 'Which room?', 'internal_note' => 'SECRET screening remark'])->assertRedirect();

        $this->actingAs($director)->get("/work-orders/{$own->id}")->assertOk()
            ->assertSee('Which room?')->assertDontSee('SECRET screening remark');
        $this->withToken($director->createToken('t')->plainTextToken)->getJson("/api/v1/work-orders/{$own->id}")
            ->assertOk()->assertSee('Which room?')->assertDontSee('SECRET screening remark');

        $other = $this->submitAs($this->user(), ['subject' => 'Someone else']);
        $this->act($head, $other, 'begin-screening')->assertRedirect();
        $this->act($head, $other, 'request-information', ['requester_message' => 'Which floor?', 'internal_note' => 'MANAGEMENT remark'])->assertRedirect();
        $this->actingAs($director)->get("/work-orders/{$other->id}")->assertOk()->assertSee('MANAGEMENT remark');
    }

    public function test_direct_authorization_is_never_available_to_fmo_personnel(): void
    {
        $head = $this->user('FMO Head');
        $head->givePermissionTo('work_orders.create_direct');
        $this->actingAs($head)->post('/work-orders/direct', $this->fields())->assertForbidden();
        $this->withToken($head->createToken('t')->plainTextToken)->postJson('/api/v1/work-orders/direct', $this->fields())->assertForbidden();

        $admin = $this->user('System Administrator');
        $admin->assignRole('FMO Head');
        $this->actingAs($admin)->post('/work-orders/direct', $this->fields())->assertForbidden();
        $this->assertDatabaseCount('work_orders', 0);

        $director = $this->user('Campus Director');
        $this->actingAs($director)->post('/work-orders/direct', $this->fields())->assertRedirect();
        $this->assertDatabaseHas('work_orders', ['requester_id' => $director->id, 'status' => 'APPROVED', 'decided_by' => $director->id]);
    }

    public function test_resubmission_and_final_decision_notify_the_right_people_once(): void
    {
        $requester = $this->user();
        $head = $this->user('FMO Head');
        $dispatcher = $this->user('FMO Dispatcher');
        $bystander = $this->user('FMO Staff');
        $order = $this->submitAs($requester);

        $this->act($dispatcher, $order, 'begin-screening')->assertRedirect();
        $this->act($dispatcher, $order, 'request-information', ['requester_message' => 'Which room?'])->assertRedirect();
        $before = $dispatcher->notifications()->count();
        $this->actingAs($requester)->post("/work-orders/{$order->id}/workflow/resubmit", [...$this->fields(), 'requester_message' => 'Room 12'])->assertRedirect();

        $this->assertSame($before + 1, $dispatcher->notifications()->count());
        $this->assertSame(1, $head->notifications()->where('data->event_key', 'RESUBMIT')->where('data->work_order_id', $order->id)->count());
        $this->assertSame(0, $bystander->notifications()->count());
        $this->assertSame(0, $requester->notifications()->where('data->event_key', 'RESUBMIT')->count());
        $this->assertSame(WorkOrderStatus::ForScreening, $order->fresh()->status);
        $this->assertSame(1, WorkOrder::count());

        $approvalsBefore = $head->notifications()->where('data->event_key', 'RECOMMEND_APPROVAL')->count();
        $this->act($dispatcher, $order, 'recommend-approval')->assertRedirect();
        $this->assertSame($approvalsBefore + 1, $head->notifications()->where('data->event_key', 'RECOMMEND_APPROVAL')->count());
        $this->assertSame(0, $dispatcher->notifications()->where('data->event_key', 'RECOMMEND_APPROVAL')->count());
        $this->assertSame(0, $requester->notifications()->where('data->event_key', 'RECOMMEND_APPROVAL')->count());
    }

    public function test_final_approval_leaves_preferred_personnel_unassigned_and_hidden_from_my_tasks(): void
    {
        $requester = $this->user();
        $head = $this->user('FMO Head');
        $electrical = Skill::create(['name' => 'Electrical', 'is_active' => true]);
        $fields = $this->fields();
        WorkOrderCategory::find($fields['work_order_category_id'])->relevantSkills()->attach($electrical);
        $staffUser = $this->user('FMO Staff', 'Preferred Sparky');
        $preferred = FmoPersonnel::create(['user_id' => $staffUser->id, 'personnel_status' => 'ACTIVE']);
        $preferred->skills()->attach($electrical, ['is_primary' => true]);

        $order = $this->submitAs($requester, ['preferred_fmo_personnel_ids' => [$preferred->id]]);
        foreach (['begin-screening', 'recommend-approval', 'approve'] as $action) {
            $this->act($head, $order, $action)->assertRedirect();
        }

        $this->assertSame(WorkOrderStatus::Approved, $order->fresh()->status);
        $this->assertSame([$preferred->id], $order->preferredPersonnel()->pluck('fmo_personnel.id')->all());
        $this->assertDatabaseCount('work_order_assignments', 0);
        $this->actingAs($staffUser)->get('/work-orders/my-tasks')->assertOk()->assertDontSee($order->work_order_number);
        $this->get("/work-orders/{$order->id}")->assertForbidden();
        $this->assertSame(0, $staffUser->notifications()->count());
        $this->actingAs($head)->get('/work-orders/assignment-queue')->assertOk()->assertSee($order->work_order_number);
    }

    public function test_another_requester_is_isolated_on_every_surface(): void
    {
        Storage::fake('local');
        $owner = $this->user();
        $intruder = $this->user();
        $order = $this->submitAs($owner, ['attachments' => [UploadedFile::fake()->image('a.png')]]);
        $attachment = $order->attachments()->firstOrFail();

        $this->flushSession();
        $this->actingAs($intruder)->get('/my-work-orders')->assertOk()->assertDontSee($order->work_order_number);
        $this->get("/work-orders/{$order->id}")->assertForbidden();
        $this->get("/work-orders/{$order->id}/edit")->assertForbidden();
        $this->put("/work-orders/{$order->id}", $this->fields())->assertForbidden();
        foreach (['', '/thumbnail', '/download'] as $suffix) {
            $this->get("/work-orders/{$order->id}/attachments/{$attachment->id}{$suffix}")->assertForbidden();
        }
        $this->post("/work-orders/{$order->id}/workflow/resubmit", [...$this->fields(), 'requester_message' => 'x'])->assertForbidden();
        $token = $intruder->createToken('t')->plainTextToken;
        $this->withToken($token)->getJson("/api/v1/work-orders/{$order->id}")->assertForbidden();
        $this->withToken($token)->patchJson("/api/v1/work-orders/{$order->id}", $this->fields())->assertForbidden();
        $this->withToken($token)->getJson("/api/v1/work-orders/{$order->id}/attachments/{$attachment->id}")->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/work-orders')->assertOk()->assertDontSee($order->work_order_number);
    }
}
