<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Services\GuidedDescriptionService;
use App\Services\WorkOrderAssignmentService;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\RoutineOperationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkOrderExecutionTest extends TestCase
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

    private function ready(User $requester, User $head, array $people): WorkOrder
    {
        $campus = Campus::firstOrCreate(['code' => 'DC'], ['name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::firstOrCreate(['campus_id' => $campus->id, 'name' => 'Main'], ['is_active' => true]);
        $category = WorkOrderCategory::firstOrCreate(['name' => 'Electrical'], ['is_active' => true]);
        $order = WorkOrder::create(['work_order_number' => 'WO-2026-'.str_pad((string) (WorkOrder::count() + 1), 6, '0', STR_PAD_LEFT), 'requester_id' => $requester->id, 'campus_id' => $campus->id, 'building_id' => $building->id, 'work_order_category_id' => $category->id, 'subject' => 'Repair light', 'description' => 'Light is broken', 'urgency' => 'NORMAL', 'status' => WorkOrderStatus::ReadyForWork, 'submitted_at' => now()]);
        app(WorkOrderAssignmentService::class)->add($order, $head, array_map(fn ($person) => $person->id, $people));

        return $order;
    }

    private function apiAs(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('execution-test')->plainTextToken);
    }

    public function test_one_active_session_per_staff_and_independent_team_sessions(): void
    {
        $head = $this->user('FMO Head');
        $requester = $this->user('Requester');
        $a = $this->user('FMO Staff');
        $b = $this->user('FMO Staff');
        $personA = $this->person($a);
        $one = $this->ready($requester, $head, [$personA, $this->person($b)]);
        $two = $this->ready($requester, $head, [$personA]);
        $this->actingAs($requester)->post("/work-orders/{$one->id}/start-work")->assertForbidden();
        $this->actingAs($a)->post("/work-orders/{$one->id}/start-work")->assertRedirect();
        $this->assertSame(WorkOrderStatus::InProgress, $one->fresh()->status);
        $this->actingAs($a)->post("/work-orders/{$one->id}/start-work")->assertSessionHasErrors('session');
        $this->actingAs($a)->post("/work-orders/{$two->id}/start-work")->assertSessionHasErrors('session');
        $this->actingAs($b)->post("/work-orders/{$one->id}/start-work")->assertRedirect();
        $this->assertSame(2, $one->activeSessions()->count());
        $this->actingAs($a)->get('/work-orders/my-tasks')->assertOk();
        $sessionA = $one->activeSessions()->where('fmo_personnel_id', $personA->id)->firstOrFail();
        $this->actingAs($b)->post("/work-orders/{$one->id}/sessions/{$sessionA->id}/updates", ['type' => 'PROGRESS', 'description' => 'Not mine'])->assertForbidden();
        $this->actingAs($a)->post("/work-orders/{$one->id}/sessions/{$sessionA->id}/end", ['outcome' => 'CONTRIBUTION_COMPLETE', 'summary' => 'My part is complete'])->assertRedirect();
        $this->actingAs($a)->post("/work-orders/{$one->id}/sessions/{$sessionA->id}/end", ['outcome' => 'PAUSED', 'summary' => 'Duplicate'])->assertStatus(409);
        $this->assertSame(WorkOrderStatus::InProgress, $one->fresh()->status);
        $this->actingAs($a)->post("/work-orders/{$two->id}/start-work")->assertRedirect();
    }

    public function test_evidence_completion_verification_and_return(): void
    {
        Storage::fake('local');
        $head = $this->user('FMO Head');
        $requester = $this->user('Requester');
        $staff = $this->user('FMO Staff');
        $order = $this->ready($requester, $head, [$this->person($staff)]);
        $this->actingAs($staff)->post("/work-orders/{$order->id}/start-work")->assertRedirect();
        $session = $order->activeSessions()->firstOrFail();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/updates", ['type' => 'BEFORE', 'description' => 'Broken fixture before work', 'evidence' => [UploadedFile::fake()->image('before.jpg')]])->assertRedirect();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/updates", ['type' => 'PROGRESS', 'description' => 'Invalid evidence', 'evidence' => [UploadedFile::fake()->create('notes.txt', 2, 'text/plain')]])->assertSessionHasErrors('evidence.0');
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/updates", ['type' => 'PROGRESS', 'description' => 'Wiring repaired', 'requester_summary' => 'Repair is underway', 'evidence' => [UploadedFile::fake()->image('progress.jpg')]])->assertRedirect();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/submit-for-verification", ['completion_summary' => 'Done', 'work_performed_summary' => 'Rewired'])->assertSessionHasErrors('session');
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/updates", ['type' => 'COMPLETION', 'description' => 'Fixture restored', 'evidence' => [UploadedFile::fake()->image('done.jpg')]])->assertRedirect();
        $evidence = $order->attachments()->where('purpose', 'EXECUTION')->firstOrFail();
        Storage::disk('local')->assertExists($evidence->stored_path);
        Storage::disk('local')->assertExists($evidence->thumbnail_path);
        $this->actingAs($requester)->get("/work-orders/{$order->id}")->assertOk()->assertSee('Repair is underway')->assertDontSee('Wiring repaired')->assertDontSee('before.jpg');
        $this->actingAs($requester)->get("/work-orders/{$order->id}/attachments/{$evidence->id}")->assertForbidden();
        $this->actingAs($head)->get("/work-orders/{$order->id}/attachments/{$evidence->id}")->assertOk();
        $this->actingAs($head)->get("/work-orders/{$order->id}/attachments/{$evidence->id}/thumbnail")->assertOk();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/end", ['outcome' => 'CONTRIBUTION_COMPLETE', 'summary' => 'Work finished'])->assertRedirect();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/submit-for-verification", ['completion_summary' => 'Fixture operational', 'work_performed_summary' => 'Wiring and fixture replaced', 'requester_summary' => 'Repair completed; awaiting FMO verification'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::ForVerification, $order->fresh()->status);
        $this->actingAs($staff)->post("/work-orders/{$order->id}/verify")->assertForbidden();
        $this->actingAs($head)->post("/work-orders/{$order->id}/return-for-work", ['reason' => 'Secure the cover'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::ForContinuation, $order->fresh()->status);
        $this->actingAs($staff)->post("/work-orders/{$order->id}/start-work")->assertRedirect();
        $second = $order->activeSessions()->firstOrFail();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$second->id}/end", ['outcome' => 'WORK_ORDER_COMPLETION_SUBMITTED', 'summary' => 'Cover secured', 'completion_summary' => 'All work finished', 'work_performed_summary' => 'Fixture repaired and cover secured'])->assertSessionHasErrors('non_photo_reason');
        $this->assertDatabaseHas('work_sessions', ['id' => $second->id, 'ended_at' => null]);
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$second->id}/end", ['outcome' => 'WORK_ORDER_COMPLETION_SUBMITTED', 'summary' => 'Cover secured', 'completion_summary' => 'All work finished', 'work_performed_summary' => 'Fixture repaired and cover secured', 'non_photo_reason' => 'A separate second photo is not meaningful for this small cover adjustment.', 'requester_summary' => 'Repair is ready for verification'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::ForVerification, $order->fresh()->status);
        $this->actingAs($head)->post("/work-orders/{$order->id}/verify", ['verification_note' => 'Inspected and satisfactory'])->assertRedirect();
        $this->actingAs($head)->post("/work-orders/{$order->id}/verify")->assertStatus(409);
        $this->assertSame(WorkOrderStatus::Completed, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->verified_at);
        $this->actingAs($staff)->post("/work-orders/{$order->id}/start-work")->assertStatus(409);
        $this->actingAs($requester)->get("/work-orders/{$order->id}")->assertOk()->assertSee('Fixture repaired and cover secured');
    }

    public function test_continuation_pause_materials_investigation_and_forced_end(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $order = $this->ready($this->user('Requester'), $head, [$this->person($staff)]);
        $this->actingAs($staff)->post("/work-orders/{$order->id}/start-work")->assertRedirect();
        $session = $order->activeSessions()->firstOrFail();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/end", ['outcome' => 'CONTINUATION', 'summary' => 'More time needed'])->assertSessionHasErrors('remaining_work');
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/end", ['outcome' => 'CONTINUATION', 'summary' => 'More time needed', 'remaining_work' => 'Install second board'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::ForContinuation, $order->fresh()->status);
        $this->actingAs($staff)->post("/work-orders/{$order->id}/start-work")->assertRedirect();
        $session = $order->activeSessions()->firstOrFail();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/end", ['outcome' => 'PAUSED', 'summary' => 'Room unavailable'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::Paused, $order->fresh()->status);
        $this->actingAs($staff)->post("/work-orders/{$order->id}/start-work")->assertRedirect();
        $session = $order->activeSessions()->firstOrFail();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/end", ['outcome' => 'WAITING_FOR_MATERIALS', 'summary' => 'Need lamp'])->assertSessionHasErrors('material_description');
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/end", ['outcome' => 'WAITING_FOR_MATERIALS', 'summary' => 'Need lamp', 'material_description' => 'LED lamp'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::WaitingForMaterials, $order->fresh()->status);
        $this->actingAs($staff)->post("/work-orders/{$order->id}/start-work")->assertRedirect();
        $session = $order->activeSessions()->firstOrFail();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/end", ['outcome' => 'NEEDS_FURTHER_INVESTIGATION', 'summary' => 'Concealed wire damaged'])->assertSessionHasErrors('evidence');
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/end", ['outcome' => 'NEEDS_FURTHER_INVESTIGATION', 'summary' => 'Concealed wire damaged', 'evidence' => [UploadedFile::fake()->image('issue.jpg')]])->assertRedirect();
        $this->assertSame(WorkOrderStatus::NeedsInvestigation, $order->fresh()->status);
        $this->actingAs($staff)->post("/work-orders/{$order->id}/start-work")->assertStatus(409);
        $this->actingAs($head)->post("/work-orders/{$order->id}/release-investigation", ['note' => 'Safe to continue'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::ReadyForWork, $order->fresh()->status);
        $this->actingAs($staff)->post("/work-orders/{$order->id}/start-work")->assertRedirect();
        $session = $order->activeSessions()->firstOrFail();
        $assignment = $order->activeAssignments()->firstOrFail();
        $this->actingAs($head)->delete("/work-orders/{$order->id}/assignments/{$assignment->id}", ['reason' => 'Change staff'])->assertSessionHasErrors('assignment');
        $this->actingAs($head)->post("/work-orders/{$order->id}/sessions/{$session->id}/force-end", ['reason' => 'Staff became unavailable'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::Paused, $order->fresh()->status);
    }

    public function test_api_active_session_scope_and_non_photo_completion_exception(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $outsider = $this->user('FMO Staff');
        $requester = $this->user('Requester');
        $order = $this->ready($requester, $head, [$this->person($staff)]);
        $this->apiAs($outsider)->postJson("/api/v1/work-orders/{$order->id}/start-work")->assertForbidden();
        $this->apiAs($staff)->postJson("/api/v1/work-orders/{$order->id}/start-work")->assertCreated()->assertJsonPath('data.status', 'IN_PROGRESS');
        $session = $order->activeSessions()->firstOrFail();
        $this->apiAs($staff)->getJson('/api/v1/me/active-work-session')->assertOk()->assertJsonPath('data.id', $session->id);
        $this->apiAs($outsider)->getJson("/api/v1/work-orders/{$order->id}/sessions")->assertForbidden();
        $this->apiAs($staff)->postJson("/api/v1/work-orders/{$order->id}/sessions/{$session->id}/updates", ['type' => 'COMPLETION', 'description' => 'Completed a non-visual inspection'])->assertCreated();
        $this->apiAs($staff)->postJson("/api/v1/work-orders/{$order->id}/sessions/{$session->id}/end", ['outcome' => 'CONTRIBUTION_COMPLETE', 'summary' => 'Inspection complete'])->assertOk();
        $this->apiAs($staff)->getJson('/api/v1/me/active-work-session')->assertOk()->assertJsonPath('data', null);
        $this->apiAs($staff)->postJson("/api/v1/work-orders/{$order->id}/submit-for-verification", ['completion_summary' => 'Inspection complete', 'work_performed_summary' => 'Inspected equipment'])->assertUnprocessable()->assertJsonValidationErrors('non_photo_reason');
        $this->apiAs($staff)->postJson("/api/v1/work-orders/{$order->id}/submit-for-verification", ['completion_summary' => 'Inspection complete', 'work_performed_summary' => 'Inspected equipment', 'non_photo_reason' => 'The electrical inspection had no visible physical change to photograph.'])->assertOk()->assertJsonPath('data.status', 'FOR_VERIFICATION');
        $this->apiAs($requester)->getJson("/api/v1/work-orders/{$order->id}")->assertOk()->assertJsonPath('data.status', 'FOR_VERIFICATION')->assertDontSee('Completed a non-visual inspection');
        $this->apiAs($head)->postJson("/api/v1/work-orders/{$order->id}/verify")->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    }

    public function test_active_staff_see_plain_language_guided_actions_and_save_structured_progress(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $order = $this->ready($this->user('Requester'), $head, [$this->person($staff)]);
        $this->seed(RoutineOperationsSeeder::class);
        $this->actingAs($staff)->post("/work-orders/{$order->id}/start-work")->assertRedirect();

        $this->actingAs($staff)->get("/work-orders/{$order->id}")
            ->assertOk()
            ->assertSeeText('What would you like to do?')
            ->assertSeeText('Add Work Progress')
            ->assertSeeText('Stop Work for Now')
            ->assertSeeText('I Cannot Continue')
            ->assertSeeText('My Assigned Work Is Finished')
            ->assertSeeText('The Whole Job Is Finished')
            ->assertSeeText('Checked electrical wiring')
            ->assertDontSeeText('Update Type')
            ->assertDontSeeText('Session Summary')
            ->assertDontSeeText('Contribution Complete');

        $session = $order->activeSessions()->firstOrFail();
        $summary = 'Inspected the affected area, replaced the damaged electrical outlet, and tested the installation after repair. The reported issue is now working properly.';
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/updates", [
            'staff_action' => 'progress', 'type' => 'OTHER', 'guided_actions' => ['INSPECT_AREA', 'REPLACE_OUTLET', 'TEST_REPAIR'],
            'guided_outcome' => 'RESOLVED', 'description' => $summary,
        ])->assertRedirect();
        $update = $order->updates()->where('type', 'PROGRESS')->latest('recorded_at')->firstOrFail();
        $this->assertSame(['INSPECT_AREA', 'REPLACE_OUTLET', 'TEST_REPAIR'], $update->structured_actions);
        $this->assertSame('RESOLVED', $update->guided_outcome);
        $this->assertSame($summary, $update->generated_summary);
        $this->assertSame($summary, $update->description);
    }

    public function test_plain_language_stop_and_blocker_choices_map_to_existing_outcomes(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $order = $this->ready($this->user('Requester'), $head, [$this->person($staff)]);
        $this->seed(RoutineOperationsSeeder::class);
        $this->actingAs($staff)->post("/work-orders/{$order->id}/start-work")->assertRedirect();
        $session = $order->activeSessions()->firstOrFail();

        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$session->id}/end", [
            'staff_action' => 'stop', 'stop_reason' => 'CONTINUE_TOMORROW', 'outcome' => 'PAUSED',
            'guided_actions' => ['INSPECT_AREA'], 'guided_outcome' => 'PARTIAL',
            'summary' => 'Inspected the affected area. Work will continue tomorrow.', 'remaining_work' => 'Replace the outlet tomorrow.',
        ])->assertRedirect();
        $this->assertSame('CONTINUATION', $session->fresh()->end_outcome->value);
        $this->assertSame(WorkOrderStatus::ForContinuation, $order->fresh()->status);

        $this->actingAs($staff)->post("/work-orders/{$order->id}/start-work")->assertRedirect();
        $blocked = $order->activeSessions()->firstOrFail();
        $this->actingAs($staff)->post("/work-orders/{$order->id}/sessions/{$blocked->id}/end", [
            'staff_action' => 'blocked', 'blocker_reason' => 'MATERIALS_UNAVAILABLE', 'outcome' => 'PAUSED',
            'guided_actions' => ['INSPECT_AREA'], 'guided_outcome' => 'WAITING_SUPPLIES',
            'summary' => 'Work cannot continue because a replacement electrical outlet is required. The task will resume once the material is available.',
            'material_description' => 'a replacement electrical outlet',
        ])->assertRedirect();
        $this->assertSame('WAITING_FOR_MATERIALS', $blocked->fresh()->end_outcome->value);
        $this->assertSame(WorkOrderStatus::WaitingForMaterials, $order->fresh()->status);
        $this->assertDatabaseHas('work_updates', [
            'work_session_id' => $blocked->id, 'type' => 'WAITING_FOR_MATERIALS',
            'material_description' => 'a replacement electrical outlet', 'guided_outcome' => 'WAITING_SUPPLIES',
            'generated_summary' => 'Inspected the affected area. Work cannot continue because a replacement electrical outlet is required. The task will resume once the material is available.',
        ]);
    }

    public function test_generic_profile_and_other_action_never_require_an_ai_service(): void
    {
        $category = WorkOrderCategory::create(['name' => 'Unmapped Specialist Work', 'is_active' => true]);
        $guided = app(GuidedDescriptionService::class);
        $profile = $guided->profileForCategory($category);

        $this->assertSame('GENERIC', $profile->code);
        $this->assertContains('OTHER_ACTION', collect($profile->actions)->pluck('code')->all());
        $this->assertSame(
            'Secured the custom mounting bracket. The reported issue is now working properly.',
            $guided->generate($profile, ['OTHER_ACTION'], 'RESOLVED', 'secured the custom mounting bracket')
        );
        $this->expectException(ValidationException::class);
        $guided->generate($profile, ['OTHER_ACTION'], 'RESOLVED');
    }
}
