<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\AssetType;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoContactMessage;
use App\Models\FmoContactThread;
use App\Models\FmoPersonnel;
use App\Models\InspectableAsset;
use App\Models\RoutineTaskTemplate;
use App\Models\RoutineTaskTemplateAssignment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAssessment;
use App\Models\WorkOrderAssignment;
use App\Models\WorkOrderCategory;
use App\Models\WorkSession;
use App\Services\RoutineOccurrenceService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\RoutineOperationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalUiModernizationTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private Campus $campus;

    private Building $building;

    private WorkOrderCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 09:00:00', 'Asia/Manila'));
        $this->seed([AuthorizationSeeder::class, RoutineOperationsSeeder::class]);
        $this->head = $this->user('FMO Head');
        $this->campus = Campus::create(['code' => 'OPS', 'name' => 'Operations Campus', 'is_active' => true]);
        $this->building = Building::create(['campus_id' => $this->campus->id, 'code' => 'OPS', 'name' => 'Operations Building', 'is_active' => true]);
        $this->category = WorkOrderCategory::create(['name' => 'Operational Electrical', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_my_tasks_are_assigned_only_filterable_paginated_and_responsive(): void
    {
        $staff = $this->user('FMO Staff');
        $otherStaff = $this->user('FMO Staff');
        $person = $this->person($staff, 'OPS-STAFF-01');
        $otherPerson = $this->person($otherStaff, 'OPS-STAFF-02');
        $requester = $this->user('Requester');
        $orders = collect(range(1, 22))->map(function (int $number) use ($requester, $person): WorkOrder {
            $order = $this->order($requester, 'ASSIGNED', $number === 1 ? 'Urgent aircon repair' : 'Assigned repair '.$number, $number === 1 ? 'URGENT' : 'NORMAL');
            $this->assign($order, $person, now()->subMinutes($number));

            return $order;
        });
        $other = $this->order($requester, 'ASSIGNED', 'Another technician task');
        $this->assign($other, $otherPerson);

        $this->actingAs($staff)->get(route('work-orders.my-tasks'))
            ->assertOk()
            ->assertViewHas('orders', fn ($page): bool => $page->total() === 22 && $page->count() === 20)
            ->assertSee('lg:hidden', false)
            ->assertDontSee('Another technician task');

        $this->actingAs($staff)->get(route('work-orders.my-tasks', [
            'search' => 'aircon', 'priority' => 'URGENT', 'category' => $this->category->id,
            'sort' => 'highest_priority', 'per_page' => 50,
        ]))->assertOk()
            ->assertSee('Urgent aircon repair')
            ->assertDontSee('Assigned repair 2')
            ->assertViewHas('orders', fn ($page): bool => $page->total() === 1 && $page->perPage() === 50)
            ->assertSee('Assess Work');

        $this->assertTrue($orders->every(fn (WorkOrder $order): bool => $order->activeAssignments()->where('fmo_personnel_id', $person->id)->exists()));
    }

    public function test_contact_fmo_filters_keep_requester_isolation_and_management_context(): void
    {
        $requester = $this->user('Requester');
        $other = $this->user('Requester');
        $own = FmoContactThread::create([
            'requester_user_id' => $requester->id, 'category' => 'GENERAL_FACILITIES_INQUIRY',
            'subject' => 'Office access question', 'status' => 'OPEN',
        ]);
        FmoContactMessage::create(['fmo_contact_thread_id' => $own->id, 'sender_user_id' => $requester->id, 'body' => 'Please clarify the building access schedule.']);
        $foreign = FmoContactThread::create([
            'requester_user_id' => $other->id, 'category' => 'OTHER',
            'subject' => 'Private requester inquiry', 'status' => 'RESPONDED',
        ]);
        FmoContactMessage::create(['fmo_contact_thread_id' => $foreign->id, 'sender_user_id' => $other->id, 'body' => 'building access secret']);

        $this->actingAs($requester)->get(route('contact-fmo.index', ['search' => 'building access', 'status' => 'open']))
            ->assertOk()->assertSee('Office access question')->assertDontSee('Private requester inquiry')
            ->assertViewHas('threads', fn ($page): bool => $page->total() === 1)
            ->assertSee('View Conversation')->assertSee('Create Work Order');

        $this->actingAs($this->head)->get(route('fmo-inquiries.index', ['search' => $other->name, 'status' => 'responded']))
            ->assertOk()->assertSee('Private requester inquiry')->assertDontSee('Office access question');
    }

    public function test_assessment_review_filters_without_changing_review_scope(): void
    {
        $requester = $this->user('Requester');
        $staff = $this->user('FMO Staff');
        $person = $this->person($staff, 'OPS-ASSESS-01');
        $urgent = $this->order($requester, 'ASSESSMENT_REVIEW', 'Urgent electrical assessment', 'URGENT');
        $assignment = $this->assign($urgent, $person);
        WorkOrderAssessment::create([
            'work_order_id' => $urgent->id, 'work_order_assignment_id' => $assignment->id,
            'fmo_personnel_id' => $person->id, 'outcome' => 'READY_FOR_WORK',
            'findings' => 'Ready for repair.', 'assessed_at' => now(),
        ]);
        $normal = $this->order($requester, 'ASSESSMENT_REVIEW', 'Routine plumbing assessment');
        $normalAssignment = $this->assign($normal, $person);
        WorkOrderAssessment::create([
            'work_order_id' => $normal->id, 'work_order_assignment_id' => $normalAssignment->id,
            'fmo_personnel_id' => $person->id, 'outcome' => 'READY_FOR_WORK',
            'findings' => 'Ready.', 'assessed_at' => now()->subDay(),
        ]);

        $this->actingAs($this->head)->get(route('work-orders.assessment-queue', [
            'search' => 'electrical', 'priority' => 'URGENT', 'category' => $this->category->id,
        ]))->assertOk()->assertSee('Urgent electrical assessment')->assertDontSee('Routine plumbing assessment')
            ->assertSee('Review Assessment')->assertSee('lg:hidden', false);

        $this->actingAs($requester)->get(route('work-orders.assessment-queue'))->assertForbidden();
    }

    public function test_active_work_and_verification_present_only_their_existing_status_queues(): void
    {
        $requester = $this->user('Requester');
        $headPerson = $this->person($this->head, 'OPS-HEAD-01');
        $active = $this->order($requester, 'IN_PROGRESS', 'Active generator repair', 'URGENT');
        $assignment = $this->assign($active, $headPerson);
        WorkSession::create([
            'work_order_id' => $active->id, 'work_order_assignment_id' => $assignment->id,
            'fmo_personnel_id' => $headPerson->id, 'started_by_user_id' => $this->head->id,
            'started_at' => now()->subHour(), 'active_marker' => 1,
        ]);
        $verification = $this->order($requester, 'FOR_VERIFICATION', 'Completed roof repair');
        $verification->update(['completion_submitted_at' => now()->subHours(2), 'completion_summary' => 'Repair complete.']);
        $this->assign($verification, $headPerson);

        $this->actingAs($this->head)->get(route('work-orders.execution-queue', ['queue' => 'active', 'search' => 'generator']))
            ->assertOk()->assertSee('Active generator repair')->assertDontSee('Completed roof repair')
            ->assertSee('Your active session')->assertSee('Continue Work');

        $this->actingAs($this->head)->get(route('work-orders.execution-queue', ['queue' => 'verification', 'search' => 'roof']))
            ->assertOk()->assertSee('Completed roof repair')->assertDontSee('Active generator repair')
            ->assertSee('0 photos')->assertSee('Review Completion');
    }

    public function test_upcoming_inspections_are_assigned_only_filterable_and_never_startable(): void
    {
        $staff = $this->user('FMO Staff');
        $otherStaff = $this->user('FMO Staff');
        $person = $this->person($staff, 'OPS-INSPECT-01');
        $this->person($otherStaff, 'OPS-INSPECT-02');
        $type = AssetType::where('code', 'FE')->firstOrFail();
        InspectableAsset::create([
            'asset_code' => 'FE-OPS-001', 'asset_type_id' => $type->id, 'name' => 'Operations Fire Extinguisher',
            'campus_id' => $this->campus->id, 'building_id' => $this->building->id,
            'inspection_frequency' => 'MONTHLY', 'status' => 'ACTIVE', 'condition' => 'GOOD', 'lifecycle_status' => 'ACTIVE',
        ]);
        $template = RoutineTaskTemplate::create([
            'title' => 'Upcoming Operations Inspection', 'task_type' => 'INSPECTION', 'frequency' => 'DAILY',
            'recurrence_config' => [], 'asset_type_id' => $type->id, 'inspection_asset_scope' => 'ALL_TYPE',
            'start_date' => '2026-11-02', 'due_time' => '16:00', 'evidence_required' => false,
            'requires_review' => false, 'is_active' => true, 'created_by' => $this->head->id,
        ]);
        RoutineTaskTemplateAssignment::create([
            'routine_task_template_id' => $template->id, 'fmo_personnel_id' => $person->id,
            'assigned_by' => $this->head->id, 'assigned_at' => now(), 'active_marker' => 1,
        ]);
        $future = CarbonImmutable::parse('2026-11-02', 'Asia/Manila');
        app(RoutineOccurrenceService::class)->generate($future, $future);

        $this->actingAs($staff)->get(route('inspections.index', ['status' => 'upcoming', 'search' => 'Operations']))
            ->assertOk()->assertSee('Upcoming Operations Inspection')->assertSee('Available Nov 2, 2026')
            ->assertSee('View Inspection')->assertDontSee('Start Inspection');

        $this->actingAs($otherStaff)->get(route('inspections.index', ['status' => 'upcoming']))
            ->assertOk()->assertDontSee('Upcoming Operations Inspection');
    }

    public function test_report_sidebar_uses_mutually_exclusive_exact_route_matching(): void
    {
        $work = $this->actingAs($this->head)->get(route('reports.history'))->assertOk()->getContent();
        $this->assertStringContainsString('nav-link-active', $this->navigationClass($work, route('reports.history')));
        $this->assertStringNotContainsString('nav-link-active', $this->navigationClass($work, route('reports.routine')));

        $routine = $this->actingAs($this->head)->get(route('reports.routine'))->assertOk()->getContent();
        $this->assertStringNotContainsString('nav-link-active', $this->navigationClass($routine, route('reports.history')));
        $this->assertStringContainsString('nav-link-active', $this->navigationClass($routine, route('reports.routine')));

        $active = $this->actingAs($this->head)->get(route('work-orders.execution-queue', 'active'))->assertOk()->getContent();
        $this->assertStringContainsString('nav-link-active', $this->navigationClass($active, route('work-orders.execution-queue', 'active')));
        $this->assertStringNotContainsString('nav-link-active', $this->navigationClass($active, route('work-orders.execution-queue', 'verification')));
    }

    private function navigationClass(string $html, string $url): string
    {
        $matched = preg_match('/<a class="([^"]*)" href="'.preg_quote($url, '/').'"/', $html, $matches);
        $this->assertSame(1, $matched, 'Expected navigation link was not rendered.');

        return $matches[1];
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => AccountStatus::Approved->value]);
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

    private function order(User $requester, string $status, string $subject, string $urgency = 'NORMAL'): WorkOrder
    {
        return WorkOrder::create([
            'work_order_number' => 'WO-OPS-'.str_pad((string) (WorkOrder::count() + 1), 5, '0', STR_PAD_LEFT),
            'requester_id' => $requester->id, 'campus_id' => $this->campus->id,
            'building_id' => $this->building->id, 'work_order_category_id' => $this->category->id,
            'subject' => $subject, 'description' => $subject, 'urgency' => $urgency,
            'status' => $status, 'submitted_at' => now(),
        ]);
    }

    private function assign(WorkOrder $order, FmoPersonnel $personnel, mixed $assignedAt = null): WorkOrderAssignment
    {
        return WorkOrderAssignment::create([
            'work_order_id' => $order->id, 'fmo_personnel_id' => $personnel->id,
            'assigned_by_user_id' => $this->head->id, 'assigned_at' => $assignedAt ?? now(), 'active_marker' => 1,
        ]);
    }
}
