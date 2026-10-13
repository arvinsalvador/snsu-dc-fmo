<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Campus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Notifications\WorkflowNotice;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
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

    private function notify(User $user, int $count, string $event = 'SUBMITTED', string $prefix = 'Notice'): void
    {
        foreach (range(1, $count) as $index) {
            $number = 'WO-2026-'.str_pad((string) $index, 6, '0', STR_PAD_LEFT);
            $user->notify(new WorkflowNotice($event, "{$prefix} {$index}", "{$number} was updated.", null, null, $number));
        }
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
            'subject' => 'Repair light',
            'description' => 'Broken light',
            'urgency' => 'NORMAL',
            'status' => 'SUBMITTED',
            'submitted_at' => now(),
        ]);
    }

    public function test_notifications_are_paginated_twenty_per_page(): void
    {
        $user = $this->user();
        $this->notify($user, 55);

        foreach ([1 => 20, 2 => 20, 3 => 15] as $page => $expectedCount) {
            $this->actingAs($user)->get('/notifications?page='.$page)->assertOk()
                ->assertViewHas('notifications', fn ($notifications): bool => $notifications->count() === $expectedCount
                    && $notifications->total() === 55
                    && $notifications->currentPage() === $page
                );
        }
    }

    public function test_all_and_unread_tabs_use_the_full_unread_total(): void
    {
        $user = $this->user();
        $this->notify($user, 25);
        $user->notifications()->limit(4)->update(['read_at' => now()]);

        $this->actingAs($user)->get('/notifications?page=2')->assertOk()
            ->assertViewHas('unreadCount', 21)
            ->assertSee('21 unread', escape: false);

        $this->actingAs($user)->get('/notifications?tab=unread&page=2')->assertOk()
            ->assertViewHas('tab', 'unread')
            ->assertViewHas('unreadCount', 21)
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->count() === 1 && $notifications->total() === 21);
    }

    public function test_single_and_mark_all_actions_are_recipient_scoped(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $this->notify($owner, 3, prefix: 'Owner');
        $this->notify($other, 2, prefix: 'Other');
        $notification = $owner->notifications()->firstOrFail();

        $this->actingAs($other)->post(route('notifications.read', $notification))->assertNotFound();
        $this->actingAs($owner)->post(route('notifications.read', $notification))->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);

        $this->post(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $owner->fresh()->unreadNotifications()->count());
        $this->assertSame(2, $other->fresh()->unreadNotifications()->count());
    }

    public function test_type_filter_keeps_its_query_across_pagination(): void
    {
        $user = $this->user();
        $this->notify($user, 25, 'ASSIGNEE_ADDED', 'Assignment');
        $this->notify($user, 3, 'SUBMITTED', 'Request');

        $this->actingAs($user)->get('/notifications?type=assignments')->assertOk()
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->count() === 20 && $notifications->total() === 25)
            ->assertSee('type=assignments&amp;page=2', escape: false)
            ->assertDontSee('Request 1');

        $this->get('/notifications?type=assignments&page=2')->assertOk()
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->count() === 5 && $notifications->total() === 25);
    }

    public function test_work_order_link_is_accessible_to_owner_and_stale_access_is_denied(): void
    {
        $owner = $this->user();
        $outsider = $this->user();
        $order = $this->order($owner, 'WO-2026-900001');
        $url = route('work-orders.show', $order);
        $owner->notify(new WorkflowNotice('SUBMITTED', 'Work Order received', 'WO-2026-900001 was recorded.', $order->id, null, $order->work_order_number));
        $outsider->notify(new WorkflowNotice('SUBMITTED', 'Work Order received', 'WO-2026-900001 was recorded.', $order->id, null, $order->work_order_number));

        $this->actingAs($owner)->get('/notifications')->assertOk()
            ->assertSee('View Work Order WO-2026-900001', escape: false)
            ->assertSee($url);
        $this->get($url)->assertOk();

        $this->actingAs($outsider)->get('/notifications')->assertOk()->assertSee($url);
        $this->get($url)->assertForbidden();
    }

    public function test_date_groups_and_empty_states_are_clear(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/notifications')->assertOk()->assertSee('No notifications yet');
        $this->get('/notifications?tab=unread')->assertOk()->assertSee("You're all caught up");
        $this->get('/notifications?search=missing')->assertOk()->assertSee('No matching notifications');

        $this->notify($user, 3);
        $items = $user->notifications()->latest()->get();
        $items[1]->forceFill(['created_at' => now()->subDay()])->save();
        $items[2]->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->get('/notifications')->assertOk()->assertSeeInOrder(['Today', 'Yesterday', 'Earlier']);
    }

    public function test_header_preview_is_limited_to_the_latest_five_and_caps_badge(): void
    {
        $user = $this->user();
        $this->notify($user, 105, prefix: 'Header notice');
        foreach ($user->notifications()->get() as $item) {
            $index = (int) str($item->data['title'])->afterLast(' ')->toString();
            $item->forceFill(['created_at' => now()->subMinutes(106 - $index)])->save();
        }

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('99+')
            ->assertSee('Header notice 105')
            ->assertSee('Header notice 101')
            ->assertDontSee('Header notice 100');
    }

    public function test_existing_work_order_notification_displays_requester_subject_and_context(): void
    {
        $requester = $this->user();
        $requester->forceFill(['name' => 'Maria D. Santos', 'user_type' => 'faculty'])->save();
        $requester->requesterProfile()->create(['department' => 'CCIS']);
        $recipient = $this->user('FMO Dispatcher');
        $order = $this->order($requester, 'WO-2026-001380');
        $order->update(['subject' => 'Defective air-conditioning unit']);

        // Represents an older payload that only knew the related Work Order ID.
        $recipient->notify(new WorkflowNotice(
            'SUBMITTED',
            'Work Order received',
            'WO-2026-001380 was recorded.',
            $order->id,
        ));

        $this->actingAs($recipient)->get('/notifications')->assertOk()
            ->assertSee('WO-2026-001380')
            ->assertSee('Defective air-conditioning unit')
            ->assertSee('Requester')
            ->assertSee('Maria D. Santos')
            ->assertSee('Faculty • CCIS');
    }

    public function test_search_matches_requester_name_work_order_and_subject_without_cross_matching(): void
    {
        $maria = $this->user();
        $maria->forceFill(['name' => 'Maria D. Santos'])->save();
        $juan = $this->user();
        $juan->forceFill(['name' => 'Juan P. Cruz'])->save();
        $recipient = $this->user('FMO Dispatcher');
        $mariaOrder = $this->order($maria, 'WO-2026-001381');
        $mariaOrder->update(['subject' => 'Defective air-conditioning unit']);
        $juanOrder = $this->order($juan, 'WO-2026-001382');
        $juanOrder->update(['subject' => 'Repair classroom door']);

        foreach ([[$mariaOrder, 'Maria notice'], [$juanOrder, 'Juan notice']] as [$order, $title]) {
            $recipient->notify(new WorkflowNotice('SUBMITTED', $title, 'A Work Order was recorded.', $order->id));
        }

        foreach (['Maria', 'Santos', 'Maria Santos', 'WO-2026-001381', 'air-conditioning'] as $search) {
            $this->actingAs($recipient)->get('/notifications?search='.urlencode($search))->assertOk()
                ->assertViewHas('notifications', fn ($notifications): bool => $notifications->total() === 1)
                ->assertViewHas('groupedNotifications', function ($groups): bool {
                    $items = $groups->flatten(1);

                    return $items->count() === 1
                        && $items->first()['presentation']['requester_name'] === 'Maria D. Santos';
                });
        }
    }

    public function test_requester_search_remains_notification_owner_scoped(): void
    {
        $maria = $this->user();
        $maria->forceFill(['name' => 'Maria Santos'])->save();
        $order = $this->order($maria, 'WO-2026-001383');
        $owner = $this->user('FMO Dispatcher');
        $other = $this->user('FMO Head');
        $other->notify(new WorkflowNotice('SUBMITTED', 'Private notification', 'Private update.', $order->id));

        $this->actingAs($owner)->get('/notifications?search=Maria')->assertOk()
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->total() === 0);
    }

    public function test_missing_work_order_requester_falls_back_without_error(): void
    {
        $recipient = $this->user('FMO Dispatcher');
        $recipient->notify(new WorkflowNotice('SUBMITTED', 'Historical Work Order', 'An archived request was updated.', '01HZZZZZZZZZZZZZZZZZZZZZZZ'));

        $this->actingAs($recipient)->get('/notifications')->assertOk()
            ->assertSee('Historical Work Order')
            ->assertSee('Requester unavailable');
    }

    public function test_requester_search_paginates_all_matches_and_preserves_query(): void
    {
        $maria = $this->user();
        $maria->forceFill(['name' => 'Maria Santos'])->save();
        $order = $this->order($maria, 'WO-2026-001384');
        $recipient = $this->user('FMO Dispatcher');

        foreach (range(1, 25) as $index) {
            $recipient->notify(new WorkflowNotice('ASSIGNEE_ADDED', "Assignment {$index}", 'Assignment update.', $order->id));
        }

        $this->actingAs($recipient)->get('/notifications?search=Maria&type=assignments')->assertOk()
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->count() === 20 && $notifications->total() === 25)
            ->assertSee('search=Maria&amp;type=assignments&amp;page=2', escape: false);
        $this->get('/notifications?search=Maria&type=assignments&page=2')->assertOk()
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->count() === 5 && $notifications->total() === 25);
    }

    public function test_work_order_context_is_batch_loaded_without_per_row_queries(): void
    {
        $requester = $this->user();
        $recipient = $this->user('FMO Dispatcher');

        foreach (range(1, 20) as $index) {
            $order = $this->order($requester, 'WO-2026-8'.str_pad((string) $index, 5, '0', STR_PAD_LEFT));
            $recipient->notify(new WorkflowNotice('SUBMITTED', "Request {$index}", 'Request update.', $order->id));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($recipient)->get('/notifications')->assertOk();
        $workOrderQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'from `work_orders`')
        );

        $this->assertGreaterThanOrEqual(1, $workOrderQueries->count());
        $this->assertLessThanOrEqual(2, $workOrderQueries->count());
    }
}
