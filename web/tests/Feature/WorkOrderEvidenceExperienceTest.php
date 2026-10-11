<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Services\WorkOrderAssignmentService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkOrderEvidenceExperienceTest extends TestCase
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
        $campus = Campus::firstOrCreate(['code' => 'DC'], ['name' => 'Del Carmen Campus', 'is_active' => true]);
        $building = Building::firstOrCreate(['campus_id' => $campus->id, 'name' => 'Academic Building'], ['is_active' => true]);
        $category = WorkOrderCategory::firstOrCreate(['name' => 'Electrical'], ['is_active' => true]);

        return WorkOrder::create([
            'work_order_number' => 'WO-2026-'.str_pad((string) (WorkOrder::count() + 1), 6, '0', STR_PAD_LEFT),
            'requester_id' => $requester->id,
            'campus_id' => $campus->id,
            'building_id' => $building->id,
            'work_order_category_id' => $category->id,
            'subject' => 'Defective electrical outlet',
            'description' => 'The outlet sparks when equipment is connected.',
            'urgency' => 'URGENT',
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }

    public function test_detail_is_lifecycle_first_role_aware_and_safe_when_empty(): void
    {
        $requester = $this->user('Requester');
        $head = $this->user('FMO Head');
        $oversight = $this->user('Director for Instruction');
        $outsider = $this->user('Requester');
        $staff = $this->user('FMO Staff');
        $person = FmoPersonnel::create(['user_id' => $staff->id, 'designation' => 'Electrician', 'personnel_status' => 'ACTIVE']);
        $order = $this->order($requester, WorkOrderStatus::Approved);

        $this->actingAs($requester)->get("/work-orders/{$order->id}")->assertOk()
            ->assertSeeInOrder(['Current status', 'Approved', 'Request Details', 'Evidence &amp; Photos', 'Activity Timeline'], false)
            ->assertSee('Awaiting FMO personnel assignment')
            ->assertSee('No evidence added yet.');
        $this->actingAs($outsider)->get("/work-orders/{$order->id}")->assertForbidden();
        $this->actingAs($head)->get("/work-orders/{$order->id}")->assertOk()->assertSee('Assign FMO Personnel');
        $this->actingAs($oversight)->get("/work-orders/{$order->id}")->assertOk()->assertDontSee('Assign FMO Personnel');

        app(WorkOrderAssignmentService::class)->add($order, $head, [$person->id]);
        $this->actingAs($staff)->get("/work-orders/{$order->id}")->assertOk()
            ->assertSee('Acknowledge and begin assessment')
            ->assertSee('Assigned FMO team')
            ->assertSee('Electrician');
        $this->actingAs($staff)->post("/work-orders/{$order->id}/assessment/acknowledge")->assertRedirect();
        $this->get("/work-orders/{$order->id}")->assertOk()
            ->assertSee('Record Assessment')
            ->assertSee('Take Photo')
            ->assertSee('capture="environment"', false)
            ->assertSee('accept="image/*"', false);
    }

    public function test_representative_lifecycle_states_render_clear_status_copy(): void
    {
        $head = $this->user('FMO Head');
        $order = $this->order($this->user('Requester'));
        $states = [
            [WorkOrderStatus::Submitted, 'Submitted'],
            [WorkOrderStatus::ForApproval, 'For Approval'],
            [WorkOrderStatus::Approved, 'Approved'],
            [WorkOrderStatus::Assigned, 'Assigned'],
            [WorkOrderStatus::ForAssessment, 'For Assessment'],
            [WorkOrderStatus::ReadyForWork, 'Ready for Work'],
            [WorkOrderStatus::InProgress, 'In Progress'],
            [WorkOrderStatus::ForContinuation, 'For Continuation'],
            [WorkOrderStatus::WaitingForMaterials, 'Waiting for Materials'],
            [WorkOrderStatus::ForVerification, 'For Verification'],
            [WorkOrderStatus::Completed, 'Completed'],
        ];

        foreach ($states as [$status, $label]) {
            $order->forceFill(['status' => $status])->save();
            $this->actingAs($head)->get("/work-orders/{$order->id}")->assertOk()->assertSee($label);
        }
    }

    public function test_images_are_normalized_thumbnailed_private_and_served_inline(): void
    {
        Storage::fake('local');
        $requester = $this->user('Requester');
        $other = $this->user('Requester');
        $head = $this->user('FMO Head');
        $order = $this->order($requester);
        config()->set('work_orders.attachments.max_count', 5);

        $large = UploadedFile::fake()->image('large.jpg', 2400, 1200);
        $small = UploadedFile::fake()->image('small.png', 240, 120);
        $portrait = $this->orientedJpeg('phone.jpg', 1200, 800, 6);
        $this->actingAs($requester)->put("/work-orders/{$order->id}", [
            'work_order_category_id' => $order->work_order_category_id,
            'campus_id' => $order->campus_id,
            'building_id' => $order->building_id,
            'subject' => $order->subject,
            'description' => $order->description,
            'urgency' => $order->urgency,
            'attachments' => [$large, $small, $portrait],
        ])->assertRedirect();

        $files = $order->attachments()->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(3, $files);
        foreach ($files as $attachment) {
            Storage::disk('local')->assertExists($attachment->stored_path);
            Storage::disk('local')->assertExists($attachment->thumbnail_path);
            $this->assertLessThanOrEqual(1920, max($attachment->width, $attachment->height));
            $this->assertLessThanOrEqual(480, max($attachment->thumbnail_width, $attachment->thumbnail_height));
            $this->assertNotSame($attachment->original_filename, basename($attachment->stored_path));
        }
        $this->assertSame([1920, 960], [$files[0]->width, $files[0]->height]);
        $this->assertSame([240, 120], [$files[1]->width, $files[1]->height]);
        $this->assertSame([800, 1200], [$files[2]->width, $files[2]->height]);
        $normalizedExif = @exif_read_data(Storage::disk('local')->path($files[2]->stored_path), null, true);
        $this->assertEmpty(is_array($normalizedExif) ? ($normalizedExif['GPS'] ?? []) : []);

        $full = $this->actingAs($requester)->get("/work-orders/{$order->id}/attachments/{$files[0]->id}")->assertOk();
        $this->assertStringStartsWith('inline;', $full->headers->get('content-disposition'));
        $this->assertStringContainsString('private', $full->headers->get('cache-control'));
        $thumb = $this->get("/work-orders/{$order->id}/attachments/{$files[0]->id}/thumbnail")->assertOk();
        $this->assertStringStartsWith('inline;', $thumb->headers->get('content-disposition'));
        $this->actingAs($other)->get("/work-orders/{$order->id}/attachments/{$files[0]->id}")->assertForbidden();
        $this->get("/work-orders/{$order->id}/attachments/{$files[0]->id}/thumbnail")->assertForbidden();
        $this->actingAs($head)->get("/work-orders/{$order->id}/attachments/{$files[0]->id}/thumbnail")->assertOk();
        $page = $this->actingAs($requester)->get("/work-orders/{$order->id}")->assertOk()
            ->assertSee('data-image-lightbox', false)
            ->assertSee('data-lightbox-next', false)
            ->assertSee('data-lightbox-counter', false)
            ->assertSee('loading="lazy"', false);
        $this->assertSame(3, substr_count($page->getContent(), 'data-gallery="requester-evidence"'));
    }

    public function test_extreme_pixel_dimensions_fail_with_a_friendly_validation_error(): void
    {
        Storage::fake('local');
        config()->set('work_orders.attachments.image_max_pixels', 100);
        $requester = $this->user('Requester');
        $order = $this->order($requester);

        $this->actingAs($requester)->put("/work-orders/{$order->id}", [
            'work_order_category_id' => $order->work_order_category_id,
            'campus_id' => $order->campus_id,
            'building_id' => $order->building_id,
            'subject' => $order->subject,
            'description' => $order->description,
            'urgency' => $order->urgency,
            'attachments' => [UploadedFile::fake()->image('huge.jpg', 20, 20)],
        ])->assertSessionHasErrors('attachments');
        $this->assertDatabaseCount('work_order_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function orientedJpeg(string $name, int $width, int $height, int $orientation): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 50, 110, 180);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $tiff = "II\x2A\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00".pack('v', $orientation)."\x00\x00\x00\x00\x00\x00";
        $exif = "Exif\x00\x00".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        return UploadedFile::fake()->createWithContent($name, substr($jpeg, 0, 2).$segment.substr($jpeg, 2));
    }
}
