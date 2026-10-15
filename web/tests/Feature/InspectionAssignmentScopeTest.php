<?php

namespace Tests\Feature;

use App\Models\AssetType;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\InspectableAsset;
use App\Models\InspectionTemplateAssetAssignment;
use App\Models\RoutineTaskTemplate;
use App\Models\RoutineTaskTemplateAssignment;
use App\Models\User;
use App\Services\RoutineOccurrenceService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\RoutineOperationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class InspectionAssignmentScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $staffA;

    private User $staffB;

    private User $staffC;

    private FmoPersonnel $personA;

    private FmoPersonnel $personB;

    private FmoPersonnel $personC;

    private Campus $campus;

    private Building $building;

    private AssetType $type;

    /** @var array<int, InspectableAsset> */
    private array $assets;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 09:00:00', 'Asia/Manila'));
        $this->seed([AuthorizationSeeder::class, RoutineOperationsSeeder::class]);
        $this->head = $this->user('FMO Head');
        $this->staffA = $this->user('FMO Staff');
        $this->staffB = $this->user('FMO Staff');
        $this->staffC = $this->user('FMO Staff');
        $this->personA = $this->person($this->staffA, 'FMO-INS-001');
        $this->personB = $this->person($this->staffB, 'FMO-INS-002');
        $this->personC = $this->person($this->staffC, 'FMO-INS-003');
        $this->campus = Campus::create(['code' => 'DC', 'name' => 'Del Carmen Campus', 'is_active' => true]);
        $this->building = Building::create(['campus_id' => $this->campus->id, 'code' => 'ADM', 'name' => 'Administration Building', 'is_active' => true]);
        $this->type = AssetType::where('code', 'FE')->firstOrFail();
        $this->assets = collect(range(1, 3))->map(fn (int $number) => InspectableAsset::create([
            'asset_code' => 'FE-DC-00'.$number, 'asset_type_id' => $this->type->id, 'name' => 'Fire Extinguisher '.$number,
            'campus_id' => $this->campus->id, 'building_id' => $this->building->id, 'exact_placement' => 'Zone '.$number,
            'inspection_frequency' => 'MONTHLY', 'status' => 'ACTIVE', 'condition' => 'GOOD', 'lifecycle_status' => 'ACTIVE',
        ]))->all();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_recurring_form_creates_specific_scope_with_default_team_and_per_asset_override(): void
    {
        $this->actingAs($this->head)->get(route('routine-templates.create'))->assertOk()
            ->assertSee('General Routine Task')->assertSee('Asset Inspection')
            ->assertSee('Specific assets')->assertSee('All active assets of this type')
            ->assertSee('Asset type in selected location')->assertSee('Customize assignment by asset')
            ->assertSee('data-inspection-template-form', false)->assertSee('Select all visible');

        $start = now()->addDays(8)->toDateString();
        $this->actingAs($this->head)->post(route('routine-templates.store'), [
            'title' => 'Monthly Fire Extinguisher Inspection', 'task_type' => 'INSPECTION', 'frequency' => 'DAILY',
            'start_date' => $start, 'due_time' => '16:00', 'asset_type_id' => $this->type->id,
            'inspection_asset_scope' => 'SPECIFIC', 'selected_asset_ids' => collect($this->assets)->pluck('id')->all(),
            'assignee_ids' => [$this->personA->id], 'customize_asset_assignments' => '1',
            'asset_assignees' => [$this->assets[2]->id => [$this->personB->id]], 'is_active' => '1',
        ])->assertRedirect(route('routine-templates.index'))->assertSessionHasNoErrors();
        $template = RoutineTaskTemplate::where('title', 'Monthly Fire Extinguisher Inspection')->firstOrFail();
        $this->assertSame('SPECIFIC', $template->inspection_asset_scope);
        $this->assertCount(3, $template->selectedAssets);
        app(RoutineOccurrenceService::class)->generate(CarbonImmutable::parse($start), CarbonImmutable::parse($start));
        $run = $template->occurrences()->firstOrFail()->inspectionRun;
        $items = $run->items()->with('activeAssignees')->get()->keyBy('inspectable_asset_id');
        $this->assertEqualsCanonicalizing([$this->personA->id], $items[$this->assets[0]->id]->activeAssignees->modelKeys());
        $this->assertEqualsCanonicalizing([$this->personA->id], $items[$this->assets[1]->id]->activeAssignees->modelKeys());
        $this->assertEqualsCanonicalizing([$this->personB->id], $items[$this->assets[2]->id]->activeAssignees->modelKeys());
        $this->actingAs($this->staffA)->get(route('inspections.show', $run))->assertOk()->assertSee('FE-DC-001')->assertSee('FE-DC-002')->assertDontSee('FE-DC-003');
        $this->actingAs($this->staffB)->get(route('inspections.show', $run))->assertOk()->assertSee('FE-DC-003')->assertDontSee('FE-DC-001');
    }

    public function test_permission_and_active_item_assignment_are_both_required_for_web_qr_and_api(): void
    {
        $template = $this->template([$this->personA->id]);
        $run = $this->generate($template, '2026-10-16');
        $item = $run->items()->where('inspectable_asset_id', $this->assets[0]->id)->firstOrFail();

        $this->actingAs($this->staffA)->get(route('assets.scan', $this->assets[0]->qr_token))->assertOk()->assertSee('Assigned to you')->assertSee('Start Inspection');
        $this->actingAs($this->staffC)->get(route('assets.scan', $this->assets[0]->qr_token))->assertOk()->assertSee('Inspection not assigned to you')->assertDontSee('Start Inspection');
        $this->actingAs($this->staffC)->get(route('assets.inspect', [$this->assets[0], 'item' => $item->id]))->assertForbidden();
        $this->actingAs($this->head)->get(route('assets.inspect', [$this->assets[0], 'item' => $item->id]))->assertForbidden();

        $dispatcher = $this->user('FMO Dispatcher');
        $dispatcherPersonnel = $this->person($dispatcher, 'FMO-INS-004');
        $this->actingAs($this->head)->post(route('inspections.assignments.item', [$run, $item]), ['assignee_ids' => [$dispatcherPersonnel->id]])->assertRedirect();
        $this->actingAs($dispatcher)->get(route('assets.scan', $this->assets[0]->qr_token))->assertOk()->assertSee('You are assigned, but your account does not currently have permission')->assertDontSee('Start Inspection');

        app('auth')->forgetGuards();
        $token = $this->staffC->createToken('unassigned-inspection')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/inspectable-assets/scan/'.$this->assets[1]->qr_token)
            ->assertOk()->assertJsonPath('data.is_assigned_inspector', false)->assertJsonPath('data.can_inspect', false);
        $this->withToken($token)->postJson("/api/v1/inspection-runs/{$run->id}/items/".$run->items()->where('inspectable_asset_id', $this->assets[1]->id)->value('id'), [])->assertForbidden();
    }

    public function test_template_team_change_affects_future_runs_without_rewriting_history(): void
    {
        $template = $this->template([$this->personA->id]);
        $october16 = $this->generate($template, '2026-10-16');
        $this->replaceTemplateTeam($template, [$this->personB->id]);
        $october17 = $this->generate($template, '2026-10-17');

        $oldItem = $october16->items()->with('activeAssignees')->firstOrFail();
        $newItem = $october17->items()->with('activeAssignees')->firstOrFail();
        $this->assertEqualsCanonicalizing([$this->personA->id], $oldItem->activeAssignees->modelKeys());
        $this->assertEqualsCanonicalizing([$this->personB->id], $newItem->activeAssignees->modelKeys());
        $this->actingAs($this->staffA)->get(route('inspections.show', $october16))->assertOk();
        $this->actingAs($this->staffA)->get(route('inspections.show', $october17))->assertForbidden();
        $this->actingAs($this->staffB)->get(route('inspections.show', $october17))->assertOk();
    }

    public function test_current_item_reassignment_is_audited_and_completed_item_cannot_be_resubmitted_or_reassigned(): void
    {
        $template = $this->template([$this->personA->id, $this->personB->id]);
        $run = $this->generate($template, '2026-10-16');
        $item = $run->items()->firstOrFail();
        $this->actingAs($this->head)->post(route('inspections.assignments.item', [$run, $item]), [
            'assignee_ids' => [$this->personB->id], 'reason' => 'Coverage change for this asset.',
        ])->assertRedirect();
        $this->assertDatabaseHas('inspection_item_assignments', ['inspection_item_id' => $item->id, 'fmo_personnel_id' => $this->personA->id, 'active_marker' => null, 'change_reason' => 'Coverage change for this asset.']);
        $this->assertDatabaseHas('inspection_item_assignments', ['inspection_item_id' => $item->id, 'fmo_personnel_id' => $this->personB->id, 'active_marker' => 1]);
        $this->actingAs($this->staffA)->get(route('assets.inspect', [$item->asset, 'item' => $item->id]))->assertForbidden();
        $this->actingAs($this->staffB)->get(route('assets.inspect', [$item->asset, 'item' => $item->id]))->assertOk();
        $removedNotice = $this->staffA->notifications()->latest()->firstOrFail();
        $this->assertSame('INSPECTION_ASSIGNMENT_UPDATED', $removedNotice->data['event_key']);
        $this->assertStringContainsString('removed from your', $removedNotice->data['message']);
        $this->assertCount(0, $this->staffB->notifications);

        $checklist = collect($item->checklist_snapshot)->mapWithKeys(fn ($check) => [$check['code'] => 'YES'])->all();
        $payload = ['result' => 'GOOD', 'checklist' => $checklist];
        $this->actingAs($this->staffB)->post(route('inspections.items.record', [$run, $item]), $payload)->assertRedirect();
        $this->assertSame($this->personB->id, $item->fresh()->inspector_personnel_id);
        $this->actingAs($this->staffB)->post(route('inspections.items.record', [$run, $item]), $payload)->assertForbidden();
        $this->actingAs($this->head)->from(route('inspections.show', $run))->post(route('inspections.assignments.item', [$run, $item]), ['assignee_ids' => [$this->personA->id]])
            ->assertRedirect(route('inspections.show', $run))->assertSessionHasErrors('assignee_ids');
        $this->assertSame($this->personB->id, $item->fresh()->inspector_personnel_id);
    }

    public function test_due_notifications_are_summarized_per_run_and_api_returns_only_assigned_items(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 18:00:00', 'Asia/Manila'));
        $template = $this->template([$this->personA->id]);
        InspectionTemplateAssetAssignment::create([
            'routine_task_template_id' => $template->id, 'inspectable_asset_id' => $this->assets[2]->id,
            'fmo_personnel_id' => $this->personB->id, 'assigned_by' => $this->head->id,
            'assigned_at' => now(), 'active_marker' => 1,
        ]);
        $template->update(['customize_asset_assignments' => true]);
        $arguments = ['--from' => '2026-10-16', '--to' => '2026-10-16'];
        $this->assertSame(0, Artisan::call('routine:generate', $arguments));
        $run = $template->occurrences()->firstOrFail()->inspectionRun;
        $noticeA = $this->staffA->notifications()->where('data->event_key', 'INSPECTION_OVERDUE')->firstOrFail();
        $noticeB = $this->staffB->notifications()->where('data->event_key', 'INSPECTION_OVERDUE')->firstOrFail();
        $this->assertStringContainsString('2 assets assigned to you', $noticeA->data['message']);
        $this->assertStringContainsString('1 asset assigned to you', $noticeB->data['message']);
        $this->assertSame(0, $this->staffC->notifications()->count());
        $this->assertSame(0, Artisan::call('routine:generate', $arguments));
        $this->assertSame(1, $this->staffA->notifications()->where('data->event_key', 'INSPECTION_OVERDUE')->count());

        app('auth')->forgetGuards();
        $tokenA = $this->staffA->createToken('assigned-a')->plainTextToken;
        $this->withToken($tokenA)->getJson('/api/v1/inspection-runs/'.$run->id)->assertOk()
            ->assertJsonCount(2, 'data.items')->assertJsonMissing(['asset_code' => 'FE-DC-003']);
        app('auth')->forgetGuards();
        $tokenB = $this->staffB->createToken('assigned-b')->plainTextToken;
        $this->withToken($tokenB)->getJson('/api/v1/inspection-runs/'.$run->id)->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.asset.asset_code', 'FE-DC-003');
    }

    /** @param array<int, string> $personnelIds */
    private function template(array $personnelIds): RoutineTaskTemplate
    {
        $template = RoutineTaskTemplate::create([
            'title' => 'Daily Fire Extinguisher Inspection', 'task_type' => 'INSPECTION', 'frequency' => 'DAILY',
            'recurrence_config' => [], 'asset_type_id' => $this->type->id, 'inspection_asset_scope' => 'ALL_TYPE',
            'start_date' => '2026-10-16', 'due_time' => '08:00', 'evidence_required' => false,
            'requires_review' => false, 'is_active' => true, 'created_by' => $this->head->id,
        ]);
        foreach ($personnelIds as $personnelId) {
            RoutineTaskTemplateAssignment::create([
                'routine_task_template_id' => $template->id, 'fmo_personnel_id' => $personnelId,
                'assigned_by' => $this->head->id, 'assigned_at' => now(), 'active_marker' => 1,
            ]);
        }

        return $template;
    }

    private function generate(RoutineTaskTemplate $template, string $date)
    {
        $day = CarbonImmutable::parse($date, config('app.timezone'));
        app(RoutineOccurrenceService::class)->generate($day, $day);

        return $template->occurrences()->where('period_key', $date)->firstOrFail()->inspectionRun;
    }

    /** @param array<int, string> $personnelIds */
    private function replaceTemplateTeam(RoutineTaskTemplate $template, array $personnelIds): void
    {
        $template->assignments()->whereNull('unassigned_at')->update(['unassigned_at' => now(), 'active_marker' => null]);
        foreach ($personnelIds as $personnelId) {
            RoutineTaskTemplateAssignment::create([
                'routine_task_template_id' => $template->id, 'fmo_personnel_id' => $personnelId,
                'assigned_by' => $this->head->id, 'assigned_at' => now(), 'active_marker' => 1,
            ]);
        }
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'APPROVED']);
        $user->assignRole($role);

        return $user;
    }

    private function person(User $user, string $identifier): FmoPersonnel
    {
        return FmoPersonnel::create([
            'user_id' => $user->id, 'personnel_identifier' => $identifier,
            'designation' => 'Facilities Staff', 'personnel_status' => 'ACTIVE',
        ]);
    }
}
