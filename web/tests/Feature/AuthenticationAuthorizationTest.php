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

        foreach ([AccountStatus::Pending, AccountStatus::NeedsCorrection] as $status) {
            $user->forceFill(['status' => $status])->save();
            $this->actingAs($user)->get('/dashboard')->assertRedirect('/registration/status');
        }
        foreach ([AccountStatus::Rejected, AccountStatus::Suspended, AccountStatus::Deactivated] as $status) {
            $user->forceFill(['status' => $status])->save();
            $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
            $this->assertGuest();
        }
    }

    public function test_rejected_suspended_and_deactivated_accounts_cannot_sign_in_with_valid_credentials(): void
    {
        foreach ([AccountStatus::Rejected, AccountStatus::Suspended, AccountStatus::Deactivated] as $status) {
            $user = $this->user('Requester', $status);

            $this->post('/login', ['email' => $user->email, 'password' => 'password'])
                ->assertSessionHasErrors(['email' => $status->signInMessage()]);
            $this->assertGuest();
            $this->get('/dashboard')->assertRedirect('/login');
            $this->get('/registration/status')->assertRedirect('/login');
            $this->get('/profile')->assertRedirect('/login');
        }
    }

    public function test_a_wrong_password_never_reveals_that_an_account_is_suspended(): void
    {
        $user = $this->user('Requester', AccountStatus::Suspended);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => 'The provided credentials are incorrect.']);
        $this->assertGuest();
    }

    public function test_pending_and_needs_correction_accounts_can_still_sign_in_to_see_their_registration_status(): void
    {
        foreach ([AccountStatus::Pending, AccountStatus::NeedsCorrection] as $status) {
            $user = $this->user('Requester', $status);

            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/registration/status');
            $this->assertAuthenticatedAs($user);
            $this->get('/dashboard')->assertRedirect('/registration/status');
            $this->post('/logout');
        }
    }

    public function test_an_open_session_ends_on_the_next_request_after_suspension_and_works_again_when_reinstated(): void
    {
        $user = $this->user();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();

        $user->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/work-orders/my-tasks')->assertRedirect('/login');

        $user->forceFill(['status' => AccountStatus::Approved])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
    }

    public function test_api_access_is_refused_for_rejected_suspended_and_deactivated_accounts(): void
    {
        foreach ([AccountStatus::Rejected, AccountStatus::Suspended, AccountStatus::Deactivated] as $status) {
            $user = $this->user('Requester', $status);
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'phone'])->assertForbidden()->assertJsonPath('status', $status->value);
            $this->assertSame(0, $user->tokens()->count());

            $active = $this->user();
            $token = $active->createToken('phone')->plainTextToken;
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/me')->assertOk();
            $active->forceFill(['status' => $status])->save();
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('message', $status->signInMessage());
            $this->assertSame(0, $active->tokens()->count(), 'Tokens are deleted as soon as the refusal is seen.');
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
        $this->actingAs($applicant)->get('/registration/status')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => $applicant->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertStringContainsString('Reason: Not eligible', session('errors')->first('email'));
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
        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
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

    public function test_a_rejected_registration_can_be_reconsidered_and_approved_from_the_registration_review(): void
    {
        $approver = $this->user('Campus Director');
        $applicant = $this->user('Requester', AccountStatus::Pending);
        $applicant->syncRoles([]);
        $url = route('registrations.review', $applicant);

        $this->actingAs($approver)->post($url, ['action' => 'reject', 'reason' => 'Incomplete details'])->assertRedirect();
        $this->actingAs($approver)->get(route('registrations.show', $applicant))->assertOk()->assertSee('Reconsider Registration');
        $this->actingAs($approver)->post($url, ['action' => 'request_correction', 'reason' => 'x'])->assertStatus(409);
        $this->actingAs($approver)->post($url, ['action' => 'approve'])->assertRedirect()->assertSessionHas('success');

        $applicant->refresh();
        $this->assertSame(AccountStatus::Approved, $applicant->status);
        $this->assertSame($approver->id, $applicant->approved_by);
        $this->assertTrue($applicant->hasRole('Requester'));
        $this->assertDatabaseHas('registration_reviews', ['user_id' => $applicant->id, 'action' => 'approved', 'previous_status' => 'REJECTED', 'new_status' => 'APPROVED']);
        $this->post('/login', ['email' => $applicant->email, 'password' => 'password'])->assertRedirect('/dashboard');
    }

    public function test_a_rejected_account_can_be_approved_from_user_management_but_not_suspended(): void
    {
        $admin = $this->user('System Administrator');
        $applicant = $this->user('Requester', AccountStatus::Rejected);
        $applicant->syncRoles([]);

        $this->actingAs($admin)->get(route('users.show', $applicant))->assertOk()->assertSee('This registration was rejected');
        $this->actingAs($admin)->put(route('users.status', $applicant), ['status' => 'SUSPENDED', 'reason' => 'x'])->assertSessionHasErrors('status');
        $this->assertSame(AccountStatus::Rejected, $applicant->fresh()->status);

        $this->actingAs($admin)->put(route('users.status', $applicant), ['status' => 'APPROVED', 'reason' => 'Reconsidered after review'])->assertRedirect()->assertSessionHasNoErrors();

        $applicant->refresh();
        $this->assertSame(AccountStatus::Approved, $applicant->status);
        $this->assertSame($admin->id, $applicant->approved_by);
        $this->assertNotNull($applicant->approved_at);
        $this->assertTrue($applicant->hasRole('Requester'));
        $this->assertDatabaseHas('registration_reviews', ['user_id' => $applicant->id, 'action' => 'status_changed', 'previous_status' => 'REJECTED', 'new_status' => 'APPROVED']);
    }
}
