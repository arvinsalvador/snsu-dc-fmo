<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\ProcessedClientOperation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Services\StoredFileTransaction;
use App\Services\WorkOrderAssignmentService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReleaseHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'APPROVED']);
        $user->assignRole($role);

        return $user;
    }

    private function order(User $requester, WorkOrderStatus $status = WorkOrderStatus::Submitted): WorkOrder
    {
        $campus = Campus::firstOrCreate(['code' => 'DC'], ['name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::firstOrCreate(['campus_id' => $campus->id, 'name' => 'Main'], ['is_active' => true]);
        $category = WorkOrderCategory::firstOrCreate(['name' => 'Electrical'], ['is_active' => true]);

        return WorkOrder::create([
            'work_order_number' => 'WO-2026-'.str_pad((string) (WorkOrder::count() + 1), 6, '0', STR_PAD_LEFT),
            'requester_id' => $requester->id, 'campus_id' => $campus->id, 'building_id' => $building->id,
            'work_order_category_id' => $category->id, 'subject' => 'Repair light', 'description' => 'Light is broken',
            'urgency' => 'NORMAL', 'status' => $status, 'submitted_at' => now(),
        ]);
    }

    public function test_private_attachment_requires_parent_authorization_and_uses_generated_path(): void
    {
        Storage::fake('local');
        $owner = $this->user('Requester');
        $other = $this->user('Requester');
        $staff = $this->user('FMO Staff');
        $head = $this->user('FMO Head');
        $order = $this->order($owner);
        $file = UploadedFile::fake()->image('tricky-name.jpg');
        $path = $file->store('work-orders/'.$order->id, 'local');
        $attachment = $order->attachments()->create([
            'uploaded_by' => $owner->id, 'purpose' => 'REQUEST_INITIAL', 'original_filename' => '../../config/app.php',
            'stored_path' => $path, 'mime_type' => 'image/jpeg', 'file_size' => $file->getSize(),
        ]);
        $this->assertStringNotContainsString('config/app.php', $path);
        Storage::disk('local')->assertExists($path);
        $url = "/work-orders/{$order->id}/attachments/{$attachment->id}";
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->actingAs($staff)->get($url)->assertForbidden();
        $this->actingAs($owner)->get($url)->assertOk();
        $this->actingAs($head)->get($url)->assertOk();
    }

    public function test_work_order_api_collection_is_paginated_and_requester_scoped(): void
    {
        $owner = $this->user('Requester');
        $other = $this->user('Requester');
        for ($i = 0; $i < 55; $i++) {
            $this->order($owner);
        }
        $hidden = $this->order($other);
        $token = $owner->createToken('release-test')->plainTextToken;
        app('auth')->forgetGuards();
        $response = $this->withToken($token)->getJson('/api/v1/work-orders')->assertOk()
            ->assertJsonPath('current_page', 1)->assertJsonPath('last_page', 2);
        $this->assertCount(50, $response->json('data'));
        $this->assertStringNotContainsString($hidden->id, $response->getContent());
        $this->withToken($token)->getJson('/api/v1/work-orders?page=2')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_mobile_evidence_limit_applies_across_repeated_upload_requests(): void
    {
        Storage::fake('local');
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $person = FmoPersonnel::create(['user_id' => $staff->id, 'personnel_identifier' => 'P-'.$staff->id, 'designation' => 'Technician', 'personnel_status' => 'ACTIVE']);
        $order = $this->order($this->user('Requester'), WorkOrderStatus::Approved);
        app(WorkOrderAssignmentService::class)->add($order, $head, [$person->id]);
        $order->forceFill(['status' => WorkOrderStatus::ForAssessment])->save();
        $assessment = $order->assessments()->create(['work_order_assignment_id' => $order->activeAssignments()->firstOrFail()->id,
            'fmo_personnel_id' => $person->id, 'outcome' => 'READY_FOR_WORK', 'findings' => 'Safe to repair', 'assessed_at' => now()]);
        $token = $staff->createToken('release-media')->plainTextToken;
        for ($i = 0; $i < config('work_orders.attachments.max_count'); $i++) {
            app('auth')->forgetGuards();
            $this->withToken($token)->post('/api/v1/mobile/media', [
                'client_operation_id' => (string) Str::uuid(), 'target_kind' => 'ASSESSMENT', 'target_id' => $assessment->id,
                'file' => UploadedFile::fake()->image("photo-{$i}.jpg"),
            ])->assertOk();
        }
        app('auth')->forgetGuards();
        $this->withToken($token)->post('/api/v1/mobile/media', [
            'client_operation_id' => (string) Str::uuid(), 'target_kind' => 'ASSESSMENT', 'target_id' => $assessment->id,
            'file' => UploadedFile::fake()->image('extra.jpg'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(config('work_orders.attachments.max_count'), $assessment->attachments()->count());
    }

    public function test_unlisted_browser_origin_gets_no_cors_permission(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://unlisted.example', 'Access-Control-Request-Method' => 'GET',
        ])->options('/api/v1/health');
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_inactive_assignee_cannot_submit_an_assessment(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $person = FmoPersonnel::create(['user_id' => $staff->id, 'personnel_identifier' => 'P-'.$staff->id,
            'designation' => 'Technician', 'personnel_status' => 'ACTIVE']);
        $order = $this->order($this->user('Requester'), WorkOrderStatus::Approved);
        app(WorkOrderAssignmentService::class)->add($order, $head, [$person->id]);
        $order->forceFill(['status' => WorkOrderStatus::ForAssessment])->save();
        $person->update(['personnel_status' => 'INACTIVE', 'archived_at' => now()]);
        $token = $staff->createToken('inactive-assessment')->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($token)->postJson("/api/v1/work-orders/{$order->id}/assessments", [
            'outcome' => 'READY_FOR_WORK', 'findings' => 'Attempt while inactive',
        ])->assertForbidden();
        $this->assertDatabaseCount('work_order_assessments', 0);
    }

    public function test_private_files_from_nested_transactions_are_removed_if_outer_database_work_fails(): void
    {
        Storage::fake('local');
        $requester = $this->user('Requester');
        $order = $this->order($requester);
        $files = app(StoredFileTransaction::class);
        $storedPath = null;

        try {
            $files->run(function () use ($files, $order, $requester, &$storedPath): void {
                $files->run(function () use ($files, $order, $requester, &$storedPath): void {
                    $upload = UploadedFile::fake()->image('evidence.jpg');
                    $storedPath = $files->store($upload, 'work-orders/'.$order->id);
                    $order->attachments()->create([
                        'uploaded_by' => $requester->id, 'purpose' => 'REQUEST_INITIAL',
                        'original_filename' => 'evidence.jpg', 'stored_path' => $storedPath,
                        'mime_type' => 'image/jpeg', 'file_size' => $upload->getSize(),
                    ]);
                });

                throw new \RuntimeException('Later transaction work failed.');
            });
            $this->fail('The outer transaction should have failed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Later transaction work failed.', $exception->getMessage());
        }

        $this->assertNotNull($storedPath);
        Storage::disk('local')->assertMissing($storedPath);
        $this->assertDatabaseMissing('work_order_attachments', ['stored_path' => $storedPath]);
    }

    public function test_personnel_with_assignment_history_cannot_be_deleted(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $personnel = FmoPersonnel::create(['user_id' => $staff->id, 'personnel_identifier' => 'P-'.$staff->id,
            'designation' => 'Technician', 'personnel_status' => 'ACTIVE']);
        $order = $this->order($this->user('Requester'), WorkOrderStatus::Approved);
        app(WorkOrderAssignmentService::class)->add($order, $head, [$personnel->id]);

        $this->actingAs($head)->delete('/personnel/'.$personnel->id)->assertStatus(409);
        $this->assertDatabaseHas('fmo_personnel', ['id' => $personnel->id]);
    }

    public function test_mobile_media_is_removed_when_idempotency_ledger_write_fails(): void
    {
        Storage::fake('local');
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $person = FmoPersonnel::create(['user_id' => $staff->id, 'personnel_identifier' => 'P-'.$staff->id,
            'designation' => 'Technician', 'personnel_status' => 'ACTIVE']);
        $order = $this->order($this->user('Requester'), WorkOrderStatus::Approved);
        app(WorkOrderAssignmentService::class)->add($order, $head, [$person->id]);
        $order->forceFill(['status' => WorkOrderStatus::ForAssessment])->save();
        $assessment = $order->assessments()->create(['work_order_assignment_id' => $order->activeAssignments()->firstOrFail()->id,
            'fmo_personnel_id' => $person->id, 'outcome' => 'READY_FOR_WORK', 'findings' => 'Safe', 'assessed_at' => now()]);
        ProcessedClientOperation::creating(function (): void {
            throw new \RuntimeException('Simulated ledger failure');
        });
        $token = $staff->createToken('rollback-media')->plainTextToken;
        app('auth')->forgetGuards();

        $this->withToken($token)->post('/api/v1/mobile/media', [
            'client_operation_id' => (string) Str::uuid(), 'target_kind' => 'ASSESSMENT', 'target_id' => $assessment->id,
            'file' => UploadedFile::fake()->image('rollback.jpg'),
        ])->assertStatus(500);
        $this->assertSame([], Storage::disk('local')->allFiles('work-orders/'.$order->id.'/mobile'));
        $this->assertDatabaseCount('work_order_attachments', 0);
    }

    public function test_inactive_personnel_cannot_read_still_assigned_field_tasks(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff');
        $person = FmoPersonnel::create(['user_id' => $staff->id, 'personnel_identifier' => 'P-'.$staff->id,
            'designation' => 'Technician', 'personnel_status' => 'ACTIVE']);
        $order = $this->order($this->user('Requester'), WorkOrderStatus::Approved);
        app(WorkOrderAssignmentService::class)->add($order, $head, [$person->id]);
        $person->update(['personnel_status' => 'INACTIVE', 'archived_at' => now()]);

        $this->actingAs($staff)->get('/work-orders/'.$order->id)->assertForbidden();
        $this->actingAs($staff)->get('/reports/work-orders')->assertOk()->assertDontSee($order->work_order_number);
        $this->actingAs($staff)->get('/reports/work-orders/'.$order->id.'/print')->assertForbidden();
        $token = $staff->createToken('inactive-read')->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/work-orders/assigned')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/work-orders/'.$order->id)->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/mobile/bootstrap')->assertForbidden();
    }
}
