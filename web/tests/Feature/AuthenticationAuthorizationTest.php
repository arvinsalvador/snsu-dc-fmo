<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthenticationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    private function user(string $role = 'Requester', AccountStatus $status = AccountStatus::Approved): User
    {
        $user = User::factory()->create(['status' => $status->value]);
        $user->assignRole($role);

        return $user;
    }

    public function test_public_registration_is_pending_and_cannot_inject_authority(): void
    {
        $this->post('/register', [
            'name' => 'Applicant Example', 'email' => 'applicant@example.test',
            'user_type' => 'student', 'password' => 'LongPassword123!',
            'password_confirmation' => 'LongPassword123!',
            'status' => 'APPROVED', 'roles' => ['System Administrator'],
            'permissions' => ['permissions.manage'], 'approved_by' => 1,
        ])->assertRedirect('/registration/status');

        $user = User::whereEmail('applicant@example.test')->firstOrFail();
        $this->assertSame(AccountStatus::Pending, $user->status);
        $this->assertNull($user->approved_by);
        $this->assertFalse($user->hasAnyRole(['Requester', 'System Administrator']));
        $this->assertCount(0, $user->getDirectPermissions());
        $this->assertTrue(password_verify('LongPassword123!', $user->password));
        $this->get('/registration/status')->assertOk()->assertSee('awaiting approval');
        $this->get('/dashboard')->assertRedirect('/registration/status');
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'LongPassword123!', 'device_name' => 'phone',
        ])->assertForbidden()->assertJsonPath('status', 'PENDING');
        $this->assertCount(0, $user->tokens);
    }

    public function test_registration_validates_email_password_and_category(): void
    {
        $this->user()->update(['email' => 'taken@example.test']);
        $this->post('/register', [
            'name' => 'Example', 'email' => 'taken@example.test', 'user_type' => 'director',
            'password' => 'short', 'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['email', 'user_type', 'password']);
    }

    public function test_web_login_logout_and_status_gate(): void
    {
        $user = $this->user();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $this->post('/logout')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');

        foreach ([AccountStatus::Pending, AccountStatus::Rejected, AccountStatus::Suspended, AccountStatus::Deactivated] as $status) {
            $user->forceFill(['status' => $status])->save();
            $this->actingAs($user)->get('/dashboard')->assertRedirect('/registration/status');
        }
    }

    public function test_default_approvers_can_approve_and_staff_cannot(): void
    {
        foreach (['FMO Head', 'Campus Director', 'Director for Instruction', 'System Administrator'] as $role) {
            $applicant = User::factory()->create(['status' => 'PENDING']);
            $approver = $this->user($role);
            $this->actingAs($approver)->post("/admin/registrations/{$applicant->id}/review", [
                'action' => 'approve',
            ])->assertRedirect();
            $applicant->refresh();
            $this->assertSame(AccountStatus::Approved, $applicant->status);
            $this->assertSame($approver->id, $applicant->approved_by);
            $this->assertNotNull($applicant->approved_at);
            $this->assertTrue($applicant->hasRole('Requester'));
            $this->assertCount(1, $applicant->roles);
            $this->assertDatabaseHas('registration_reviews', [
                'user_id' => $applicant->id, 'reviewer_id' => $approver->id, 'action' => 'approved',
            ]);
        }

        $applicant = User::factory()->create(['status' => 'PENDING']);
        foreach (['FMO Staff', 'Requester'] as $role) {
            $this->actingAs($this->user($role))->post("/admin/registrations/{$applicant->id}/review", [
                'action' => 'approve',
            ])->assertForbidden();
        }
        $this->assertSame(AccountStatus::Pending, $applicant->fresh()->status);
    }

    public function test_rejection_correction_and_resubmission_keep_history_and_protected_fields(): void
    {
        $approver = $this->user('FMO Head');
        $applicant = User::factory()->create(['status' => 'PENDING']);
        $url = "/admin/registrations/{$applicant->id}/review";
        $this->actingAs($approver)->post($url, ['action' => 'reject'])->assertSessionHasErrors('reason');
        $this->actingAs($approver)->post($url, ['action' => 'request_correction'])->assertSessionHasErrors('reason');
        $this->actingAs($approver)->post($url, ['action' => 'request_correction', 'reason' => 'Correct name'])->assertRedirect();
        $this->assertSame(AccountStatus::NeedsCorrection, $applicant->fresh()->status);

        $this->actingAs($applicant)->get('/registration/status')->assertOk()->assertSee('Correct name');
        $this->actingAs($applicant->fresh())->put('/registration/correction', [
            'name' => 'Corrected Name', 'email' => $applicant->email, 'user_type' => 'faculty',
            'status' => 'APPROVED', 'roles' => ['System Administrator'],
            'permissions' => ['permissions.manage'], 'approved_by' => $approver->id,
        ])->assertRedirect();
        $applicant->refresh();
        $this->assertSame(AccountStatus::Pending, $applicant->status);
        $this->assertSame('Corrected Name', $applicant->name);
        $this->assertNull($applicant->approved_by);
        $this->assertCount(0, $applicant->roles);
        $this->assertCount(0, $applicant->getDirectPermissions());
        $this->assertDatabaseHas('registration_reviews', ['user_id' => $applicant->id, 'action' => 'resubmitted']);

        $this->actingAs($approver)->post($url, ['action' => 'reject', 'reason' => 'Not eligible'])->assertRedirect();
        $this->assertSame(AccountStatus::Rejected, $applicant->fresh()->status);
        $this->actingAs($applicant)->get('/registration/status')->assertSee('Not eligible');
        $this->assertDatabaseHas('registration_reviews', ['user_id' => $applicant->id, 'action' => 'rejected']);
    }

    public function test_delegation_and_multiple_roles_obey_permission_boundaries(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $staff->assignRole('FMO Dispatcher');
        $this->assertCount(2, $staff->roles);
        $this->actingAs($head)->put("/admin/users/{$staff->id}/permissions", [
            'permissions' => ['users.approve_registration'],
        ])->assertRedirect();
        $this->assertTrue($staff->fresh()->can('users.approve_registration'));
        $this->actingAs($head)->put("/admin/users/{$staff->id}/permissions", [
            'permissions' => ['permissions.manage'],
        ])->assertForbidden();

        $requester = $this->user();
        $this->actingAs($requester)->put("/admin/users/{$staff->id}/permissions", [
            'permissions' => ['users.approve_registration'],
        ])->assertForbidden();

        $applicant = User::factory()->create(['status' => 'PENDING']);
        $this->actingAs($staff)->post("/admin/registrations/{$applicant->id}/review", [
            'action' => 'approve',
        ])->assertRedirect();
        $this->assertSame(AccountStatus::Approved, $applicant->fresh()->status);
    }

    public function test_core_roles_and_self_administration_are_protected(): void
    {
        $admin = $this->user('System Administrator');
        $this->actingAs($admin)->delete('/admin/roles/'.Role::findByName('System Administrator')->id)->assertForbidden();
        $this->actingAs($admin)->put("/admin/users/{$admin->id}/roles", ['roles' => []])->assertForbidden();
        $this->actingAs($admin)->put("/admin/users/{$admin->id}/status", [
            'status' => 'DEACTIVATED', 'reason' => 'test',
        ])->assertForbidden();
    }

    public function test_api_login_me_logout_and_revocation(): void
    {
        $user = $this->user('FMO Head');
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'password', 'device_name' => 'test phone',
        ])->assertOk();
        $token = $login->json('token');
        $this->assertNotEmpty($token);
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('email', $user->email)
            ->assertJsonMissing(['password' => $user->password])
            ->assertJsonFragment(['FMO Head']);
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'wrong', 'device_name' => 'test phone',
        ])->assertUnauthorized();

        $this->getJson('/api/v1/me')->assertUnauthorized();
        foreach ([AccountStatus::Pending, AccountStatus::Rejected, AccountStatus::Suspended, AccountStatus::Deactivated] as $status) {
            $user->forceFill(['status' => $status])->save();
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email, 'password' => 'password', 'device_name' => 'test phone',
            ])->assertForbidden()->assertJsonPath('status', $status->value);
        }
    }

    public function test_status_change_revokes_tokens_and_blocks_existing_sessions(): void
    {
        $admin = $this->user('System Administrator');
        $user = $this->user();
        $token = $user->createToken('test')->plainTextToken;
        $this->actingAs($admin)->put("/admin/users/{$user->id}/status", [
            'status' => 'SUSPENDED', 'reason' => 'Administrative hold',
        ])->assertRedirect();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/registration/status');
    }

    public function test_administration_pages_and_custom_roles_are_authorized(): void
    {
        $admin = $this->user('System Administrator');
        $requester = $this->user();
        $this->actingAs($requester)->get('/admin/users')->assertForbidden();
        $this->actingAs($requester)->get('/admin/roles')->assertForbidden();
        $this->actingAs($requester)->post('/admin/roles', [
            'name' => 'Reviewer', 'permissions' => ['users.approve_registration'],
        ])->assertForbidden();

        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->actingAs($admin)->get("/admin/users/{$requester->id}")->assertOk();
        $this->actingAs($admin)->get('/admin/roles')->assertOk();
        $this->actingAs($admin)->post('/admin/roles', [
            'name' => 'Reviewer', 'permissions' => ['users.approve_registration'],
        ])->assertRedirect();
        $role = Role::findByName('Reviewer');
        $this->actingAs($admin)->get("/admin/roles/{$role->id}")->assertOk();
        $this->actingAs($admin)->put("/admin/users/{$requester->id}/roles", [
            'roles' => ['Requester', 'Reviewer'],
        ])->assertRedirect();
        $this->assertTrue($requester->fresh()->can('users.approve_registration'));
        $this->actingAs($admin)->delete("/admin/roles/{$role->id}")->assertStatus(409);
        $this->actingAs($admin)->put("/admin/users/{$requester->id}/roles", [
            'roles' => ['Requester'],
        ])->assertRedirect();
        $this->actingAs($admin)->delete("/admin/roles/{$role->id}")->assertRedirect();
    }

    public function test_existing_api_token_is_denied_after_status_change_even_before_revocation(): void
    {
        $user = $this->user();
        $token = $user->createToken('test')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $user->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_repeated_login_replaces_the_same_device_token(): void
    {
        $user = $this->user();
        $credentials = ['email' => $user->email, 'password' => 'password', 'device_name' => 'shared device'];
        $first = $this->postJson('/api/v1/auth/login', $credentials)->assertOk()->json('token');
        $second = $this->postJson('/api/v1/auth/login', $credentials)->assertOk()->json('token');
        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->withToken($first)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($second)->getJson('/api/v1/me')->assertOk();
    }

    public function test_review_and_user_management_paths_reject_manipulated_requests(): void
    {
        $requester = $this->user();
        $applicant = User::factory()->create(['status' => 'PENDING']);
        $this->actingAs($requester)->get("/admin/registrations/{$applicant->id}")->assertForbidden();
        $this->actingAs($requester)->post("/admin/registrations/{$applicant->id}/review", [
            'action' => 'approve', 'status' => 'APPROVED',
        ])->assertForbidden();
        $this->actingAs($requester)->put("/admin/users/{$applicant->id}/status", [
            'status' => 'APPROVED', 'reason' => 'Manipulated request',
        ])->assertForbidden();
        $this->actingAs($requester)->put("/admin/users/{$applicant->id}/roles", [
            'roles' => ['System Administrator'],
        ])->assertForbidden();
        $this->assertSame(AccountStatus::Pending, $applicant->fresh()->status);
        $this->assertCount(0, $applicant->fresh()->roles);
    }

    public function test_authorization_seeder_is_idempotent(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $this->assertDatabaseCount('roles', count(config('authorization.core_roles')));
        $this->assertDatabaseCount('permissions', count(config('authorization.permissions')));
        $this->assertCount(
            count(config('authorization.permissions')),
            Role::findByName('System Administrator')->permissions,
        );
    }
}
