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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkOrderAssessmentTest extends TestCase
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

    private function apiAs(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('assessment-test')->plainTextToken);
    }

    private function order(User $requester): WorkOrder
    {
        $campus = Campus::create(['code' => 'DC', 'name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::create(['campus_id' => $campus->id, 'name' => 'Main', 'is_active' => true]);
        $category = WorkOrderCategory::create(['name' => 'Electrical', 'is_active' => true]);

        return WorkOrder::create(['work_order_number' => 'WO-2026-000001', 'requester_id' => $requester->id, 'campus_id' => $campus->id, 'building_id' => $building->id, 'work_order_category_id' => $category->id, 'subject' => 'Broken light', 'description' => 'Needs investigation', 'urgency' => 'NORMAL', 'status' => 'APPROVED', 'submitted_at' => now()]);
    }

    public function test_assignment_assessment_exception_and_requester_correction(): void
    {
        $requester = $this->user('Requester');
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $other = $this->user('FMO Staff');
        $person = $this->person($staff);
        $order = $this->order($requester);
        $this->actingAs($staff)->get("/work-orders/{$order->id}")->assertForbidden();
        $this->actingAs($head)->post("/work-orders/{$order->id}/assignments", ['personnel_ids' => [$person->id]])->assertRedirect();
        $this->assertSame(WorkOrderStatus::Assigned, $order->fresh()->status);
        $this->actingAs($staff)->get("/work-orders/{$order->id}")->assertOk();
        $this->actingAs($other)->get("/work-orders/{$order->id}")->assertForbidden();
        $this->actingAs($other)->post("/work-orders/{$order->id}/assessment/acknowledge")->assertForbidden();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/assessment/acknowledge")->assertRedirect();
        $this->assertSame(WorkOrderStatus::ForAssessment, $order->fresh()->status);
        $this->actingAs($staff)->post("/work-orders/{$order->id}/assessments", ['outcome' => 'NEEDS_INFORMATION', 'findings' => 'Room number missing'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::AssessmentReview, $order->fresh()->status);
        $this->assertDatabaseCount('work_order_assessments', 1);
        $this->actingAs($requester)->get("/work-orders/{$order->id}")->assertDontSee('Room number missing');
        $this->actingAs($head)->post("/work-orders/{$order->id}/assessment/resolve/request-information", ['requester_message' => 'Which room?', 'internal_note' => 'Internal review'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::NeedsInformation, $order->fresh()->status);
        $this->actingAs($requester)->post("/work-orders/{$order->id}/workflow/resubmit", ['campus_id' => $order->campus_id, 'building_id' => $order->building_id, 'work_order_category_id' => $order->work_order_category_id, 'subject' => 'Room 12 light', 'description' => 'Room 12 light broken', 'urgency' => 'NORMAL'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::ForAssessment, $order->fresh()->status);
    }

    public function test_removed_assignee_loses_access_and_history_is_retained(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $order = $this->order($this->user('Requester'));
        $person = $this->person($staff);
        $this->actingAs($head)->post("/work-orders/{$order->id}/assignments", ['personnel_ids' => [$person->id]])->assertRedirect();
        $assignment = $order->activeAssignments()->firstOrFail();
        $this->actingAs($head)->delete("/work-orders/{$order->id}/assignments/{$assignment->id}", ['reason' => 'Reassigned'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::Approved, $order->fresh()->status);
        $this->assertDatabaseHas('work_order_assignments', ['id' => $assignment->id, 'unassignment_reason' => 'Reassigned']);
        $this->actingAs($staff)->get("/work-orders/{$order->id}")->assertForbidden();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/assessment/acknowledge")->assertForbidden();
    }

    public function test_multiple_assignees_and_api_scope(): void
    {
        $head = $this->user('FMO Head');
        $dispatcher = $this->user('FMO Dispatcher');
        $first = $this->user('FMO Staff');
        $second = $this->user('FMO Staff');
        $outsider = $this->user('FMO Staff');
        $order = $this->order($this->user('Requester'));
        $one = $this->person($first);
        $two = $this->person($second);
        $this->apiAs($head)->postJson("/api/v1/work-orders/{$order->id}/assignments", ['personnel_ids' => [$one->id, $two->id]])->assertCreated()->assertJsonPath('data.status', 'ASSIGNED');
        $this->apiAs($first)->postJson("/api/v1/work-orders/{$order->id}/assessment/acknowledge")->assertOk();
        $this->apiAs($first)->postJson("/api/v1/work-orders/{$order->id}/assessments", ['outcome' => 'READY_FOR_WORK', 'findings' => 'Safe to repair'])->assertCreated()->assertJsonPath('data.status', 'READY_FOR_WORK');
        $this->apiAs($second)->postJson("/api/v1/work-orders/{$order->id}/assessments", ['outcome' => 'NEEDS_MATERIALS', 'findings' => 'Replacement lamp required'])->assertCreated()->assertJsonPath('data.status', 'ASSESSMENT_REVIEW');
        $this->assertDatabaseCount('work_order_assessments', 2);
        $this->apiAs($outsider)->getJson("/api/v1/work-orders/{$order->id}/assessments")->assertForbidden();
        $this->apiAs($dispatcher)->postJson("/api/v1/work-orders/{$order->id}/assessment/resolve/proceed")->assertForbidden();
        $dispatcher->givePermissionTo('work_orders.resolve_assessment');
        $this->apiAs($dispatcher)->postJson("/api/v1/work-orders/{$order->id}/assessment/resolve/proceed")->assertOk()->assertJsonPath('data.status', 'READY_FOR_WORK');
        $this->apiAs($first)->postJson("/api/v1/work-orders/{$order->id}/assessment/resolve/cancel", ['requester_message' => 'Cancelled'])->assertForbidden();
    }

    public function test_assessment_evidence_is_private_to_assignees_and_management(): void
    {
        Storage::fake('local');
        $requester = $this->user('Requester');
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $order = $this->order($requester);
        $person = $this->person($staff);
        $this->actingAs($head)->post("/work-orders/{$order->id}/assignments", ['personnel_ids' => [$person->id]])->assertRedirect();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/assessment/acknowledge")->assertRedirect();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/assessments", ['outcome' => 'READY_FOR_WORK', 'findings' => 'Safe to repair', 'evidence' => [UploadedFile::fake()->image('photo.jpg')]])->assertRedirect();
        $attachment = $order->attachments()->where('purpose', 'ASSESSMENT')->firstOrFail();
        Storage::disk('local')->assertExists($attachment->stored_path);
        $this->actingAs($requester)->get("/work-orders/{$order->id}")->assertOk()->assertDontSee('photo.jpg');
        $this->actingAs($requester)->get("/work-orders/{$order->id}/attachments/{$attachment->id}")->assertForbidden();
        $this->actingAs($staff)->get("/work-orders/{$order->id}/attachments/{$attachment->id}")->assertOk();
    }
}
