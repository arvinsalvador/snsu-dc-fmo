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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkOrderRequestTest extends TestCase
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

    private function context(): array
    {
        $campus = Campus::create(['code' => 'DC', 'name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::create(['campus_id' => $campus->id, 'name' => 'Academic Building', 'is_active' => true]);
        $category = WorkOrderCategory::create(['name' => 'Electrical Repair', 'is_active' => true]);

        return compact('campus', 'building', 'category');
    }

    private function payload(array $context, array $overrides = []): array
    {
        return [...['work_order_category_id' => $context['category']->id, 'campus_id' => $context['campus']->id, 'building_id' => $context['building']->id, 'subject' => 'Repair light', 'description' => 'The hallway light is defective.', 'urgency' => 'NORMAL'], ...$overrides];
    }

    public function test_requester_submission_uses_authenticated_identity_and_server_status(): void
    {
        $requester = $this->user();
        $other = $this->user();
        $context = $this->context();
        $this->actingAs($requester)->post('/work-orders', $this->payload($context, ['requester_id' => $other->id, 'status' => 'APPROVED']))->assertRedirect();
        $order = WorkOrder::first();
        $this->assertSame($requester->id, $order->requester_id);
        $this->assertSame(WorkOrderStatus::Submitted, $order->status);
        $this->assertMatchesRegularExpression('/^WO-\d{4}-000001$/', $order->work_order_number);
    }

    public function test_requesters_are_scoped_to_their_own_requests_and_management_can_view_all(): void
    {
        $first = $this->user();
        $second = $this->user();
        $head = $this->user('FMO Head');
        $context = $this->context();
        $one = WorkOrder::create([...$this->payload($context), 'requester_id' => $first->id, 'work_order_number' => 'WO-2026-000001', 'status' => WorkOrderStatus::Submitted, 'submitted_at' => now()]);
        $two = WorkOrder::create([...$this->payload($context, ['subject' => 'Repair chair']), 'requester_id' => $second->id, 'work_order_number' => 'WO-2026-000002', 'status' => WorkOrderStatus::Submitted, 'submitted_at' => now()]);
        $this->actingAs($first)->get('/work-orders/'.$two->id)->assertForbidden();
        $this->actingAs($first)->get('/work-orders')->assertSee($one->work_order_number)->assertDontSee($two->work_order_number);
        $this->actingAs($head)->get('/work-orders')->assertSee($one->work_order_number)->assertSee($two->work_order_number);
    }

    public function test_mismatched_location_hierarchy_is_rejected(): void
    {
        $requester = $this->user();
        $context = $this->context();
        $otherCampus = Campus::create(['code' => 'OT', 'name' => 'Other', 'is_active' => true]);
        $otherBuilding = Building::create(['campus_id' => $otherCampus->id, 'name' => 'Other Building', 'is_active' => true]);
        $this->actingAs($requester)->post('/work-orders', $this->payload($context, ['building_id' => $otherBuilding->id]))->assertSessionHasErrors('building_id');
    }

    public function test_valid_uploads_are_private_and_authorized(): void
    {
        Storage::fake('local');
        $owner = $this->user();
        $other = $this->user();
        $context = $this->context();
        $this->actingAs($owner)->post('/work-orders', $this->payload($context, ['attachments' => [UploadedFile::fake()->image('leak.png')]]))->assertRedirect();
        $order = WorkOrder::first();
        $attachment = $order->attachments()->first();
        Storage::disk('local')->assertExists($attachment->stored_path);
        $this->actingAs($other)->get('/work-orders/'.$order->id.'/attachments/'.$attachment->id)->assertForbidden();
        $this->actingAs($owner)->get('/work-orders/'.$order->id.'/attachments/'.$attachment->id)->assertOk();
    }

    public function test_category_management_is_permission_protected_and_referenced_categories_are_not_deleted(): void
    {
        $requester = $this->user();
        $head = $this->user('FMO Head');
        $context = $this->context();
        $this->actingAs($requester)->post('/work-order-categories', ['name' => 'Unsafe', 'is_active' => true])->assertForbidden();
        $this->actingAs($head)->post('/work-order-categories', ['name' => 'Painting Repair', 'is_active' => true, 'display_order' => 1])->assertRedirect();
        WorkOrder::create([...$this->payload($context), 'requester_id' => $requester->id, 'work_order_number' => 'WO-2026-000001', 'status' => WorkOrderStatus::Submitted, 'submitted_at' => now()]);
        $this->actingAs($head)->delete('/work-order-categories/'.$context['category']->id)->assertStatus(409);
    }

    public function test_api_creation_and_scope_are_enforced(): void
    {
        $requester = $this->user();
        $other = $this->user();
        $context = $this->context();
        WorkOrder::create([...$this->payload($context, ['subject' => 'Other requester request']), 'requester_id' => $other->id, 'work_order_number' => 'WO-2026-000099', 'status' => WorkOrderStatus::Submitted, 'submitted_at' => now()]);
        $token = $requester->createToken('test')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/work-orders', $this->payload($context))->assertCreated()->assertJsonPath('data.status', 'SUBMITTED');
        $this->withToken($token)->getJson('/api/v1/work-orders')->assertJsonCount(1, 'data');
    }
}
