<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\Campus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Services\EvidenceMediaService;
use App\Services\StoredFileTransaction;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class WorkOrderResubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        Storage::fake('local');
    }

    private function user(string $role = 'Requester'): User
    {
        $user = User::factory()->create(['status' => 'APPROVED']);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, mixed> */
    private function fields(array $overrides = []): array
    {
        $campus = Campus::firstOrCreate(['code' => 'DC'], ['name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::firstOrCreate(['campus_id' => $campus->id, 'name' => 'Academic'], ['is_active' => true]);
        $category = WorkOrderCategory::firstOrCreate(['name' => 'Electrical'], ['is_active' => true]);

        return array_merge([
            'campus_id' => $campus->id,
            'building_id' => $building->id,
            'work_order_category_id' => $category->id,
            'subject' => 'Broken light',
            'description' => 'The light needs repair.',
            'urgency' => 'NORMAL',
        ], $overrides);
    }

    private function needsInformation(User $requester, User $reviewer, string $message = 'Please upload a clearer photo.', array $initial = []): WorkOrder
    {
        $this->actingAs($requester)->post('/work-orders', $this->fields($initial))->assertRedirect();
        $order = WorkOrder::latest('submitted_at')->firstOrFail();
        $this->actingAs($reviewer)->post("/work-orders/{$order->id}/workflow/begin-screening")->assertRedirect();
        $this->actingAs($reviewer)->post("/work-orders/{$order->id}/workflow/request-information", [
            'requester_message' => $message,
            'internal_note' => 'Internal screening note.',
        ])->assertRedirect();

        return $order->fresh();
    }

    public function test_requester_can_resubmit_with_a_new_private_image(): void
    {
        $requester = $this->user();
        $reviewer = $this->user('FMO Dispatcher');
        $order = $this->needsInformation($requester, $reviewer, initial: [
            'attachments' => [UploadedFile::fake()->image('original.jpg', 800, 600)],
        ]);
        $existing = $order->attachments()->sole();

        $response = $this->actingAs($requester)->post("/work-orders/{$order->id}/workflow/resubmit", $this->fields([
            'subject' => 'Broken light in Room 12',
            'requester_message' => 'I confirmed the room and added a clearer photo.',
            'attachments' => [UploadedFile::fake()->image('clearer.jpg', 1200, 800)],
        ]));

        $response->assertRedirect(route('work-orders.show', $order));
        $this->assertSame(WorkOrderStatus::ForScreening, $order->fresh()->status);
        $this->assertSame(2, $order->attachments()->count());
        $attachment = $order->attachments()->where('original_filename', 'clearer.jpg')->sole();
        Storage::disk('local')->assertExists($existing->stored_path);
        Storage::disk('local')->assertExists($existing->thumbnail_path);
        Storage::disk('local')->assertExists($attachment->stored_path);
        Storage::disk('local')->assertExists($attachment->thumbnail_path);
        $this->actingAs($requester)->get("/work-orders/{$order->id}")
            ->assertOk()
            ->assertSee('clearer.jpg')
            ->assertSee('data-gallery="requester-evidence"', false);
        $this->get("/work-orders/{$order->id}/attachments/{$attachment->id}")->assertOk();
    }

    public function test_unprocessable_image_returns_a_friendly_error_without_mutation(): void
    {
        config()->set('work_orders.attachments.image_max_pixels', 100);
        $requester = $this->user();
        $reviewer = $this->user('FMO Dispatcher');
        $order = $this->needsInformation($requester, $reviewer);
        $eventCount = $order->workflowEvents()->count();

        $this->actingAs($requester)
            ->from("/work-orders/{$order->id}/edit")
            ->post("/work-orders/{$order->id}/workflow/resubmit", $this->fields([
                'requester_message' => 'I attached a clearer image.',
                'attachments' => [UploadedFile::fake()->image('unsupported.jpg', 20, 20)],
            ]))
            ->assertRedirect("/work-orders/{$order->id}/edit")
            ->assertSessionHasErrors([
                'attachments' => 'This image has unsupported pixel dimensions. Choose a photo no larger than 12,000 pixels on either side.',
            ]);

        $this->assertSame(WorkOrderStatus::NeedsInformation, $order->fresh()->status);
        $this->assertSame($eventCount, $order->workflowEvents()->count());
        $this->assertDatabaseCount('work_order_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_text_only_resubmission_preserves_existing_attachments_and_records_response(): void
    {
        $requester = $this->user();
        $reviewer = $this->user('FMO Dispatcher');
        $order = $this->needsInformation($requester, $reviewer, initial: [
            'attachments' => [UploadedFile::fake()->image('original.jpg', 800, 600)],
        ]);
        $existing = $order->attachments()->sole();

        $response = $this->actingAs($requester)->post("/work-orders/{$order->id}/workflow/resubmit", $this->fields([
            'description' => 'The light in Room 12 needs repair.',
            'requester_message' => 'I confirmed that the affected location is Room 12.',
        ]));

        $response->assertRedirect(route('work-orders.show', $order))
            ->assertSessionHas('success', 'Your Work Order has been updated and resubmitted to FMO for review.');
        $this->assertSame(WorkOrderStatus::ForScreening, $order->fresh()->status);
        $this->assertSame('The light in Room 12 needs repair.', $order->fresh()->description);
        $this->assertSame(1, $order->attachments()->count());
        Storage::disk('local')->assertExists($existing->stored_path);
        Storage::disk('local')->assertExists($existing->thumbnail_path);
        $this->assertDatabaseHas('work_order_workflow_events', [
            'work_order_id' => $order->id,
            'actor_id' => $requester->id,
            'action' => 'RESUBMIT',
            'from_status' => WorkOrderStatus::NeedsInformation->value,
            'to_status' => WorkOrderStatus::ForScreening->value,
            'requester_message' => 'I confirmed that the affected location is Room 12.',
        ]);
    }

    public function test_multiple_images_use_the_normalized_private_media_pipeline(): void
    {
        $requester = $this->user();
        $reviewer = $this->user('FMO Dispatcher');
        $order = $this->needsInformation($requester, $reviewer);

        $this->actingAs($requester)->post("/work-orders/{$order->id}/workflow/resubmit", $this->fields([
            'requester_message' => 'I added two clear photos of the affected area.',
            'attachments' => [
                UploadedFile::fake()->image('wide.jpg', 2400, 1200),
                UploadedFile::fake()->image('detail.png', 600, 900),
            ],
        ]))->assertRedirect(route('work-orders.show', $order));

        $attachments = $order->attachments()->get();
        $this->assertCount(2, $attachments);
        foreach ($attachments as $attachment) {
            Storage::disk('local')->assertExists($attachment->stored_path);
            Storage::disk('local')->assertExists($attachment->thumbnail_path);
            $this->assertLessThanOrEqual(1920, max($attachment->width, $attachment->height));
            $this->assertLessThanOrEqual(480, max($attachment->thumbnail_width, $attachment->thumbnail_height));
        }
    }

    public function test_failure_after_file_staging_rolls_back_files_database_workflow_and_status(): void
    {
        $requester = $this->user();
        $reviewer = $this->user('FMO Dispatcher');
        $order = $this->needsInformation($requester, $reviewer);
        $originalSubject = $order->subject;
        $eventCount = $order->workflowEvents()->count();

        $this->app->instance(EvidenceMediaService::class, new class(app(StoredFileTransaction::class)) extends EvidenceMediaService
        {
            public function store(UploadedFile $file, string $directory, string $field = 'evidence'): array
            {
                parent::store($file, $directory, $field);

                throw new RuntimeException('Forced failure after private file staging.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($requester)->post("/work-orders/{$order->id}/workflow/resubmit", $this->fields([
                'subject' => 'This change must roll back',
                'requester_message' => 'This response must roll back.',
                'attachments' => [UploadedFile::fake()->image('rollback.jpg', 900, 600)],
            ]));
            $this->fail('The forced media failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced failure after private file staging.', $exception->getMessage());
        }

        $fresh = $order->fresh();
        $this->assertSame(WorkOrderStatus::NeedsInformation, $fresh->status);
        $this->assertSame($originalSubject, $fresh->subject);
        $this->assertSame($eventCount, $fresh->workflowEvents()->count());
        $this->assertDatabaseCount('work_order_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_only_the_requester_in_the_correction_state_can_resubmit(): void
    {
        $requester = $this->user();
        $other = $this->user();
        $reviewer = $this->user('FMO Dispatcher');
        $order = $this->needsInformation($requester, $reviewer);

        $this->actingAs($other)->post("/work-orders/{$order->id}/workflow/resubmit", $this->fields([
            'requester_message' => 'Unauthorized response.',
        ]))->assertForbidden();
        $this->assertSame(WorkOrderStatus::NeedsInformation, $order->fresh()->status);

        $order->forceFill(['status' => WorkOrderStatus::Approved])->save();
        $this->actingAs($requester)->post("/work-orders/{$order->id}/workflow/resubmit", $this->fields([
            'requester_message' => 'Stale response.',
        ]))->assertStatus(409);
        $this->assertSame(WorkOrderStatus::Approved, $order->fresh()->status);
        $this->assertDatabaseMissing('work_order_workflow_events', ['requester_message' => 'Stale response.']);
    }

    public function test_clarification_is_prominent_on_detail_and_edit_while_internal_notes_remain_private(): void
    {
        $requester = $this->user();
        $reviewer = $this->user('FMO Dispatcher');
        $message = 'Please upload a clearer image and confirm the exact room.';
        $order = $this->needsInformation($requester, $reviewer, $message);

        $this->actingAs($requester)->get("/work-orders/{$order->id}")
            ->assertOk()
            ->assertSee('Additional Information Required')
            ->assertSee('Requested information')
            ->assertSee($message)
            ->assertSee('Additional Information Requested')
            ->assertSee($reviewer->name)
            ->assertDontSee('Internal screening note.');

        $this->actingAs($requester)->get("/work-orders/{$order->id}/edit")
            ->assertOk()
            ->assertSee('Additional Information Requested')
            ->assertSee($message)
            ->assertSee('Response to FMO')
            ->assertSee('Resubmitting…')
            ->assertDontSee('Internal screening note.');
    }

    public function test_validation_failure_does_not_change_workflow_or_history(): void
    {
        $requester = $this->user();
        $reviewer = $this->user('FMO Dispatcher');
        $order = $this->needsInformation($requester, $reviewer);
        $eventCount = $order->workflowEvents()->count();

        $this->actingAs($requester)
            ->from("/work-orders/{$order->id}/edit")
            ->post("/work-orders/{$order->id}/workflow/resubmit", $this->fields([
                'requester_message' => '',
            ]))
            ->assertRedirect("/work-orders/{$order->id}/edit")
            ->assertSessionHasErrors('requester_message');

        $this->actingAs($requester)
            ->from("/work-orders/{$order->id}/edit")
            ->post("/work-orders/{$order->id}/workflow/resubmit", $this->fields([
                'subject' => '',
                'requester_message' => 'The corrected room is Room 12.',
            ]))
            ->assertRedirect("/work-orders/{$order->id}/edit")
            ->assertSessionHasErrors('subject')
            ->assertSessionHasInput('description', 'The light needs repair.');

        $this->assertSame(WorkOrderStatus::NeedsInformation, $order->fresh()->status);
        $this->assertSame($eventCount, $order->workflowEvents()->count());
        $this->assertDatabaseCount('work_order_attachments', 0);
    }
}
