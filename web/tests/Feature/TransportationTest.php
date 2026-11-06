<?php

namespace Tests\Feature;

use App\Enums\TransportStatus;
use App\Models\AssetType;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\InspectableAsset;
use App\Models\Skill;
use App\Models\TransportRequest;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AssetMaintenanceHistoryService;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\RoutineOperationsSeeder;
use Database\Seeders\SkillSeeder;
use Database\Seeders\WorkOrderCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TransportationTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $otherRequester;

    private User $director;

    private User $head;

    private User $dispatcher;

    private User $driverUser;

    private User $otherStaff;

    private FmoPersonnel $driver;

    private InspectableAsset $van;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', config('database.connections.'.config('database.default').'.database'));
        Storage::fake('local');
        $this->travelTo('2026-11-10 09:00:00');
        $this->seed([AuthorizationSeeder::class, SkillSeeder::class, RoutineOperationsSeeder::class, WorkOrderCategorySeeder::class]);
        $this->requester = $this->user('Requester');
        $this->otherRequester = $this->user('Requester');
        $this->director = $this->user('Campus Director');
        $this->head = $this->user('FMO Head');
        $this->dispatcher = $this->user('FMO Dispatcher');
        $this->driverUser = $this->user('FMO Staff');
        $this->otherStaff = $this->user('FMO Staff');
        $this->driver = $this->person($this->driverUser, 'FMO-DRV-1', true);
        $this->person($this->otherStaff, 'FMO-STF-2', false);
        $campus = Campus::create(['code' => 'DC', 'name' => 'Del Carmen Campus', 'is_active' => true]);
        $building = Building::create(['campus_id' => $campus->id, 'code' => 'GAR', 'name' => 'Motor Pool', 'is_active' => true]);
        $this->van = InspectableAsset::create([
            'asset_code' => 'VEH-VAN-001', 'asset_type_id' => AssetType::where('code', 'VAN')->value('id'), 'asset_class' => 'VEHICLE', 'name' => 'University Van',
            'campus_id' => $campus->id, 'building_id' => $building->id, 'brand' => 'Toyota', 'model' => 'HiAce', 'inspection_frequency' => 'MONTHLY',
            'status' => 'ACTIVE', 'condition' => 'GOOD', 'lifecycle_status' => 'ACTIVE', 'current_odometer_km' => 48250,
            'specifications' => ['plate_number' => 'ABC 1234', 'passenger_capacity' => 12],
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_requester_submits_request_with_optional_memo_and_cannot_approve_it(): void
    {
        $this->actingAs($this->requester)->post(route('transport.store'), $this->payload([
            'documents' => [UploadedFile::fake()->create('travel-order.pdf', 120, 'application/pdf')],
        ]))->assertRedirect();

        $transport = TransportRequest::sole();
        $this->assertSame('TR-2026-0001', $transport->request_number);
        $this->assertSame(TransportStatus::PendingApproval, $transport->status);
        $this->assertSame(1, $transport->attachments()->where('purpose', TransportRequest::DOCUMENT_PURPOSE)->count());
        Storage::disk('local')->assertExists($transport->attachments()->first()->stored_path);
        $this->assertSame(1, $this->director->notifications()->count());
        $this->actingAs($this->requester)->post(route('transport.approve', $transport))->assertForbidden();
        $this->actingAs($this->requester)->post(route('transport.assign', $transport), [])->assertForbidden();
    }

    public function test_request_needs_no_document_and_numbers_are_sequential(): void
    {
        $this->actingAs($this->requester)->post(route('transport.store'), $this->payload())->assertRedirect();
        $this->actingAs($this->requester)->post(route('transport.store'), $this->payload())->assertRedirect();

        $this->assertEqualsCanonicalizing(['TR-2026-0001', 'TR-2026-0002'], TransportRequest::pluck('request_number')->all());
        $this->actingAs($this->requester)->post(route('transport.store'), $this->payload(['travel_date' => '2026-11-01']))->assertSessionHasErrors('travel_date');
    }

    public function test_director_and_head_approve_but_unauthorized_users_cannot(): void
    {
        $first = $this->pending();
        $second = $this->pending();

        $this->actingAs($this->director)->post(route('transport.approve', $first), ['approval_authority' => 'OWN_AUTHORITY'])->assertRedirect();
        $this->actingAs($this->head)->post(route('transport.approve', $second))->assertRedirect();
        $this->assertSame(TransportStatus::Approved, $first->fresh()->status);
        $this->assertSame($this->director->id, $first->fresh()->approved_by);
        $this->assertSame(TransportStatus::Approved, $second->fresh()->status);

        $third = $this->pending();
        $this->actingAs($this->otherStaff)->post(route('transport.approve', $third))->assertForbidden();
        $this->actingAs($this->otherRequester)->post(route('transport.approve', $third))->assertForbidden();
        $this->actingAs($this->driverUser)->post(route('transport.reject', $third), ['rejection_reason' => 'No'])->assertForbidden();
        $this->assertSame(TransportStatus::PendingApproval, $third->fresh()->status);
    }

    public function test_requester_who_is_also_an_approver_cannot_approve_their_own_request(): void
    {
        $transport = $this->pending($this->director);

        $this->actingAs($this->director)->post(route('transport.approve', $transport))->assertForbidden();
        $this->actingAs($this->head)->post(route('transport.approve', $transport))->assertRedirect();
    }

    public function test_dispatcher_delegated_approval_requires_a_note_and_records_the_real_approver(): void
    {
        $transport = $this->pending();

        $this->actingAs($this->dispatcher)->post(route('transport.approve', $transport), ['approval_authority' => 'CAMPUS_DIRECTOR_INSTRUCTION'])
            ->assertSessionHasErrors('approval_note');
        $this->actingAs($this->dispatcher)->post(route('transport.approve', $transport), ['approval_authority' => 'OWN_AUTHORITY'])
            ->assertSessionHasErrors('approval_authority');
        $this->assertSame(TransportStatus::PendingApproval, $transport->fresh()->status);

        $this->actingAs($this->dispatcher)->post(route('transport.approve', $transport), [
            'approval_authority' => 'CAMPUS_DIRECTOR_INSTRUCTION', 'approval_instructed_by' => 'Joey G. Escauso',
            'approval_note' => 'Approved as instructed via Messenger.',
        ])->assertRedirect();

        $fresh = $transport->fresh();
        $this->assertSame($this->dispatcher->id, $fresh->approved_by);
        $this->assertSame('CAMPUS_DIRECTOR_INSTRUCTION', $fresh->approval_authority->value);
        $this->assertSame('Joey G. Escauso', $fresh->approval_instructed_by);
        $this->assertSame(0, $fresh->attachments()->count(), 'Evidence is optional.');
        $this->actingAs($this->dispatcher)->get(route('transport.show', $fresh))->assertOk()
            ->assertSee('As instructed by Campus Director')->assertSee('Joey G. Escauso');
    }

    public function test_approval_evidence_is_optional_and_private_to_management(): void
    {
        $transport = $this->pending();
        $this->actingAs($this->dispatcher)->post(route('transport.approve', $transport), [
            'approval_authority' => 'FMO_HEAD_INSTRUCTION', 'approval_note' => 'Approved by text message.',
            'approval_evidence' => [UploadedFile::fake()->image('messenger.png', 800, 600)],
        ])->assertRedirect();
        $evidence = $transport->attachments()->where('purpose', TransportRequest::APPROVAL_EVIDENCE_PURPOSE)->sole();

        $this->actingAs($this->dispatcher)->get(route('routine-evidence.show', $evidence))->assertOk();
        $this->actingAs($this->director)->get(route('routine-evidence.show', $evidence))->assertOk();
        $this->actingAs($this->requester)->get(route('routine-evidence.show', $evidence))->assertForbidden();
        $this->actingAs($this->otherStaff)->get(route('routine-evidence.show', $evidence))->assertForbidden();
        auth()->logout();
        $this->get(route('routine-evidence.show', $evidence))->assertRedirect();
    }

    public function test_rejection_requires_a_reason_and_is_logged(): void
    {
        $transport = $this->pending();

        $this->actingAs($this->head)->post(route('transport.reject', $transport), [])->assertSessionHasErrors('rejection_reason');
        $this->actingAs($this->head)->post(route('transport.reject', $transport), ['rejection_reason' => 'Vehicle unavailable on requested date.'])->assertRedirect();

        $fresh = $transport->fresh();
        $this->assertSame(TransportStatus::Rejected, $fresh->status);
        $this->assertSame('Vehicle unavailable on requested date.', $fresh->rejection_reason);
        $this->assertSame(['SUBMITTED', 'REJECTED'], $fresh->events->pluck('event')->all());
        $this->actingAs($this->head)->post(route('transport.approve', $fresh))->assertForbidden();
    }

    public function test_assignment_requires_an_eligible_driver_and_active_vehicle(): void
    {
        $transport = $this->approved();
        $url = route('transport.assign', $transport);

        $this->actingAs($this->dispatcher)->post($url, ['driver_personnel_id' => $this->otherStaff->personnelProfile->id, 'inspectable_asset_id' => $this->van->id])
            ->assertSessionHasErrors('driver_personnel_id');
        $regular = InspectableAsset::create([
            'asset_code' => 'AC-1', 'asset_type_id' => AssetType::where('code', 'AC')->value('id'), 'asset_class' => 'EQUIPMENT', 'name' => 'Aircon',
            'campus_id' => $this->van->campus_id, 'building_id' => $this->van->building_id, 'inspection_frequency' => 'MONTHLY',
            'status' => 'ACTIVE', 'condition' => 'GOOD', 'lifecycle_status' => 'ACTIVE',
        ]);
        $this->actingAs($this->dispatcher)->post($url, ['driver_personnel_id' => $this->driver->id, 'inspectable_asset_id' => $regular->id])
            ->assertSessionHasErrors('inspectable_asset_id');
        $this->van->update(['lifecycle_status' => 'UNDER_REPAIR']);
        $this->actingAs($this->dispatcher)->post($url, ['driver_personnel_id' => $this->driver->id, 'inspectable_asset_id' => $this->van->id])
            ->assertSessionHasErrors('inspectable_asset_id');
        $this->van->update(['lifecycle_status' => 'ACTIVE']);
        $this->actingAs($this->requester)->post($url, ['driver_personnel_id' => $this->driver->id, 'inspectable_asset_id' => $this->van->id])->assertForbidden();

        $this->actingAs($this->dispatcher)->post($url, ['driver_personnel_id' => $this->driver->id, 'inspectable_asset_id' => $this->van->id])->assertRedirect();
        $fresh = $transport->fresh();
        $this->assertSame(TransportStatus::Assigned, $fresh->status);
        $this->assertSame($this->van->id, $fresh->inspectable_asset_id);
        $this->assertSame(1, $this->driverUser->notifications()->count());
    }

    public function test_overlapping_assignments_for_the_same_vehicle_or_driver_are_rejected(): void
    {
        $first = $this->assigned();
        $second = $this->approved();

        $this->actingAs($this->dispatcher)->post(route('transport.assign', $second), ['driver_personnel_id' => $this->driver->id, 'inspectable_asset_id' => $this->van->id])
            ->assertSessionHasErrors();
        $this->assertSame(TransportStatus::Approved, $second->fresh()->status);

        $later = $this->approved(['travel_date' => '2026-11-13']);
        $this->actingAs($this->dispatcher)->post(route('transport.assign', $later), ['driver_personnel_id' => $this->driver->id, 'inspectable_asset_id' => $this->van->id])->assertRedirect();
        $this->assertSame(TransportStatus::Assigned, $first->fresh()->status);
    }

    public function test_only_the_assigned_driver_can_open_and_update_the_trip(): void
    {
        $transport = $this->assigned();

        $this->actingAs($this->driverUser)->get(route('transport.show', $transport))->assertOk()->assertSee('Save Update')->assertDontSee('Start Trip');
        $this->actingAs($this->otherStaff)->get(route('transport.show', $transport))->assertForbidden();
        $this->actingAs($this->otherStaff)->post(route('transport.trip.update', $transport), ['odometer_before' => 1])->assertForbidden();
        $this->actingAs($this->otherRequester)->get(route('transport.show', $transport))->assertForbidden();
        $this->actingAs($this->requester)->get(route('transport.show', $transport))->assertOk()->assertDontSee('Save Update');
        $this->actingAs($this->requester)->post(route('transport.trip.update', $transport), ['odometer_before' => 1])->assertForbidden();
        $this->assertNull($transport->fresh()->odometer_before);
    }

    public function test_driver_saves_partial_details_without_completing(): void
    {
        $transport = $this->assigned();

        $this->actingAs($this->driverUser)->post(route('transport.trip.update', $transport), ['odometer_before' => 48250, 'fuel_before' => 'THREE_QUARTERS'])->assertRedirect();
        $fresh = $transport->fresh();
        $this->assertSame(TransportStatus::Assigned, $fresh->status);
        $this->assertSame(48250, $fresh->odometer_before);
        $this->assertSame('THREE_QUARTERS', $fresh->fuel_before->value);
        $this->assertNull($fresh->odometer_after);
        $this->assertNull($fresh->distance_km);

        $this->actingAs($this->driverUser)->post(route('transport.trip.update', $transport), ['actual_departed_at' => '2026-11-12T08:15', 'odometer_after' => 48332])->assertRedirect();
        $this->assertSame(82, $transport->fresh()->distance_km);
        $this->assertSame(48250, $transport->fresh()->odometer_before, 'Fields not submitted are left as saved.');
    }

    public function test_completion_requires_every_final_field(): void
    {
        $transport = $this->assigned();

        $response = $this->actingAs($this->driverUser)->post(route('transport.trip.complete', $transport), ['odometer_before' => 48250]);
        $response->assertSessionHasErrors(['actual_departed_at', 'actual_arrived_at', 'odometer_after', 'fuel_before', 'fuel_after']);
        $this->assertSame(TransportStatus::Assigned, $transport->fresh()->status);
        $this->assertSame(48250, $this->van->fresh()->current_odometer_km);
    }

    public function test_completion_calculates_distance_updates_the_vehicle_and_appears_in_history(): void
    {
        $transport = $this->assigned();

        $this->actingAs($this->driverUser)->post(route('transport.trip.complete', $transport), $this->completion([
            'trip_documents' => [UploadedFile::fake()->create('trip-ticket.pdf', 90, 'application/pdf')],
        ]))->assertRedirect();

        $fresh = $transport->fresh();
        $this->assertSame(TransportStatus::Completed, $fresh->status);
        $this->assertSame(82, $fresh->distance_km);
        $this->assertSame($this->driver->id, $fresh->driver_personnel_id);
        $this->assertSame(48332, $this->van->fresh()->current_odometer_km);
        $this->assertSame(1, $this->requester->notifications()->where('data->event_key', 'TRANSPORT_COMPLETED')->count());
        $this->actingAs($this->driverUser)->post(route('transport.trip.update', $fresh), ['odometer_after' => 49000])->assertForbidden();

        $this->actingAs($this->head)->get(route('assets.show', $this->van))->assertOk()
            ->assertSee('Travel History')->assertSee('Del Carmen Campus → Dapa')->assertSee('82 km')->assertSee('3/4 → 1/2');
        $this->actingAs($this->head)->get(route('transport.show', $fresh))->assertOk()
            ->assertSee('Distance travelled')->assertSee('8 h 27 min')->assertSee('About')->assertSee('25%', false);
        $this->actingAs($this->head)->get(route('transport.history'))->assertOk()->assertSee($fresh->request_number)->assertSee('82 km');
        $history = collect(app(AssetMaintenanceHistoryService::class)->paginate($this->van)->items());
        $this->assertSame(['TRIP_COMPLETED'], $history->pluck('type')->all());
        $this->assertStringContainsString('Distance: 82 km', $history->first()['summary']);
    }

    public function test_invalid_odometer_and_dates_are_rejected_but_overnight_trips_are_allowed(): void
    {
        $transport = $this->assigned();

        $this->actingAs($this->driverUser)->post(route('transport.trip.complete', $transport), $this->completion(['odometer_after' => 48000]))->assertSessionHasErrors('odometer_after');
        $this->actingAs($this->driverUser)->post(route('transport.trip.complete', $transport), $this->completion([
            'actual_departed_at' => '2026-11-12T16:00', 'actual_arrived_at' => '2026-11-12T08:00',
        ]))->assertSessionHasErrors('actual_arrived_at');
        $this->assertSame(TransportStatus::Assigned, $transport->fresh()->status);

        $this->actingAs($this->driverUser)->post(route('transport.trip.complete', $transport), $this->completion([
            'actual_departed_at' => '2026-11-12T22:00', 'actual_arrived_at' => '2026-11-13T03:30',
        ]))->assertSessionHasNoErrors();
        $this->assertSame(TransportStatus::Completed, $transport->fresh()->status);
    }

    public function test_a_stale_trip_never_lowers_the_vehicle_odometer(): void
    {
        $this->van->update(['current_odometer_km' => 60000]);
        $transport = $this->assigned();

        $this->actingAs($this->driverUser)->post(route('transport.trip.complete', $transport), $this->completion())->assertRedirect();

        $this->assertSame(TransportStatus::Completed, $transport->fresh()->status);
        $this->assertSame(60000, $this->van->fresh()->current_odometer_km);
    }

    public function test_trip_documents_are_private_to_the_driver_and_management(): void
    {
        $transport = $this->assigned();
        $this->actingAs($this->driverUser)->post(route('transport.trip.update', $transport), [
            'trip_documents' => [UploadedFile::fake()->create('ticket.pdf', 50, 'application/pdf')],
        ])->assertRedirect();
        $this->actingAs($this->requester)->post(route('transport.store'), $this->payload(['documents' => [UploadedFile::fake()->create('memo.pdf', 10, 'application/pdf')]]));
        $ticket = $transport->attachments()->where('purpose', TransportRequest::TRIP_DOCUMENT_PURPOSE)->sole();
        $memo = TransportRequest::where('id', '!=', $transport->id)->firstOrFail()->attachments()->sole();

        $this->actingAs($this->driverUser)->get(route('routine-evidence.show', $ticket))->assertOk();
        $this->actingAs($this->head)->get(route('routine-evidence.show', $ticket))->assertOk();
        $this->actingAs($this->requester)->get(route('routine-evidence.show', $ticket))->assertForbidden();
        $this->actingAs($this->otherRequester)->get(route('routine-evidence.show', $ticket))->assertForbidden();
        $this->actingAs($this->requester)->get(route('routine-evidence.show', $memo))->assertOk();
        $this->actingAs($this->otherRequester)->get(route('routine-evidence.show', $memo))->assertForbidden();
        $this->actingAs($this->otherStaff)->get(route('routine-evidence.show', $memo))->assertForbidden();
    }

    public function test_requests_are_scoped_to_their_owner(): void
    {
        $mine = $this->pending();
        $theirs = $this->pending($this->otherRequester);

        $this->actingAs($this->requester)->get(route('transport.show', $theirs))->assertForbidden();
        $this->actingAs($this->requester)->get(route('transport.mine'))->assertOk()->assertSee($mine->request_number)->assertDontSee($theirs->request_number);
        $this->actingAs($this->requester)->get(route('transport.index'))->assertForbidden();
        $this->actingAs($this->requester)->post(route('transport.cancel', $theirs))->assertForbidden();
        $this->actingAs($this->head)->get(route('transport.index'))->assertOk()->assertSee($mine->request_number)->assertSee($theirs->request_number);
        $this->actingAs($this->requester)->post(route('transport.cancel', $mine))->assertRedirect();
        $this->assertSame(TransportStatus::Cancelled, $mine->fresh()->status);
    }

    public function test_lists_are_paginated_and_filterable(): void
    {
        foreach (range(1, 22) as $index) {
            TransportRequest::create($this->payload(['destination' => $index === 22 ? 'General Luna' : 'Dapa']) + [
                'request_number' => sprintf('TR-2026-%04d', $index), 'requester_id' => $this->requester->id, 'status' => TransportStatus::PendingApproval,
            ]);
        }

        $this->actingAs($this->head)->get(route('transport.index'))->assertOk()->assertViewHas('requests', fn ($page) => $page->perPage() === 20 && $page->total() === 22);
        $this->actingAs($this->head)->get(route('transport.index', ['destination' => 'Luna', 'per_page' => 50]))->assertOk()
            ->assertViewHas('requests', fn ($page) => $page->total() === 1 && $page->perPage() === 50);
    }

    public function test_vehicle_issue_can_become_one_corrective_work_order(): void
    {
        $transport = $this->assigned();
        $this->actingAs($this->driverUser)->post(route('transport.trip.complete', $transport), $this->completion([
            'vehicle_issue_observed' => 1, 'vehicle_issue_remarks' => 'Abnormal brake noise observed.',
            'issue_photos' => [UploadedFile::fake()->image('brake.jpg', 600, 400)],
        ]))->assertRedirect();

        $this->actingAs($this->driverUser)->post(route('transport.corrective-work-order', $transport), ['subject' => 'Brake noise'])->assertForbidden();
        $this->actingAs($this->dispatcher)->post(route('transport.corrective-work-order', $transport), ['subject' => 'Brake noise'])->assertRedirect();
        $this->actingAs($this->dispatcher)->post(route('transport.corrective-work-order', $transport), ['subject' => 'Brake noise'])->assertRedirect();

        $this->assertSame(1, WorkOrder::where('transport_request_id', $transport->id)->count());
        $this->assertSame($this->van->id, WorkOrder::where('transport_request_id', $transport->id)->value('inspectable_asset_id'));
    }

    public function test_vehicle_issue_requires_remarks(): void
    {
        $transport = $this->assigned();

        $this->actingAs($this->driverUser)->post(route('transport.trip.update', $transport), ['vehicle_issue_observed' => 1])->assertSessionHasErrors('vehicle_issue_remarks');
    }

    public function test_api_supports_the_full_request_lifecycle(): void
    {
        $token = function (User $user) {
            app('auth')->forgetGuards();

            return $this->withToken($user->createToken('t')->plainTextToken);
        };

        $id = $token($this->requester)->postJson('/api/v1/transport-requests', $this->payload())->assertCreated()->json('data.id');
        $token($this->requester)->postJson("/api/v1/transport-requests/{$id}/approve")->assertForbidden();
        $token($this->director)->postJson("/api/v1/transport-requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $token($this->dispatcher)->postJson("/api/v1/transport-requests/{$id}/assign", ['driver_personnel_id' => $this->driver->id, 'inspectable_asset_id' => $this->van->id])
            ->assertOk()->assertJsonPath('data.status', 'ASSIGNED');
        $token($this->otherStaff)->getJson("/api/v1/transport-requests/{$id}")->assertForbidden();
        $token($this->driverUser)->patchJson("/api/v1/transport-requests/{$id}/trip", ['odometer_before' => 48250])->assertOk()->assertJsonPath('data.odometer_before', 48250);
        $token($this->driverUser)->postJson("/api/v1/transport-requests/{$id}/complete", ['odometer_before' => 48250])->assertUnprocessable();
        $token($this->driverUser)->postJson("/api/v1/transport-requests/{$id}/complete", $this->completion())->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.distance_km', 82);
        $token($this->requester)->getJson('/api/v1/transport-requests')->assertOk()->assertJsonCount(1, 'data');
        $token($this->otherRequester)->getJson('/api/v1/transport-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'purpose' => 'Official meeting', 'origin' => 'SNSU Del Carmen Campus', 'destination' => 'Dapa', 'travel_date' => '2026-11-12',
            'expected_departure_at' => '2026-11-12T08:00', 'expected_return_at' => '2026-11-12T17:00', 'passenger_count' => 4,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function completion(array $overrides = []): array
    {
        return $overrides + [
            'actual_departed_at' => '2026-11-12T08:15', 'actual_arrived_at' => '2026-11-12T16:42', 'odometer_before' => 48250, 'odometer_after' => 48332,
            'fuel_before' => 'THREE_QUARTERS', 'fuel_after' => 'HALF',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function pending(?User $by = null, array $overrides = []): TransportRequest
    {
        $this->actingAs($by ?? $this->requester)->post(route('transport.store'), $this->payload($overrides))->assertRedirect();

        return TransportRequest::latest('created_at')->latest('id')->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function approved(array $overrides = []): TransportRequest
    {
        $transport = $this->pending(null, $overrides);
        $this->actingAs($this->head)->post(route('transport.approve', $transport))->assertRedirect();

        return $transport->fresh();
    }

    private function assigned(): TransportRequest
    {
        $transport = $this->approved();
        $this->actingAs($this->dispatcher)->post(route('transport.assign', $transport), ['driver_personnel_id' => $this->driver->id, 'inspectable_asset_id' => $this->van->id])->assertRedirect();

        return $transport->fresh();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'APPROVED']);
        $user->assignRole($role);

        return $user;
    }

    private function person(User $user, string $identifier, bool $driver): FmoPersonnel
    {
        $person = FmoPersonnel::create(['user_id' => $user->id, 'personnel_identifier' => $identifier, 'designation' => $driver ? 'Campus Driver' : 'Facilities Staff', 'personnel_status' => 'ACTIVE']);
        if ($driver) {
            $person->skills()->attach(Skill::where('name', 'Driver')->value('id'), ['is_primary' => true]);
        }

        return $person;
    }
}
