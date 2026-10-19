<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\PersonnelStatus;
use App\Models\FmoPersonnel;
use App\Models\Skill;
use App\Models\User;
use App\Services\PersonnelSkillService;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\SkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
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

    public function test_authorized_manager_can_manage_personnel_and_provision_only_staff_role(): void
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
        $this->assertTrue($candidate->fresh()->hasAllRoles(['Requester', 'FMO Staff']));
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
        $this->actingAs($head)->from('/personnel/create')->post('/personnel', ['user_id' => $pending->id, 'personnel_status' => 'ACTIVE'])
            ->assertRedirect('/personnel/create')->assertSessionHasErrors('user_id');
        $this->actingAs($head)->post('/personnel', $payload)->assertRedirect();
        $this->actingAs($head)->from('/personnel/create')->post('/personnel', $payload)
            ->assertRedirect('/personnel/create')->assertSessionHasErrors('user_id');
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
        $this->assertTrue($staffUser->fresh()->hasRole('FMO Staff'));
        $staffUser->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->assertFalse($personnel->fresh()->isAssignable());
        $this->actingAs($head)->delete('/personnel/'.$personnel->id)->assertRedirect();
        $this->assertDatabaseMissing('fmo_personnel', ['id' => $personnel->id]);
        $this->assertDatabaseHas('users', ['id' => $staffUser->id]);
    }

    public function test_skills_are_managed_safely_and_primary_is_automatically_assigned(): void
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
        ])->assertRedirect();
        $this->assertCount(1, $personnel->fresh()->skills);
        $this->assertTrue((bool) $personnel->fresh()->skills->first()->pivot->is_primary);
    }

    public function test_three_personnel_can_be_created_sequentially_with_different_skill_combinations(): void
    {
        $head = $this->user('FMO Head');
        $electrical = Skill::where('name', 'Electrical')->firstOrFail();
        $general = Skill::where('name', 'General Maintenance')->firstOrFail();
        $carpentry = Skill::where('name', 'Carpentry / Woodworking')->firstOrFail();
        $masonry = Skill::where('name', 'Masonry')->firstOrFail();
        $painting = Skill::where('name', 'Painting')->firstOrFail();
        $airConditioning = Skill::where('name', 'Air Conditioning')->firstOrFail();
        $staff = [$this->user(), $this->user(), $this->user()];
        $combinations = [
            [[$electrical->id, $general->id], $electrical->id],
            [[$carpentry->id, $masonry->id, $painting->id], $carpentry->id],
            [[$airConditioning->id], $airConditioning->id],
        ];

        foreach ($staff as $index => $candidate) {
            $this->actingAs($head)->post('/personnel', [
                'user_id' => $candidate->id, 'personnel_identifier' => 'SEQ-00'.($index + 1),
                'personnel_status' => 'ACTIVE', 'skill_ids' => $combinations[$index][0],
                'primary_skill_id' => $combinations[$index][1],
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        foreach ($staff as $index => $candidate) {
            $personnel = FmoPersonnel::where('user_id', $candidate->id)->with('skills')->firstOrFail();
            $this->assertEqualsCanonicalizing($combinations[$index][0], $personnel->skills->modelKeys());
            $this->assertSame($combinations[$index][1], $personnel->skills->firstWhere('pivot.is_primary', true)?->id);
            $this->assertSame(1, $personnel->skills->where('pivot.is_primary', true)->count());
        }
    }

    public function test_raw_post_normalizes_missing_primary_and_duplicate_skill_ids(): void
    {
        $head = $this->user('FMO Head');
        $candidate = $this->user();
        $carpentry = Skill::where('name', 'Carpentry / Woodworking')->firstOrFail();
        $masonry = Skill::where('name', 'Masonry')->firstOrFail();

        $this->actingAs($head)->post('/personnel', [
            'user_id' => $candidate->id, 'personnel_status' => 'ACTIVE',
            'skill_ids' => [$masonry->id, $masonry->id], 'primary_skill_id' => $carpentry->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $personnel = FmoPersonnel::where('user_id', $candidate->id)->with('skills')->firstOrFail();
        $this->assertEqualsCanonicalizing([$masonry->id, $carpentry->id], $personnel->skills->modelKeys());
        $this->assertSame($carpentry->id, $personnel->skills->firstWhere('pivot.is_primary', true)?->id);
        $this->assertDatabaseCount('fmo_personnel_skill', 2);
    }

    public function test_invalid_primary_returns_form_errors_without_creating_personnel(): void
    {
        $head = $this->user('FMO Head');
        $candidate = $this->user();
        $inactive = Skill::create(['name' => 'Inactive test skill', 'is_active' => false]);

        $this->actingAs($head)->from('/personnel/create')->post('/personnel', [
            'user_id' => $candidate->id, 'personnel_status' => 'ACTIVE',
            'skill_ids' => [], 'primary_skill_id' => 999999,
        ])->assertRedirect('/personnel/create')->assertSessionHasErrors('primary_skill_id');

        $this->assertDatabaseMissing('fmo_personnel', ['user_id' => $candidate->id]);

        $this->actingAs($head)->from('/personnel/create')->post('/personnel', [
            'user_id' => $candidate->id, 'personnel_status' => 'ACTIVE',
            'skill_ids' => [], 'primary_skill_id' => $inactive->id,
        ])->assertRedirect('/personnel/create')->assertSessionHasErrors('primary_skill_id');
        $this->assertDatabaseMissing('fmo_personnel', ['user_id' => $candidate->id]);
    }

    public function test_edit_normalizes_a_new_primary_and_allows_no_primary(): void
    {
        $head = $this->user('FMO Head');
        $personnel = $this->createPersonnel($this->user());
        $carpentry = Skill::where('name', 'Carpentry / Woodworking')->firstOrFail();
        $masonry = Skill::where('name', 'Masonry')->firstOrFail();

        $this->actingAs($head)->put('/personnel/'.$personnel->id, [
            'personnel_status' => 'ACTIVE', 'skill_ids' => [$masonry->id], 'primary_skill_id' => $carpentry->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $personnel->refresh()->load('skills');
        $this->assertEqualsCanonicalizing([$masonry->id, $carpentry->id], $personnel->skills->modelKeys());
        $this->assertSame($carpentry->id, $personnel->skills->firstWhere('pivot.is_primary', true)?->id);

        $this->actingAs($head)->put('/personnel/'.$personnel->id, [
            'personnel_status' => 'ACTIVE', 'skill_ids' => [$masonry->id], 'primary_skill_id' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($personnel->fresh()->skills->firstWhere('pivot.is_primary', true));
    }

    public function test_personnel_creation_rolls_back_when_skill_sync_fails(): void
    {
        $head = $this->user('FMO Head');
        $candidate = $this->user();
        $skill = Skill::firstOrFail();
        $this->mock(PersonnelSkillService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')->once()->andThrow(new RuntimeException('Forced skill sync failure.'));
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($head)->post('/personnel', [
                'user_id' => $candidate->id, 'personnel_status' => 'ACTIVE',
                'skill_ids' => [$skill->id], 'primary_skill_id' => $skill->id,
            ]);
            $this->fail('Expected the forced skill synchronization failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced skill sync failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('fmo_personnel', ['user_id' => $candidate->id]);
        $this->assertFalse($candidate->fresh()->hasRole('FMO Staff'));
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
