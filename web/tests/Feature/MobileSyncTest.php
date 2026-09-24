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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileSyncTest extends TestCase
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

    private function order(User $requester, User $head, FmoPersonnel $person, WorkOrderStatus $status): WorkOrder
    {
        $campus = Campus::firstOrCreate(['code' => 'DC'], ['name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::firstOrCreate(['campus_id' => $campus->id, 'name' => 'Main'], ['is_active' => true]);
        $category = WorkOrderCategory::firstOrCreate(['name' => 'Electrical'], ['is_active' => true]);
        $order = WorkOrder::create(['work_order_number' => 'WO-2026-'.str_pad((string) (WorkOrder::count() + 1), 6, '0', STR_PAD_LEFT), 'requester_id' => $requester->id, 'campus_id' => $campus->id, 'building_id' => $building->id, 'work_order_category_id' => $category->id, 'subject' => 'Repair light', 'description' => 'Light is broken', 'urgency' => 'NORMAL', 'status' => $status, 'submitted_at' => now()]);
        app(WorkOrderAssignmentService::class)->add($order, $head, [$person->id]);

        return $order;
    }

    private function apiAs(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('mobile-sync-test')->plainTextToken);
    }

    public function test_bootstrap_is_assigned_only_and_removal_is_reflected(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $other = $this->user('FMO Staff');
        $person = $this->person($staff);
        $visible = $this->order($this->user('Requester'), $head, $person, WorkOrderStatus::ReadyForWork);
        $hidden = $this->order($this->user('Requester'), $head, $this->person($other), WorkOrderStatus::ReadyForWork);
        $this->apiAs($staff)->getJson('/api/v1/mobile/bootstrap')->assertOk()->assertSee($visible->work_order_number)->assertDontSee($hidden->work_order_number);
        $this->apiAs($staff)->getJson("/api/v1/work-orders/{$hidden->id}/sessions")->assertForbidden();
        $this->apiAs($other)->getJson('/api/v1/mobile/bootstrap')->assertOk()->assertSee($hidden->work_order_number)->assertDontSee($visible->work_order_number);
        $this->apiAs($this->user('Requester'))->getJson('/api/v1/mobile/bootstrap')->assertForbidden();
    }

    public function test_retried_start_update_and_media_do_not_duplicate(): void
    {
        Storage::fake('local');
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $order = $this->order($this->user('Requester'), $head, $this->person($staff), WorkOrderStatus::ReadyForWork);
        $start = ['client_operation_id' => '33aca06f-90c3-4593-b9b8-e9551d9ea001', 'type' => 'START_WORK', 'work_order_id' => $order->id];
        $first = $this->apiAs($staff)->postJson('/api/v1/mobile/operations', $start)->assertOk()->json('data');
        $this->apiAs($staff)->postJson('/api/v1/mobile/operations', $start)->assertOk()->assertJsonPath('data.session_id', $first['session_id']);
        $this->assertDatabaseCount('work_sessions', 1);
        $update = ['client_operation_id' => '33aca06f-90c3-4593-b9b8-e9551d9ea002', 'type' => 'ADD_WORK_UPDATE', 'work_order_id' => $order->id, 'payload' => ['session_id' => $first['session_id'], 'type' => 'PROGRESS', 'description' => 'Wiring secured']];
        $created = $this->apiAs($staff)->postJson('/api/v1/mobile/operations', $update)->assertOk()->json('data');
        $this->apiAs($staff)->postJson('/api/v1/mobile/operations', $update)->assertOk()->assertJsonPath('data.update_id', $created['update_id']);
        $this->assertDatabaseCount('work_updates', 2);
        $media = ['client_operation_id' => '33aca06f-90c3-4593-b9b8-e9551d9ea003', 'target_kind' => 'UPDATE', 'target_id' => $created['update_id']];
        $upload = $this->apiAs($staff)->post('/api/v1/mobile/media', [...$media, 'file' => UploadedFile::fake()->image('work.jpg')])->assertOk()->json('data');
        $this->apiAs($staff)->post('/api/v1/mobile/media', [...$media, 'file' => UploadedFile::fake()->image('work.jpg')])->assertOk()->assertJsonPath('data.attachment_id', $upload['attachment_id']);
        $this->assertDatabaseCount('work_order_attachments', 1);
        $this->apiAs($staff)->postJson('/api/v1/mobile/operations', [...$start, 'type' => 'SUBMIT_COMPLETION', 'payload' => ['completion_summary' => 'Done', 'work_performed_summary' => 'Fixed']])->assertStatus(409);
    }

    public function test_assessment_retry_and_removed_assignment_conflict(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $order = $this->order($this->user('Requester'), $head, $this->person($staff), WorkOrderStatus::ForAssessment);
        $assessment = ['client_operation_id' => '33aca06f-90c3-4593-b9b8-e9551d9ea004', 'type' => 'SUBMIT_ASSESSMENT', 'work_order_id' => $order->id, 'payload' => ['outcome' => 'READY_FOR_WORK', 'findings' => 'Safe to repair']];
        $first = $this->apiAs($staff)->postJson('/api/v1/mobile/operations', $assessment)->assertOk()->json('data');
        $this->apiAs($staff)->postJson('/api/v1/mobile/operations', $assessment)->assertOk()->assertJsonPath('data.assessment_id', $first['assessment_id']);
        $this->assertDatabaseCount('work_order_assessments', 1);
        $replacement = $this->person($this->user('FMO Staff'));
        app(WorkOrderAssignmentService::class)->add($order->fresh(), $head, [$replacement->id]);
        $assignment = $order->activeAssignments()->firstOrFail();
        $this->actingAs($head)->delete("/work-orders/{$order->id}/assignments/{$assignment->id}", ['reason' => 'Reassigned'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('work_order_assignments', ['id' => $assignment->id, 'active_marker' => null]);
        $next = ['client_operation_id' => '33aca06f-90c3-4593-b9b8-e9551d9ea005', 'type' => 'START_WORK', 'work_order_id' => $order->id];
        $this->apiAs($staff)->postJson('/api/v1/mobile/operations', $next)->assertForbidden();
        $this->assertDatabaseCount('work_sessions', 0);
    }

    public function test_uploaded_evidence_precedes_continuation_and_stale_work_is_rejected(): void
    {
        Storage::fake('local');
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $other = $this->user('FMO Staff');
        $staffPerson = $this->person($staff);
        $order = $this->order($this->user('Requester'), $head, $staffPerson, WorkOrderStatus::ReadyForWork);
        app(WorkOrderAssignmentService::class)->add($order, $head, [$this->person($other)->id]);

        $start = $this->apiAs($staff)->postJson('/api/v1/mobile/operations', [
            'client_operation_id' => '33aca06f-90c3-4593-b9b8-e9551d9ea010',
            'type' => 'START_WORK', 'work_order_id' => $order->id,
        ])->assertOk()->json('data');
        $update = $this->apiAs($staff)->postJson('/api/v1/mobile/operations', [
            'client_operation_id' => '33aca06f-90c3-4593-b9b8-e9551d9ea011',
            'type' => 'ADD_WORK_UPDATE', 'work_order_id' => $order->id,
            'payload' => ['session_id' => $start['session_id'], 'type' => 'PROGRESS', 'description' => 'Current wiring state'],
        ])->assertOk()->json('data');
        $media = [
            'client_operation_id' => '33aca06f-90c3-4593-b9b8-e9551d9ea012',
            'target_kind' => 'UPDATE', 'target_id' => $update['update_id'],
            'file' => UploadedFile::fake()->image('state.jpg'),
        ];
        $this->apiAs($other)->post('/api/v1/mobile/media', $media)->assertForbidden();
        $this->apiAs($staff)->post('/api/v1/mobile/media', $media)->assertOk();
        $end = [
            'client_operation_id' => '33aca06f-90c3-4593-b9b8-e9551d9ea013',
            'type' => 'END_WORK_SESSION', 'work_order_id' => $order->id,
            'payload' => ['session_id' => $start['session_id'], 'outcome' => 'CONTINUATION',
                'summary' => 'Further rewiring needed', 'remaining_work' => 'Replace cable'],
        ];
        $this->apiAs($staff)->postJson('/api/v1/mobile/operations', $end)->assertOk()->assertJsonPath('data.status', 'FOR_CONTINUATION');
        $this->apiAs($staff)->postJson('/api/v1/mobile/operations', $end)->assertOk();
        $this->assertDatabaseCount('work_updates', 3);

        $stale = $this->order($this->user('Requester'), $head, $staffPerson, WorkOrderStatus::ReadyForWork);
        $stale->update(['status' => WorkOrderStatus::Completed]);
        $this->apiAs($staff)->postJson('/api/v1/mobile/operations', [
            'client_operation_id' => '33aca06f-90c3-4593-b9b8-e9551d9ea014',
            'type' => 'START_WORK', 'work_order_id' => $stale->id,
        ])->assertStatus(409);
    }
}
