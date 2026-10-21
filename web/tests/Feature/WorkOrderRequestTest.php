<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\BuildingLocation;
use App\Models\Campus;
use App\Models\Floor;
use App\Models\FmoPersonnel;
use App\Models\Skill;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Services\WorkOrderAssignmentService;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\SkillSeeder;
use Database\Seeders\WorkOrderCategorySeeder;
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

    /** @param array<int, array{0: Skill, 1: bool}> $skills */
    private function personnel(string $name, array $skills = []): FmoPersonnel
    {
        $user = User::factory()->create(['name' => $name, 'status' => 'APPROVED']);
        $personnel = FmoPersonnel::create(['user_id' => $user->id, 'personnel_status' => 'ACTIVE']);
        foreach ($skills as [$skill, $primary]) {
            $personnel->skills()->attach($skill, ['is_primary' => $primary]);
        }

        return $personnel->load('user', 'skills');
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
        $this->assertCount(0, $order->preferredPersonnel);
        $this->assertMatchesRegularExpression('/^WO-\d{4}-000001$/', $order->work_order_number);
    }

    public function test_preference_lookup_filters_by_mapped_assigned_skills_and_returns_safe_context(): void
    {
        $requester = $this->user();
        $context = $this->context();
        $electrical = Skill::create(['name' => 'Electrical', 'is_active' => true]);
        $plumbing = Skill::create(['name' => 'Plumbing', 'is_active' => true]);
        $context['category']->relevantSkills()->attach($electrical);
        $electrician = $this->personnel('Electrical Staff', [[$electrical, true]]);
        $plumber = $this->personnel('Plumbing Staff', [[$plumbing, true]]);
        $inactive = $this->personnel('Inactive Electrical Staff', [[$electrical, true]]);
        $inactive->update(['personnel_status' => 'INACTIVE']);
        $archived = $this->personnel('Archived Electrical Staff', [[$electrical, true]]);
        $archived->update(['archived_at' => now()]);
        $unapproved = $this->personnel('Unapproved Electrical Staff', [[$electrical, true]]);
        $unapproved->user->forceFill(['status' => 'PENDING'])->save();

        $this->actingAs($requester)->get('/work-orders/create')
            ->assertOk()
            ->assertSee('Select a Work Type first')
            ->assertSee('No skill mapping configured')
            ->assertSee('No matching FMO personnel available')
            ->assertSee('Unable to load personnel')
            ->assertSee('Search personnel or skill...')
            ->assertSee('Loading matching FMO personnel...')
            ->assertSee('No matching FMO personnel are currently available.')
            ->assertSee('No personnel skill mapping is configured for this Work Type.')
            ->assertSee('You may continue without selecting a preference.')
            ->assertSee('You may select up to 3 preferred personnel.');

        $this->getJson(route('work-order-categories.eligible-preferred-personnel', $context['category']))
            ->assertOk()
            ->assertJsonPath('state', 'ready')
            ->assertJsonPath('mapping_configured', true)
            ->assertJsonPath('max_selections', 3)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'id' => $electrician->id,
                'name' => 'Electrical Staff',
                'primary_skill' => 'Electrical',
                'primary_matches' => true,
                'matching_skills' => ['Electrical'],
            ])
            ->assertJsonMissing(['id' => $plumber->id])
            ->assertJsonMissing(['id' => $inactive->id])
            ->assertJsonMissing(['id' => $archived->id])
            ->assertJsonMissing(['id' => $unapproved->id])
            ->assertJsonMissing(['contact_number' => null]);
    }

    public function test_multiple_mapped_skills_and_non_primary_skills_are_used_for_matching(): void
    {
        $requester = $this->user();
        $context = $this->context();
        $airConditioning = Skill::create(['name' => 'Air Conditioning', 'is_active' => true]);
        $electrical = Skill::create(['name' => 'Electrical', 'is_active' => true]);
        $plumbing = Skill::create(['name' => 'Plumbing', 'is_active' => true]);
        $carpentry = Skill::create(['name' => 'Carpentry', 'is_active' => true]);
        $context['category']->relevantSkills()->sync([$airConditioning->id, $electrical->id]);
        $airStaff = $this->personnel('Air Staff', [[$airConditioning, true]]);
        $electricalStaff = $this->personnel('Electrical Staff', [[$carpentry, true], [$electrical, false]]);
        $crossSkilledStaff = $this->personnel('Cross-skilled Staff', [[$electrical, true], [$airConditioning, false]]);
        $plumbingStaff = $this->personnel('Plumbing Staff', [[$plumbing, true]]);

        $response = $this->actingAs($requester)
            ->getJson(route('work-order-categories.eligible-preferred-personnel', $context['category']));

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment(['id' => $airStaff->id, 'matching_skills' => ['Air Conditioning']])
            ->assertJsonFragment(['id' => $electricalStaff->id, 'primary_skill' => 'Carpentry', 'primary_matches' => false, 'matching_skills' => ['Electrical']])
            ->assertJsonFragment(['id' => $crossSkilledStaff->id, 'primary_matches' => true, 'matching_skills' => ['Electrical', 'Air Conditioning']])
            ->assertJsonMissing(['id' => $plumbingStaff->id]);

        $this->assertSame(1, collect($response->json('data'))->where('id', $crossSkilledStaff->id)->count());
    }

    public function test_multiple_preferences_are_advisory_and_assignment_remains_independent(): void
    {
        $requester = $this->user();
        $head = $this->user('FMO Head');
        $context = $this->context();
        $electrical = Skill::create(['name' => 'Electrical', 'is_active' => true]);
        $general = Skill::create(['name' => 'General Maintenance', 'is_active' => true]);
        $context['category']->relevantSkills()->attach($electrical);
        $preferredA = $this->personnel('Preferred Electrician A', [[$electrical, true], [$general, false]]);
        $preferredB = $this->personnel('Preferred Electrician B', [[$electrical, true]]);
        $assigned = $this->personnel('Assigned Electrician C', [[$electrical, true]]);
        foreach ([$preferredA, $preferredB, $assigned] as $personnel) {
            $personnel->user->assignRole('FMO Staff');
        }

        $this->actingAs($requester)->post('/work-orders', $this->payload($context, [
            'preferred_fmo_personnel_ids' => [$preferredA->id, $preferredB->id],
        ]))->assertRedirect();

        $order = WorkOrder::firstOrFail();
        $this->assertEqualsCanonicalizing([$preferredA->id, $preferredB->id], $order->preferredPersonnel()->pluck('fmo_personnel.id')->all());
        $this->assertDatabaseCount('work_order_assignments', 0);
        $this->assertSame(0, $preferredA->user->notifications()->count());
        $this->assertSame(0, $preferredB->user->notifications()->count());

        $this->actingAs($requester)->get('/work-orders/'.$order->id)
            ->assertOk()
            ->assertSee('Requester Personnel Preferences')
            ->assertSee('Preferred Electrician A')
            ->assertSee('Preferred Electrician B')
            ->assertSee('Primary: Electrical • General Maintenance')
            ->assertSee('Advisory only');
        foreach ([$preferredA, $preferredB] as $preferred) {
            $this->actingAs($preferred->user)->get('/work-orders/my-tasks')->assertOk()->assertDontSee($order->work_order_number);
            $this->get('/work-orders/'.$order->id)->assertForbidden();
        }

        $order->update(['status' => WorkOrderStatus::Approved]);
        app(WorkOrderAssignmentService::class)->add($order->fresh(), $head, [$assigned->id]);
        $this->actingAs($head)->get('/work-orders/'.$order->id)
            ->assertOk()
            ->assertSee('Preferred Electrician A')
            ->assertSee('Preferred Electrician B')
            ->assertSee('Assigned Electrician C')
            ->assertSee('Requester preference');
    }

    public function test_preference_validation_enforces_mapping_limit_and_preserves_old_input(): void
    {
        $requester = $this->user();
        $context = $this->context();
        $electrical = Skill::create(['name' => 'Electrical', 'is_active' => true]);
        $plumbing = Skill::create(['name' => 'Plumbing', 'is_active' => true]);
        $context['category']->relevantSkills()->attach($electrical);
        $matching = collect(range(1, 4))->map(fn (int $number) => $this->personnel('Electrician '.$number, [[$electrical, true]]));
        $mismatch = $this->personnel('Plumber', [[$plumbing, true]]);

        $this->actingAs($requester)
            ->from('/work-orders/create')
            ->post('/work-orders', $this->payload($context, [
                'subject' => '',
                'preferred_fmo_personnel_ids' => $matching->take(2)->pluck('id')->all(),
            ]))
            ->assertRedirect('/work-orders/create')
            ->assertSessionHasErrors('subject')
            ->assertSessionHasInput('preferred_fmo_personnel_ids', $matching->take(2)->pluck('id')->all());

        $this->actingAs($requester)->post('/work-orders', $this->payload($context, [
            'preferred_fmo_personnel_ids' => $matching->pluck('id')->all(),
        ]))->assertSessionHasErrors(['preferred_fmo_personnel_ids' => 'You may select up to 3 preferred personnel.']);
        $this->assertDatabaseCount('work_orders', 0);

        $this->actingAs($requester)->post('/work-orders', $this->payload($context, [
            'preferred_fmo_personnel_ids' => [$mismatch->id],
        ]))->assertSessionHasErrors(['preferred_fmo_personnel_ids' => 'One or more selected preferred personnel do not match the selected Work Type.']);
        $this->assertDatabaseCount('work_order_personnel_preferences', 0);
    }

    public function test_unmapped_category_has_clear_empty_state_and_allows_zero_preferences(): void
    {
        $requester = $this->user();
        $context = $this->context();

        $this->actingAs($requester)
            ->getJson(route('work-order-categories.eligible-preferred-personnel', $context['category']))
            ->assertOk()
            ->assertJsonPath('state', 'unmapped')
            ->assertJsonPath('mapping_configured', false)
            ->assertJsonCount(0, 'data');
        $unusedSkill = Skill::create(['name' => 'No Staff Skill', 'is_active' => true]);
        $context['category']->relevantSkills()->attach($unusedSkill);
        $this->getJson(route('work-order-categories.eligible-preferred-personnel', $context['category']))
            ->assertOk()
            ->assertJsonPath('state', 'no_matches')
            ->assertJsonPath('mapping_configured', true)
            ->assertJsonCount(0, 'data');
        $this->post('/work-orders', $this->payload($context))->assertRedirect();
        $this->assertCount(0, WorkOrder::firstOrFail()->preferredPersonnel);
    }

    public function test_standard_seed_data_maps_existing_work_types_and_all_assigned_skills(): void
    {
        $this->seed([SkillSeeder::class, WorkOrderCategorySeeder::class]);
        $requester = $this->user();
        $carpentry = Skill::where('name', 'Carpentry / Woodworking')->firstOrFail();
        $masonry = Skill::where('name', 'Masonry')->firstOrFail();
        $airConditioning = Skill::where('name', 'Air Conditioning')->firstOrFail();
        $marjun = $this->personnel('Marjun Portrias', [
            [$carpentry, true],
            [$masonry, false],
            [$airConditioning, false],
        ]);
        $masonryCategory = WorkOrderCategory::where('name', 'Masonry')->firstOrFail();

        $this->assertSame(['Masonry'], $masonryCategory->relevantSkills->pluck('name')->all());
        $this->actingAs($requester)
            ->getJson(route('work-order-categories.eligible-preferred-personnel', $masonryCategory))
            ->assertOk()
            ->assertJsonPath('state', 'ready')
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'id' => $marjun->id,
                'primary_skill' => 'Carpentry / Woodworking',
                'matching_skills' => ['Masonry'],
            ]);
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
        $this->actingAs($first)->get('/my-work-orders')->assertSee($one->work_order_number)->assertDontSee($two->work_order_number);
        $this->actingAs($first)->get('/work-orders')->assertForbidden();
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
        $painting = Skill::create(['name' => 'Painting', 'is_active' => true]);
        $general = Skill::create(['name' => 'General Maintenance', 'is_active' => true]);
        $this->actingAs($requester)->post('/work-order-categories', ['name' => 'Unsafe', 'is_active' => true])->assertForbidden();
        $this->actingAs($head)->post('/work-order-categories', ['name' => 'Painting Repair', 'is_active' => true, 'display_order' => 1, 'relevant_skill_ids' => [$painting->id]])->assertRedirect();
        $category = WorkOrderCategory::where('name', 'Painting Repair')->firstOrFail();
        $this->assertDatabaseHas('work_order_category_skill', ['work_order_category_id' => $category->id, 'skill_id' => $painting->id]);
        $this->put('/work-order-categories/'.$category->id, ['name' => 'Painting Repair', 'is_active' => true, 'display_order' => 1, 'relevant_skill_ids_present' => true, 'relevant_skill_ids' => [$painting->id, $general->id]])->assertRedirect();
        $this->assertEqualsCanonicalizing([$painting->id, $general->id], $category->relevantSkills()->pluck('skills.id')->all());
        WorkOrder::create([...$this->payload($context), 'requester_id' => $requester->id, 'work_order_number' => 'WO-2026-000001', 'status' => WorkOrderStatus::Submitted, 'submitted_at' => now()]);
        $this->actingAs($head)->delete('/work-order-categories/'.$context['category']->id)->assertStatus(409);
    }

    public function test_api_creation_and_scope_are_enforced(): void
    {
        $requester = $this->user();
        $other = $this->user();
        $context = $this->context();
        $electrical = Skill::create(['name' => 'Electrical', 'is_active' => true]);
        $context['category']->relevantSkills()->attach($electrical);
        $preferredA = $this->personnel('API Electrician A', [[$electrical, true]]);
        $preferredB = $this->personnel('API Electrician B', [[$electrical, true]]);
        WorkOrder::create([...$this->payload($context, ['subject' => 'Other requester request']), 'requester_id' => $other->id, 'work_order_number' => 'WO-2026-000099', 'status' => WorkOrderStatus::Submitted, 'submitted_at' => now()]);
        $token = $requester->createToken('test')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/work-orders', $this->payload($context, [
            'preferred_fmo_personnel_ids' => [$preferredA->id, $preferredB->id],
        ]))->assertCreated()
            ->assertJsonPath('data.status', 'SUBMITTED')
            ->assertJsonCount(2, 'data.preferred_personnel')
            ->assertJsonFragment(['id' => $preferredA->id, 'name' => 'API Electrician A', 'primary_skill' => 'Electrical', 'skills' => ['Electrical']])
            ->assertJsonFragment(['id' => $preferredB->id, 'name' => 'API Electrician B', 'primary_skill' => 'Electrical', 'skills' => ['Electrical']]);
        $this->withToken($token)->getJson('/api/v1/work-orders')->assertJsonCount(1, 'data');
    }

    public function test_floor_and_area_are_optional_but_must_match_the_selected_building(): void
    {
        $requester = $this->user();
        $context = $this->context();
        $floor = Floor::create(['building_id' => $context['building']->id, 'name' => 'Ground Floor', 'is_active' => true]);
        $area = BuildingLocation::create(['building_id' => $context['building']->id, 'floor_id' => $floor->id, 'type' => 'LABORATORY', 'name' => 'Computer Laboratory', 'is_active' => true]);
        $buildingLevel = BuildingLocation::create(['building_id' => $context['building']->id, 'type' => 'HALLWAY', 'name' => 'Main Hallway', 'is_active' => true]);
        $otherCampus = Campus::create(['code' => 'OT', 'name' => 'Other', 'is_active' => true]);
        $otherBuilding = Building::create(['campus_id' => $otherCampus->id, 'name' => 'Other Building', 'is_active' => true]);
        $otherFloor = Floor::create(['building_id' => $otherBuilding->id, 'name' => 'Other Floor', 'is_active' => true]);
        $otherArea = BuildingLocation::create(['building_id' => $otherBuilding->id, 'floor_id' => $otherFloor->id, 'type' => 'ROOM', 'name' => 'Other Room', 'is_active' => true]);

        $this->actingAs($requester)->post('/work-orders', $this->payload($context, ['subject' => 'Building wide']))->assertRedirect();
        $this->actingAs($requester)->post('/work-orders', $this->payload($context, ['subject' => 'Floor wide', 'floor_id' => $floor->id]))->assertRedirect();
        $this->actingAs($requester)->post('/work-orders', $this->payload($context, ['subject' => 'Specific room', 'floor_id' => $floor->id, 'building_location_id' => $area->id]))->assertRedirect();
        $this->actingAs($requester)->post('/work-orders', $this->payload($context, ['subject' => 'Floor with hallway', 'floor_id' => $floor->id, 'building_location_id' => $buildingLevel->id]))->assertRedirect();
        $this->actingAs($requester)->post('/work-orders', $this->payload($context, ['floor_id' => $otherFloor->id]))->assertSessionHasErrors('floor_id');
        $this->actingAs($requester)->post('/work-orders', $this->payload($context, ['building_location_id' => $otherArea->id]))->assertSessionHasErrors('building_location_id');
        $floor->update(['is_active' => false]);
        $this->actingAs($requester)->post('/work-orders', $this->payload($context, ['floor_id' => $floor->id]))->assertSessionHasErrors('floor_id');
    }
}
