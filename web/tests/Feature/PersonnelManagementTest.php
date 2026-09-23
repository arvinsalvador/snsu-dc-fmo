<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\PersonnelStatus;
use App\Models\FmoPersonnel;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\SkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonnelManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AuthorizationSeeder::class, SkillSeeder::class]);
    }

    private function user(string $role = 'Requester', string $type = 'staff'): User
    {
        $user = User::factory()->create(['status' => AccountStatus::Approved->value, 'user_type' => $type]);
        $user->assignRole($role);

        return $user;
    }

    private function createPersonnel(User $user, array $data = []): FmoPersonnel
    {
        $personnel = FmoPersonnel::create([
            'user_id' => $user->id,
            'personnel_identifier' => 'P-'.$user->id,
            'designation' => 'Maintenance Worker',
            'personnel_status' => 'ACTIVE',
            ...$data,
        ]);
        $skill = Skill::first();
        $personnel->skills()->attach($skill->id, ['is_primary' => true]);

        return $personnel;
    }

    public function test_approved_user_can_manage_safe_own_profile_fields(): void
    {
        $user = $this->user('Requester', 'student');
        $this->actingAs($user)->get('/profile')->assertOk();
        $this->actingAs($user)->put('/profile', [
            'institutional_id' => 'S-100', 'contact_number' => '09000000000',
            'program' => 'BSIT', 'year_level' => '3',
            'status' => 'SUSPENDED', 'roles' => ['System Administrator'],
        ])->assertRedirect();
        $user->refresh();
        $this->assertSame(AccountStatus::Approved, $user->status);
        $this->assertTrue($user->hasRole('Requester'));
        $this->assertSame('BSIT', $user->requesterProfile->program);
        $this->assertSame('S-100', $user->requesterProfile->institutional_id);
        $this->actingAs($user)->put('/profile', ['institutional_id' => 'S-100'])->assertSessionHasErrors('program');
    }

    public function test_authorized_manager_can_create_update_and_filter_personnel_without_changing_roles(): void
    {
        $head = $this->user('FMO Head');
        $candidate = $this->user('Requester');
        $electrical = Skill::where('name', 'Electrical')->firstOrFail();
        $plumbing = Skill::where('name', 'Plumbing')->firstOrFail();
        $payload = [
            'user_id' => $candidate->id, 'personnel_identifier' => 'EMP-001',
            'designation' => 'Electrician', 'employment_type' => 'REGULAR',
            'start_date' => '2024-01-01', 'contact_number' => '09123456789',
            'personnel_status' => 'ACTIVE', 'notes' => 'Internal notes',
            'skill_ids' => [$electrical->id, $plumbing->id], 'primary_skill_id' => $electrical->id,
            'roles' => ['System Administrator'],
        ];
        $this->actingAs($head)->post('/personnel', $payload)->assertRedirect();
        $personnel = FmoPersonnel::where('user_id', $candidate->id)->firstOrFail();
        $this->assertSame('Electrician', $personnel->designation);
        $this->assertCount(2, $personnel->skills);
        $this->assertSame($electrical->id, $personnel->skills->firstWhere('pivot.is_primary', true)->id);
        $this->assertFalse($candidate->hasRole('System Administrator'));
        $this->actingAs($head)->get('/personnel?search='.$candidate->name.'&skill='.$electrical->id.'&designation=Electrician&employment_type=REGULAR')->assertOk()->assertSee($candidate->name);
        $this->actingAs($head)->put('/personnel/'.$personnel->id, [
            ...$payload, 'designation' => 'Senior Electrician', 'personnel_status' => 'UNAVAILABLE',
            'skill_ids' => [$plumbing->id], 'primary_skill_id' => $plumbing->id,
        ])->assertRedirect();
        $personnel->refresh();
        $this->assertSame('Senior Electrician', $personnel->designation);
        $this->assertFalse($personnel->isAssignable());
        $this->assertCount(1, $personnel->skills);
    }

    public function test_personnel_creation_is_restricted_and_requires_a_unique_approved_user(): void
    {
        $requester = $this->user();
        $candidate = $this->user();
        $payload = ['user_id' => $candidate->id, 'personnel_status' => 'ACTIVE'];
        $this->actingAs($requester)->post('/personnel', $payload)->assertForbidden();
        $head = $this->user('FMO Head');
        $pending = User::factory()->create(['status' => 'PENDING']);
        $this->actingAs($head)->post('/personnel', ['user_id' => $pending->id, 'personnel_status' => 'ACTIVE'])->assertStatus(422);
        $this->actingAs($head)->post('/personnel', $payload)->assertRedirect();
        $this->actingAs($head)->post('/personnel', $payload)->assertStatus(422);
    }

    public function test_status_archive_reactivate_and_delete_keep_user_account(): void
    {
        $head = $this->user('FMO Head');
        $staffUser = $this->user();
        $personnel = $this->createPersonnel($staffUser);
        $this->actingAs($head)->put('/personnel/'.$personnel->id.'/status', ['personnel_status' => 'INACTIVE'])->assertRedirect();
        $personnel->refresh();
        $this->assertSame(PersonnelStatus::Inactive, $personnel->personnel_status);
        $this->assertNotNull($personnel->archived_at);
        $this->assertFalse($personnel->isAssignable());
        $this->actingAs($head)->put('/personnel/'.$personnel->id.'/status', ['personnel_status' => 'ACTIVE'])->assertRedirect();
        $this->assertTrue($personnel->fresh()->isAssignable());
        $staffUser->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->assertFalse($personnel->fresh()->isAssignable());
        $this->actingAs($head)->delete('/personnel/'.$personnel->id)->assertRedirect();
        $this->assertDatabaseMissing('fmo_personnel', ['id' => $personnel->id]);
        $this->assertDatabaseHas('users', ['id' => $staffUser->id]);
    }

    public function test_skills_are_managed_safely_and_primary_must_be_assigned(): void
    {
        $head = $this->user('FMO Head');
        $this->actingAs($head)->post('/skills', ['name' => 'Roofing', 'description' => 'Roof repairs', 'is_active' => true])->assertRedirect();
        $this->actingAs($head)->post('/skills', ['name' => 'Roofing', 'is_active' => true])->assertSessionHasErrors('name');
        $skill = Skill::where('name', 'Roofing')->firstOrFail();
        $this->actingAs($head)->put('/skills/'.$skill->id, ['name' => 'Roofing', 'description' => 'Updated', 'is_active' => false])->assertRedirect();
        $this->assertFalse($skill->fresh()->is_active);
        $personnel = $this->createPersonnel($this->user());
        $this->actingAs($head)->delete('/skills/'.Skill::first()->id)->assertStatus(409);
        $this->actingAs($head)->put('/personnel/'.$personnel->id, [
            'personnel_status' => 'ACTIVE', 'skill_ids' => [], 'primary_skill_id' => Skill::first()->id,
        ])->assertStatus(422);
    }

    public function test_delegated_personnel_permissions_and_api_resources_are_secure(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $candidate = $this->user();
        $this->actingAs($head)->put('/admin/users/'.$staff->id.'/permissions', [
            'permissions' => ['personnel.view', 'personnel.create', 'skills.view'],
        ])->assertRedirect();
        $this->actingAs($staff->fresh())->post('/personnel', ['user_id' => $candidate->id, 'personnel_status' => 'ACTIVE'])->assertRedirect();
        $personnel = FmoPersonnel::where('user_id', $candidate->id)->firstOrFail();
        $token = $staff->fresh()->createToken('test')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/personnel')->assertOk()->assertJsonMissing(['email' => $candidate->email, 'notes' => $personnel->notes]);
        $this->withToken($token)->getJson('/api/v1/personnel/'.$personnel->id)->assertOk();
        $this->withToken($token)->getJson('/api/v1/skills')->assertOk();
        $requester = $this->user();
        $this->actingAs($requester)->get('/personnel')->assertForbidden();
    }

    public function test_profile_api_allows_only_the_authenticated_user_profile_fields(): void
    {
        $user = $this->user('Requester', 'staff');
        $token = $user->createToken('profile')->plainTextToken;
        $this->withToken($token)->patchJson('/api/v1/me/profile', [
            'institutional_id' => 'EMP-42', 'organizational_office' => 'Registrar',
            'contact_number' => '09170000000', 'status' => 'SUSPENDED',
        ])->assertOk()->assertJsonPath('data.institutional_id', 'EMP-42');
        $user->refresh();
        $this->assertSame(AccountStatus::Approved, $user->status);
        $this->withToken($token)->getJson('/api/v1/me/profile')->assertOk()
            ->assertJsonPath('data.organizational_office', 'Registrar');
    }
}
