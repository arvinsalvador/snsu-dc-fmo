<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoContactThread;
use App\Models\FmoPersonnel;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Models\WorkOrderInternalNote;
use App\Models\WorkOrderMessage;
use App\Services\WorkOrderAssignmentService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommunicationModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_requester_and_assigned_staff_exchange_paginated_work_order_messages_with_unread_tracking(): void
    {
        $head = $this->user('FMO Head');
        $requester = $this->user();
        $staff = $this->user('FMO Staff');
        $order = $this->order($requester, 'WO-2026-700001');
        $this->assign($order, $staff, $head);

        $this->actingAs($requester)->post(route('work-orders.messages.store', $order), [
            'message' => 'The outlet is still sparking.',
            'attachments' => [UploadedFile::fake()->image('outlet.jpg')],
            'sender_user_id' => $staff->id,
            'work_order_id' => $this->order($requester, 'WO-2026-700099')->id,
        ])->assertRedirectContains('communication=messages');

        $message = WorkOrderMessage::firstOrFail();
        $this->assertSame($requester->id, $message->sender_user_id);
        $this->assertSame($order->id, $message->work_order_id);
        $attachment = $message->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->stored_path);
        Storage::disk('local')->assertExists($attachment->thumbnail_path);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $staff->id]);

        $this->actingAs($staff)->get(route('work-orders.my-tasks'))
            ->assertOk()->assertSee('1 unread message');
        $this->get(route('work-orders.show', $order).'?communication=messages')
            ->assertOk()->assertSee('The outlet is still sparking.')
            ->assertSee('Requester')->assertSee('Suggested replies');
        $this->assertDatabaseHas('work_order_message_reads', ['work_order_id' => $order->id, 'user_id' => $staff->id]);
        $this->get(route('work-orders.my-tasks'))->assertOk()->assertDontSee('unread message');

        $this->post(route('work-orders.messages.store', $order), ['message' => 'Please avoid using the outlet until we inspect it.'])->assertRedirect();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $requester->id]);
        $this->actingAs($requester)->get(route('communication-attachments.show', $attachment))->assertOk();
        $this->apiAs($requester)->postJson('/api/v1/work-orders/'.$order->id.'/messages', ['message' => 'API follow-up message'])
            ->assertCreated()->assertJsonPath('data.sender.id', $requester->id);
    }

    public function test_work_order_message_scope_follows_current_assignment_and_blocks_idor(): void
    {
        $head = $this->user('FMO Head');
        $owner = $this->user();
        $otherRequester = $this->user();
        $firstStaff = $this->user('FMO Staff');
        $replacement = $this->user('FMO Staff');
        $unassigned = $this->user('FMO Staff');
        $order = $this->order($owner, 'WO-2026-700002');
        $assignment = $this->assign($order, $firstStaff, $head);
        $this->actingAs($owner)->post(route('work-orders.messages.store', $order), ['message' => 'Conversation history'])->assertRedirect();

        foreach ([$otherRequester, $unassigned] as $outsider) {
            $this->actingAs($outsider)->get(route('work-orders.show', $order))->assertForbidden();
            $this->post(route('work-orders.messages.store', $order), ['message' => 'Unauthorized'])->assertForbidden();
            $this->apiAs($outsider)->getJson('/api/v1/work-orders/'.$order->id.'/messages')->assertForbidden();
        }

        app(WorkOrderAssignmentService::class)->remove($order->fresh(), $assignment, $head, 'Reassigned to another technician.');
        $this->assign($order->fresh(), $replacement, $head);
        $this->actingAs($firstStaff)->get(route('work-orders.show', $order))->assertForbidden();
        $this->actingAs($replacement)->get(route('work-orders.show', $order))->assertOk()->assertSee('Conversation history');
    }

    public function test_internal_notes_are_isolated_from_requester_web_api_print_notifications_and_attachments(): void
    {
        $head = $this->user('FMO Head');
        $requester = $this->user();
        $staff = $this->user('FMO Staff');
        $order = $this->order($requester, 'WO-2026-700003');
        $this->assign($order, $staff, $head);

        $this->actingAs($staff)->post(route('work-orders.internal-notes.store', $order), [
            'message' => 'SECRET: inspect Panel B before replacement.',
            'attachments' => [UploadedFile::fake()->image('panel-b.jpg')],
        ])->assertRedirectContains('communication=notes');
        $note = WorkOrderInternalNote::firstOrFail();
        $attachment = $note->attachments()->firstOrFail();

        $this->actingAs($head)->get(route('work-orders.show', $order).'?communication=notes')
            ->assertOk()->assertSee('SECRET: inspect Panel B')->assertSee('FMO Internal');
        $this->actingAs($requester)->get(route('work-orders.show', $order).'?communication=notes')->assertForbidden();
        $this->get(route('work-orders.show', $order))->assertOk()->assertDontSee('SECRET: inspect Panel B');
        $this->get(route('communication-attachments.show', $attachment))->assertForbidden();
        $this->get(route('reports.print', $order))->assertOk()->assertDontSee('SECRET: inspect Panel B');
        $this->apiAs($requester)->getJson('/api/v1/work-orders/'.$order->id)
            ->assertOk()->assertJsonMissing(['body' => 'SECRET: inspect Panel B before replacement.']);
        $this->getJson('/api/v1/work-orders/'.$order->id.'/internal-notes')->assertForbidden();
        $this->assertSame(0, $requester->notifications()->where('data->event_key', 'WORK_ORDER_INTERNAL_NOTE')->count());
        $this->assertGreaterThan(0, $head->notifications()->where('data->event_key', 'WORK_ORDER_INTERNAL_NOTE')->count());
    }

    public function test_message_history_is_bounded_escaped_and_does_not_change_workflow_status(): void
    {
        $requester = $this->user();
        $order = $this->order($requester, 'WO-2026-700004');
        foreach (range(1, 25) as $index) {
            $order->messages()->create(['sender_user_id' => $requester->id, 'body' => $index === 1 ? 'Oldest page marker' : "Message {$index}"]);
        }
        $status = $order->status;

        $this->actingAs($requester)->post(route('work-orders.messages.store', $order), [
            'message' => '<script>alert("xss")</script> Please cancel this Work Order.',
        ])->assertRedirect();
        $this->assertSame($status, $order->fresh()->status);
        $this->assertDatabaseHas('work_order_messages', [
            'work_order_id' => $order->id,
            'body' => '<script>alert("xss")</script> Please cancel this Work Order.',
        ]);
        $this->get(route('work-orders.show', $order).'?communication=messages')
            ->assertOk()->assertSee('Please cancel this Work Order.')->assertDontSee('<script>alert', escape: false)
            ->assertSee('Load earlier messages')->assertDontSee('Oldest page marker');

        $this->apiAs($requester)->getJson('/api/v1/work-orders/'.$order->id.'/messages')
            ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('current_page', 1);
        $this->getJson('/api/v1/work-orders/'.$order->id.'/messages?messages_page=2')
            ->assertOk()->assertJsonCount(6, 'data');
    }

    public function test_closed_work_order_communications_are_read_only(): void
    {
        $requester = $this->user();
        $order = $this->order($requester, 'WO-2026-700005');
        $order->messages()->create(['sender_user_id' => $requester->id, 'body' => 'Historical message']);
        $order->update(['status' => WorkOrderStatus::Completed]);

        $this->actingAs($requester)->get(route('work-orders.show', $order))
            ->assertOk()->assertSee('Historical message')->assertSee('Conversation is read-only');
        $this->post(route('work-orders.messages.store', $order), ['message' => 'Late message'])->assertForbidden();
        $this->apiAs($requester)->postJson('/api/v1/work-orders/'.$order->id.'/messages', ['message' => 'Late API message'])->assertForbidden();
    }

    public function test_contact_fmo_create_reply_close_notifications_and_closed_history(): void
    {
        $requester = $this->user();
        $head = $this->user('FMO Head');

        $this->actingAs($requester)->post(route('contact-fmo.store'), [
            'category' => 'GENERAL_FACILITIES_INQUIRY',
            'subject' => 'Campus water schedule',
            'message' => 'Is there a scheduled interruption tomorrow?',
            'attachments' => [UploadedFile::fake()->image('faucet.jpg')],
            'requester_user_id' => $head->id,
        ])->assertRedirect();
        $thread = FmoContactThread::firstOrFail();
        $this->assertSame($requester->id, $thread->requester_user_id);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $head->id]);
        $attachment = $thread->messages()->firstOrFail()->attachments()->firstOrFail();

        $this->actingAs($head)->get(route('fmo-inquiries.show', $thread))
            ->assertOk()->assertSee('Campus water schedule')->assertSee($requester->name);
        $this->get(route('communication-attachments.show', $attachment))->assertOk();
        $this->post(route('fmo-inquiries.reply', $thread), ['message' => 'No interruption is currently scheduled.'])->assertRedirect();
        $this->assertSame('RESPONDED', $thread->fresh()->status->value);
        $this->assertGreaterThan(0, $requester->notifications()->where('data->event_key', 'FMO_INQUIRY_REPLY')->count());

        $this->post(route('fmo-inquiries.close', $thread))->assertRedirect();
        $this->assertSame('CLOSED', $thread->fresh()->status->value);
        $this->actingAs($requester)->get(route('contact-fmo.show', $thread))
            ->assertOk()->assertSee('No interruption is currently scheduled.')->assertSee('Inquiry closed');
        $this->get(route('communication-attachments.show', $attachment))->assertOk();
        $this->post(route('contact-fmo.reply', $thread), ['message' => 'Another reply'])->assertForbidden();
    }

    public function test_contact_fmo_user_isolation_existing_work_order_guidance_and_api_scope(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $order = $this->order($owner, 'WO-2026-700006');

        $this->actingAs($owner)->get(route('contact-fmo.create'))
            ->assertOk()->assertSee('Repair and service requests belong in Work Orders')
            ->assertSee('Question About Existing Work Order');
        $this->post(route('contact-fmo.store'), [
            'category' => 'EXISTING_WORK_ORDER_QUESTION',
            'subject' => 'Question about repair',
            'message' => 'When will this be assessed?',
        ])->assertSessionHasErrors('related_work_order_id');
        $this->post(route('contact-fmo.store'), [
            'category' => 'EXISTING_WORK_ORDER_QUESTION',
            'subject' => 'Question about repair',
            'related_work_order_id' => $order->id,
            'message' => 'When will this be assessed?',
        ])->assertRedirect();
        $thread = FmoContactThread::firstOrFail();

        $this->actingAs($other)->get(route('contact-fmo.show', $thread))->assertForbidden();
        $attachmentOwner = $thread->messages()->firstOrFail();
        $attachmentOwner->attachments()->create([
            'uploaded_by' => $owner->id,
            'purpose' => 'FMO_INQUIRY_MESSAGE',
            'original_filename' => 'private.jpg',
            'stored_path' => 'communications/private.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 7,
        ]);
        Storage::disk('local')->put('communications/private.jpg', 'private');
        $this->get(route('communication-attachments.show', $attachmentOwner->attachments()->firstOrFail()))->assertForbidden();
        $this->apiAs($other)->getJson('/api/v1/fmo-inquiries/'.$thread->id)->assertForbidden();
        $this->getJson('/api/v1/fmo-inquiries')->assertOk()->assertJsonCount(0, 'data');
        $this->apiAs($owner)->getJson('/api/v1/fmo-inquiries')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_communication_lists_eager_load_senders_and_attachments_without_query_growth(): void
    {
        $requester = $this->user();
        $order = $this->order($requester, 'WO-2026-700007');
        foreach (range(1, 20) as $index) {
            $order->messages()->create(['sender_user_id' => $requester->id, 'body' => "Bounded message {$index}"]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->apiAs($requester)->getJson('/api/v1/work-orders/'.$order->id.'/messages')->assertOk();
        $queries = collect(DB::getQueryLog());

        $this->assertLessThanOrEqual(25, $queries->count());
        $this->assertSame(1, $queries->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'from `communication_attachments`'))->count());
    }

    public function test_contact_fmo_api_creation_and_management_reply_follow_the_same_scope(): void
    {
        $requester = $this->user();
        $head = $this->user('FMO Head');

        $this->apiAs($requester)->postJson('/api/v1/fmo-inquiries', [
            'category' => 'OTHER',
            'subject' => 'Wayfinding question',
            'message' => 'Where is the FMO office located?',
        ])->assertCreated()->assertJsonPath('data.requester.id', $requester->id);
        $thread = FmoContactThread::firstOrFail();

        $this->apiAs($head)->getJson('/api/v1/fmo-inquiries/'.$thread->id)
            ->assertOk()->assertJsonPath('data.can_reply', true);
        $this->postJson('/api/v1/fmo-inquiries/'.$thread->id.'/messages', ['message' => 'The FMO office is beside the administration building.'])
            ->assertCreated()->assertJsonPath('data.sender.id', $head->id);
        $this->assertSame('RESPONDED', $thread->fresh()->status->value);
    }

    private function user(string $role = 'Requester'): User
    {
        $user = User::factory()->create(['status' => 'APPROVED']);
        $user->assignRole($role);

        return $user;
    }

    private function order(User $requester, string $number): WorkOrder
    {
        $campus = Campus::firstOrCreate(['code' => 'DC'], ['name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::firstOrCreate(['campus_id' => $campus->id, 'name' => 'Academic'], ['is_active' => true]);
        $category = WorkOrderCategory::firstOrCreate(['name' => 'Electrical'], ['is_active' => true]);

        return WorkOrder::create([
            'requester_id' => $requester->id,
            'campus_id' => $campus->id,
            'building_id' => $building->id,
            'work_order_category_id' => $category->id,
            'work_order_number' => $number,
            'subject' => 'Repair electrical outlet',
            'description' => 'The outlet is not working safely.',
            'urgency' => 'NORMAL',
            'status' => WorkOrderStatus::Approved,
            'submitted_at' => now(),
        ]);
    }

    private function assign(WorkOrder $order, User $staff, User $head)
    {
        $personnel = FmoPersonnel::create([
            'user_id' => $staff->id,
            'designation' => 'Technician',
            'personnel_status' => 'ACTIVE',
        ]);
        app(WorkOrderAssignmentService::class)->add($order, $head, [$personnel->id]);

        return $order->activeAssignments()->where('fmo_personnel_id', $personnel->id)->firstOrFail();
    }

    private function apiAs(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('communications-test')->plainTextToken);
    }
}
