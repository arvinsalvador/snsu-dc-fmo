<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Services\WorkOrderAssignmentService;
use App\Services\PersonnelAccessService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssignedPersonnelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    private function user(string $role = 'Requester'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function person(User $user): FmoPersonnel
    {
        return FmoPersonnel::create(['user_id' => $user->id, 'designation' => 'Technician', 'personnel_status' => 'ACTIVE']);
    }

    private function order(User $requester, string $number): WorkOrder
    {
        $campus = Campus::firstOrCreate(['code' => 'DC'], ['name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::firstOrCreate(['campus_id' => $campus->id, 'name' => 'Academic'], ['is_active' => true]);
        $category = WorkOrderCategory::firstOrCreate(['name' => 'Electrical'], ['is_active' => true]);

        return WorkOrder::create(['requester_id' => $requester->id, 'campus_id' => $campus->id,
            'building_id' => $building->id, 'work_order_category_id' => $category->id,
            'work_order_number' => $number, 'subject' => 'Repair light', 'description' => 'Broken light',
            'urgency' => 'NORMAL', 'status' => 'APPROVED', 'submitted_at' => now()]);
    }

    public function test_personnel_onboarding_provisions_assigned_staff_access(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user();
        $requester = $this->user();
        $this->actingAs($head)->post('/personnel', ['user_id' => $staff->id, 'personnel_status' => 'ACTIVE'])->assertRedirect();
        $person = FmoPersonnel::where('user_id', $staff->id)->firstOrFail();
        $order = $this->order($requester, 'WO-2026-000001');
        $this->post('/work-orders/'.$order->id.'/assignments', ['personnel_ids' => [$person->id]])->assertRedirect();
        $this->assertDatabaseHas('work_order_assignments', ['work_order_id' => $order->id, 'fmo_personnel_id' => $person->id, 'unassigned_at' => null]);
        $notice = $staff->notifications()->where('data->event_key', 'ASSIGNEE_ADDED')->firstOrFail();

        $this->actingAs($staff->fresh())->get('/work-orders/my-tasks')->assertOk()->assertSee($order->work_order_number);
        $this->get($notice->data['action_url'])->assertOk()->assertSee('Acknowledge and begin assessment');
        $this->assertFalse($staff->fresh()->can('work_orders.view_all'));
        $this->get('/notifications')->assertOk()->assertSee($order->work_order_number);
        $this->post('/notifications/'.$notice->id.'/read')->assertRedirect();
        $this->get($notice->data['action_url'])->assertOk();
        $notice->delete();
        $this->get('/work-orders/'.$order->id)->assertOk();
        $this->post('/work-orders/'.$order->id.'/assessment/acknowledge')->assertRedirect();
        $this->get('/work-orders/my-tasks')->assertSee($order->work_order_number);
        $this->post('/work-orders/'.$order->id.'/assessments', ['outcome' => 'READY_FOR_WORK', 'findings' => 'Ready to repair'])->assertRedirect();
        $this->post('/work-orders/'.$order->id.'/start-work')->assertRedirect();
        $this->get('/work-orders/my-tasks')->assertSee($order->work_order_number);
        $this->get('/work-orders/create')->assertForbidden();
        $this->get('/work-orders')->assertForbidden();
    }

    private function apiAs(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('assigned-access')->plainTextToken);
    }

    public function test_assigned_only_idor_shared_work_preference_and_private_attachments(): void
    {
        Storage::fake('local');
        $head = $this->user('FMO Head');
        $requester = $this->user();
        $a = $this->user('FMO Staff');
        $b = $this->user('FMO Staff');
        $c = $this->user('FMO Staff');
        $pa = $this->person($a);
        $pb = $this->person($b);
        $pc = $this->person($c);
        $one = $this->order($requester, 'WO-2026-000001');
        $two = $this->order($requester, 'WO-2026-000002');
        $shared = $this->order($requester, 'WO-2026-000003');
        $one->update(['preferred_fmo_personnel_id' => $pc->id]);
        $assignments = app(WorkOrderAssignmentService::class);
        $assignments->add($one, $head, [$pa->id]);
        $assignments->add($two, $head, [$pb->id]);
        $assignments->add($shared, $head, [$pa->id, $pb->id]);
        Storage::disk('local')->put('request-photo.jpg', 'test evidence');
        $file = $one->attachments()->create(['uploaded_by' => $requester->id, 'purpose' => 'REQUEST_INITIAL', 'original_filename' => 'photo.jpg', 'stored_path' => 'request-photo.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 13]);

        foreach ([[$a, $one, $two], [$b, $two, $one]] as [$staff, $own, $other]) {
            $this->actingAs($staff)->get('/work-orders/my-tasks')->assertOk()->assertSee($own->work_order_number)->assertSee($shared->work_order_number)->assertDontSee($other->work_order_number);
            $this->get('/work-orders/'.$own->id)->assertOk();
            $this->get('/work-orders/'.$shared->id)->assertOk();
            $this->get('/work-orders/'.$other->id)->assertForbidden();
            $this->apiAs($staff)->getJson('/api/v1/work-orders/assigned')->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['id' => $own->id])->assertJsonMissing(['id' => $other->id]);
            $this->getJson('/api/v1/work-orders/'.$own->id)->assertOk();
            $this->getJson('/api/v1/work-orders/'.$other->id)->assertForbidden();
            $this->getJson('/api/v1/mobile/bootstrap')->assertOk()->assertJsonCount(2, 'data.orders')->assertJsonMissing(['id' => $other->id]);
        }

        $this->actingAs($a)->get('/work-orders/'.$one->id.'/attachments/'.$file->id)->assertOk();
        $this->actingAs($b)->get('/work-orders/'.$one->id.'/attachments/'.$file->id)->assertForbidden();
        $this->actingAs($c)->get('/work-orders/my-tasks')->assertOk()->assertDontSee($one->work_order_number)->assertDontSee($shared->work_order_number);
        $this->get('/work-orders/'.$one->id)->assertForbidden();
        $this->assertSame(0, $c->notifications()->where('data->event_key', 'ASSIGNEE_ADDED')->count());
        $this->apiAs($c)->getJson('/api/v1/work-orders/assigned')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/mobile/bootstrap')->assertOk()->assertJsonCount(0, 'data.orders');

        foreach ([$requester, $head, $this->user('Campus Director'), $this->user('Director for Instruction')] as $viewer) {
            $this->actingAs($viewer)->get('/work-orders/'.$one->id)->assertOk();
        }
    }

    public function test_removal_revokes_access_retains_history_and_reassignment_grants_new_person(): void
    {
        $head = $this->user('FMO Head');
        $requester = $this->user();
        $a = $this->user('FMO Staff');
        $b = $this->user('FMO Staff');
        $pa = $this->person($a);
        $pb = $this->person($b);
        $order = $this->order($requester, 'WO-2026-000001');
        $service = app(WorkOrderAssignmentService::class);
        $service->add($order, $head, [$pa->id, $pb->id]);
        $assignment = $order->activeAssignments()->where('fmo_personnel_id', $pa->id)->firstOrFail();
        $notice = $a->notifications()->where('data->event_key', 'ASSIGNEE_ADDED')->firstOrFail();
        $this->actingAs($a)->post('/work-orders/'.$order->id.'/assessment/acknowledge')->assertRedirect();
        $this->post('/work-orders/'.$order->id.'/assessments', ['outcome' => 'NEEDS_INFORMATION', 'findings' => 'Need clarification'])->assertRedirect();
        $service->remove($order, $assignment, $head, 'Reassigned');
        $this->assertDatabaseHas('work_order_assignments', ['id' => $assignment->id, 'fmo_personnel_id' => $pa->id]);
        $this->assertNotNull($assignment->fresh()->unassigned_at);
        $this->assertDatabaseHas('work_order_assessments', ['work_order_assignment_id' => $assignment->id]);
        $this->actingAs($a)->get('/work-orders/my-tasks')->assertOk()->assertDontSee($order->work_order_number);
        $this->get($notice->data['action_url'])->assertForbidden();
        $this->post('/work-orders/'.$order->id.'/assessment/acknowledge')->assertForbidden();
        $this->post('/work-orders/'.$order->id.'/start-work')->assertForbidden();
        $this->apiAs($a)->getJson('/api/v1/work-orders/assigned')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/work-orders/'.$order->id)->assertForbidden();
        $this->getJson('/api/v1/mobile/bootstrap')->assertJsonCount(0, 'data.orders');
        $this->actingAs($b)->get('/work-orders/'.$order->id)->assertOk();
        $service->add($order->fresh(), $head, [$pa->id]);
        $this->actingAs($a)->get('/work-orders/'.$order->id)->assertOk();
        $pa->update(['personnel_status' => 'ON_LEAVE']);
        $this->get('/work-orders/my-tasks')->assertOk()->assertDontSee($order->work_order_number);
        $this->get('/work-orders/'.$order->id)->assertForbidden();
        $this->apiAs($a)->getJson('/api/v1/work-orders/assigned')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/mobile/bootstrap')->assertForbidden();
    }

    public function test_assignment_membership_does_not_bypass_capability_checks(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user();
        $person = $this->person($staff);
        $order = $this->order($this->user(), 'WO-2026-000001');
        app(WorkOrderAssignmentService::class)->add($order, $head, [$person->id]);
        $this->actingAs($staff)->get('/work-orders/my-tasks')->assertForbidden();
        $this->get('/work-orders/'.$order->id)->assertForbidden();
        $staff->givePermissionTo('work_orders.view_assigned');
        $this->actingAs($staff->fresh())->get('/work-orders/'.$order->id)->assertOk();
        $this->post('/work-orders/'.$order->id.'/assessment/acknowledge')->assertForbidden();
    }

    public function test_provisioning_rejects_broad_staff_role_and_ineligible_profiles(): void
    {
        $staff = $this->user();
        $person = $this->person($staff);
        $access = app(PersonnelAccessService::class);
        $person->update(['archived_at' => now()]);
        $this->assertFalse($access->provision($person->fresh()));
        $person->update(['archived_at' => null]);
        $staff->forceFill(['status' => 'SUSPENDED'])->save();
        $this->assertFalse($access->provision($person->fresh()));
        $staff->forceFill(['status' => 'APPROVED'])->save();
        Role::findByName('FMO Staff')->givePermissionTo('work_orders.view_all');
        try {
            $access->provision($person->fresh());
            $this->fail('A broad staff role must not be provisioned.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('must not grant view_all', $exception->getMessage());
        }
        $this->assertFalse($staff->fresh()->hasRole('FMO Staff'));
    }

    public function test_legacy_access_sync_is_additive_repeatable_and_does_not_require_reassignment(): void
    {
        $head = $this->user('FMO Head');
        $legacy = $this->user();
        $legacy->givePermissionTo('skills.view');
        $person = $this->person($legacy);
        $inactive = $this->person($this->user());
        $inactive->update(['personnel_status' => 'INACTIVE']);
        $order = $this->order($this->user(), 'WO-2026-000001');
        app(WorkOrderAssignmentService::class)->add($order, $head, [$person->id]);
        $this->artisan('personnel:sync-staff-access --dry-run')->assertSuccessful();
        $this->assertFalse($legacy->fresh()->hasRole('FMO Staff'));
        $this->artisan('personnel:sync-staff-access')->assertSuccessful();
        $this->artisan('personnel:sync-staff-access')->assertSuccessful();
        $this->assertTrue($legacy->fresh()->hasAllRoles(['Requester', 'FMO Staff']));
        $this->assertTrue($legacy->fresh()->hasDirectPermission('skills.view'));
        $this->assertFalse($legacy->fresh()->can('work_orders.view_all'));
        $this->assertFalse($inactive->user->fresh()->hasRole('FMO Staff'));
        $this->actingAs($legacy->fresh())->get('/work-orders/my-tasks')->assertOk()->assertSee($order->work_order_number);
        $this->get('/work-orders/'.$order->id)->assertOk();
    }
}
