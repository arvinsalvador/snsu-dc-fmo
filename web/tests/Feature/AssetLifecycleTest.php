<?php

namespace Tests\Feature;

use App\Models\AssetActionRequest;
use App\Models\AssetType;
use App\Models\Building;
use App\Models\BuildingLocation;
use App\Models\Campus;
use App\Models\Floor;
use App\Models\FmoPersonnel;
use App\Models\InspectableAsset;
use App\Models\InspectionItem;
use App\Models\RoutineTaskTemplate;
use App\Models\RoutineTaskTemplateAssignment;
use App\Models\User;
use App\Services\RoutineOccurrenceService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\RoutineOperationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $staff;

    private User $director;

    private User $admin;

    private Campus $campus;

    private Building $building;

    private Floor $floor;

    private BuildingLocation $area;

    private AssetType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AuthorizationSeeder::class, RoutineOperationsSeeder::class]);
        $this->head = $this->user('FMO Head');
        $this->staff = $this->user('FMO Staff');
        $this->director = $this->user('Campus Director');
        $this->admin = $this->user('System Administrator');
        FmoPersonnel::create(['user_id' => $this->staff->id, 'personnel_identifier' => 'FMO-ASSET-01', 'designation' => 'Facilities Staff', 'personnel_status' => 'ACTIVE']);
        $this->campus = Campus::create(['code' => 'DC', 'name' => 'Del Carmen Campus', 'is_active' => true]);
        $this->building = Building::create(['campus_id' => $this->campus->id, 'code' => 'LRC', 'name' => 'LRC Building', 'is_active' => true]);
        $this->floor = Floor::create(['building_id' => $this->building->id, 'name' => 'Ground Floor', 'display_order' => 1, 'is_active' => true]);
        $this->area = BuildingLocation::create(['building_id' => $this->building->id, 'floor_id' => $this->floor->id, 'type' => 'OFFICE', 'code' => 'REG', 'name' => 'Registrar Entrance', 'is_active' => true]);
        $this->type = AssetType::where('code', 'FE')->firstOrFail();
    }

    public function test_asset_form_uses_dependent_locations_and_rejects_invalid_hierarchy(): void
    {
        $this->actingAs($this->head)->get('/assets/create')->assertOk()
            ->assertSee('data-work-order-location', false)->assertSee('Select a campus first')->assertSee('disabled', false);
        $otherCampus = Campus::create(['code' => 'MC', 'name' => 'Main Campus', 'is_active' => true]);
        $otherBuilding = Building::create(['campus_id' => $otherCampus->id, 'code' => 'ADM', 'name' => 'Administration', 'is_active' => true]);
        $this->actingAs($this->head)->post('/assets', $this->assetPayload(['building_id' => $otherBuilding->id]))
            ->assertSessionHasErrors('building_id');
    }

    public function test_asset_photos_qr_and_authenticated_scan_are_private_and_stable(): void
    {
        Storage::fake('local');
        $this->actingAs($this->head)->post('/assets', $this->assetPayload([
            'asset_photos' => [UploadedFile::fake()->image('asset.jpg', 2200, 1400)],
            'placement_photos' => [UploadedFile::fake()->image('placement.jpg', 1800, 1200)],
        ]))->assertRedirect();
        $asset = InspectableAsset::where('asset_code', 'FE-DC-001')->firstOrFail();
        $this->assertNotEmpty($asset->qr_token);
        $this->assertSame(48, strlen($asset->qr_token));
        $this->assertCount(2, $asset->attachments);
        foreach ($asset->attachments as $photo) {
            Storage::disk('local')->assertExists($photo->stored_path);
            Storage::disk('local')->assertExists($photo->thumbnail_path);
            $this->actingAs($this->staff)->get(route('routine-evidence.show', [$photo, 'thumbnail']))->assertOk();
        }
        $this->actingAs($this->staff)->get(route('assets.scan', $asset->qr_token))->assertOk()->assertSee('FE-DC-001')->assertSee('No current inspection');
        $this->actingAs($this->user('Requester'))->get(route('assets.scan', $asset->qr_token))->assertForbidden();
        $oldToken = $asset->qr_token;
        $this->actingAs($this->head)->get(route('assets.show', $asset))->assertOk()
            ->assertSee('QR Code')->assertSee('Scan this code to open this asset for inspection or maintenance.')
            ->assertSee('data-asset-qr-modal', false)->assertSee('id="asset-qr-label"', false)
            ->assertSee('data-asset-qr-print', false)->assertSee('<svg', false)
            ->assertSee('LRC Building')->assertSee('Ground Floor • Registrar Entrance')
            ->assertDontSee($asset->qr_token)
            ->assertDontSee('href="'.route('assets.qr.print', $asset).'"', false);
        $this->assertSame($oldToken, $asset->fresh()->qr_token);
        $this->actingAs($this->staff)->get(route('assets.show', $asset))->assertOk()
            ->assertSee('QR Code')->assertSee('<svg', false)->assertDontSee('data-asset-qr-modal', false);
        $this->actingAs($this->staff)->get(route('assets.qr.print', $asset))->assertForbidden();
        $this->actingAs($this->head)->get(route('assets.qr.print', $asset))->assertOk()->assertSee('<svg', false)->assertSee('SNSU-DC FMO');
        $this->actingAs($this->head)->post(route('assets.qr.regenerate', $asset))->assertRedirect();
        $this->assertNotSame($oldToken, $asset->fresh()->qr_token);
        $this->actingAs($this->staff)->get(route('assets.scan', $oldToken))->assertNotFound();
    }

    public function test_inspection_repair_and_disposal_lifecycle_is_audited_and_authorized(): void
    {
        Storage::fake('local');
        $asset = $this->asset();
        $item = $this->inspectionItem($asset);
        $checklist = collect($this->type->checklist)->mapWithKeys(fn ($check) => [$check['code'] => 'YES'])->all();
        $this->actingAs($this->staff)->post(route('assets.inspections.store', $asset), [
            'inspection_item_id' => $item->id,
            'condition' => 'NEEDS_REPAIR', 'checklist' => $checklist, 'summary' => 'Pressure gauge is outside the normal range.',
            'recommended_action' => 'REPAIR_REVIEW', 'evidence' => [UploadedFile::fake()->image('gauge.jpg', 1200, 900)],
        ])->assertRedirect(route('assets.show', $asset));
        $asset->refresh();
        $this->assertSame('NEEDS_REPAIR', $asset->condition->value);
        $this->assertNotNull($asset->last_inspected_at);
        $this->assertSame(1, $asset->inspectionItems()->count());
        $repair = AssetActionRequest::where('type', 'REPAIR')->firstOrFail();
        foreach ([$this->head, $this->director, $this->admin] as $reviewer) {
            $this->assertSame('ASSET_REPAIR_REQUESTED', $reviewer->notifications()->firstOrFail()->data['event_key']);
        }
        $this->actingAs($this->head)->post(route('assets.actions.review', $repair), ['decision' => 'APPROVE_REPAIR'])->assertRedirect();
        $this->assertSame('UNDER_REPAIR', $asset->fresh()->lifecycle_status->value);
        $this->assertSame('ASSET_REPAIR_APPROVED', $this->staff->notifications()->firstOrFail()->data['event_key']);
        $this->actingAs($this->head)->post(route('assets.actions.assign-maintenance', $repair), ['assignee_ids' => [$this->staff->personnelProfile->id]])->assertRedirect();
        $this->actingAs($this->staff)->post(route('assets.maintenance.store', $asset), [
            'work_performed' => 'Replaced the faulty gauge and tested pressure.', 'parts_materials' => 'Replacement gauge',
            'result' => 'REPAIRED_SERVICEABLE', 'evidence' => [UploadedFile::fake()->image('repaired.jpg', 1200, 900)],
        ])->assertRedirect();
        $this->assertSame('ACTIVE', $asset->fresh()->lifecycle_status->value);
        $this->assertSame('GOOD', $asset->fresh()->condition->value);
        $this->assertSame(1, $asset->maintenanceRecords()->count());

        $this->actingAs($this->staff)->post(route('assets.actions.request', $asset), [
            'type' => 'DISPOSAL', 'problem_description' => 'Cylinder is now structurally unsafe.',
            'evidence' => [UploadedFile::fake()->image('unsafe.jpg', 1200, 900)],
        ])->assertRedirect();
        $disposal = AssetActionRequest::where('type', 'DISPOSAL')->latest()->firstOrFail();
        $this->assertSame('FOR_DISPOSAL_REVIEW', $asset->fresh()->lifecycle_status->value);
        $this->actingAs($this->staff)->post(route('assets.actions.review', $disposal), ['decision' => 'APPROVE_DISPOSAL'])->assertForbidden();
        $this->actingAs($this->head)->post(route('assets.actions.review', $disposal), ['decision' => 'APPROVE_DISPOSAL'])->assertRedirect();
        $this->assertSame('APPROVED_FOR_DISPOSAL', $asset->fresh()->lifecycle_status->value);
        $this->actingAs($this->staff)->post(route('assets.dispose', $asset), ['disposed_at' => now()->toDateString(), 'remarks' => 'Disposed by accredited handler.'])->assertForbidden();
        $this->actingAs($this->head)->post(route('assets.dispose', $asset), ['disposed_at' => now()->toDateString(), 'remarks' => 'Disposed by accredited handler.'])->assertRedirect();
        $this->assertSame('DISPOSED', $asset->fresh()->lifecycle_status->value);
        $this->assertGreaterThanOrEqual(7, $asset->lifecycleEvents()->count());
    }

    public function test_asset_types_are_managed_without_deleting_existing_assets(): void
    {
        $this->actingAs($this->head)->post('/asset-types', ['name' => 'Water Pump', 'code' => 'WP', 'description' => 'Campus water pump',
            'default_inspection_frequency' => 'MONTHLY', 'checklist_text' => "Pump is present\nMotor runs\nNo visible leak", 'is_active' => '1'])->assertRedirect('/asset-types');
        $type = AssetType::where('code', 'WP')->firstOrFail();
        $this->assertCount(3, $type->checklist);
        $asset = $this->asset(['asset_type_id' => $type->id, 'asset_code' => 'WP-DC-001', 'name' => 'Water Pump']);
        $this->actingAs($this->head)->delete(route('asset-types.deactivate', $type))->assertRedirect();
        $this->assertFalse($type->fresh()->is_active);
        $this->assertDatabaseHas('inspectable_assets', ['id' => $asset->id, 'asset_type_id' => $type->id]);
        $this->actingAs($this->head)->post('/assets', $this->assetPayload(['asset_code' => 'WP-DC-002', 'asset_type_id' => $type->id]))->assertSessionHasErrors('asset_type_id');
    }

    private function asset(array $overrides = []): InspectableAsset
    {
        return InspectableAsset::create(array_merge(collect($this->assetPayload())->except(['condition', 'lifecycle_status'])->all(), [
            'condition' => 'GOOD', 'lifecycle_status' => 'ACTIVE', 'status' => 'ACTIVE',
        ], $overrides));
    }

    private function assetPayload(array $overrides = []): array
    {
        return array_merge(['asset_code' => 'FE-DC-001', 'asset_type_id' => $this->type->id, 'name' => 'Fire Extinguisher',
            'campus_id' => $this->campus->id, 'building_id' => $this->building->id, 'floor_id' => $this->floor->id,
            'building_location_id' => $this->area->id, 'exact_placement' => 'Beside the entrance door', 'inspection_frequency' => 'MONTHLY',
            'condition' => 'GOOD', 'lifecycle_status' => 'ACTIVE'], $overrides);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'APPROVED']);
        $user->assignRole($role);

        return $user;
    }

    private function inspectionItem(InspectableAsset $asset): InspectionItem
    {
        $template = RoutineTaskTemplate::create([
            'title' => 'Daily asset inspection', 'task_type' => 'INSPECTION', 'frequency' => 'DAILY',
            'recurrence_config' => [], 'campus_id' => $this->campus->id, 'building_id' => $this->building->id,
            'asset_type_id' => $this->type->id, 'inspection_asset_scope' => 'TYPE_LOCATION',
            'start_date' => now()->toDateString(), 'due_time' => '17:00', 'evidence_required' => false,
            'requires_review' => false, 'is_active' => true, 'created_by' => $this->head->id,
        ]);
        RoutineTaskTemplateAssignment::create([
            'routine_task_template_id' => $template->id, 'fmo_personnel_id' => $this->staff->personnelProfile->id,
            'assigned_by' => $this->head->id, 'assigned_at' => now(), 'active_marker' => 1,
        ]);
        $today = CarbonImmutable::today(config('app.timezone'));
        app(RoutineOccurrenceService::class)->generate($today, $today);

        return $template->occurrences()->firstOrFail()->inspectionRun->items()->where('inspectable_asset_id', $asset->id)->firstOrFail();
    }
}
