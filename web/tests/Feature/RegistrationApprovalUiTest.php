<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationApprovalUiTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->reviewer = User::factory()->create(['status' => AccountStatus::Approved->value]);
        $this->reviewer->assignRole('System Administrator');
    }

    public function test_default_queue_and_server_side_name_and_email_search(): void
    {
        $kier = $this->applicant('Kier Ecle', 'kier.ecle@example.test');
        $emailMatch = $this->applicant('Arvin Salvador', 'office.match@example.test');
        $approved = $this->applicant('Already Approved', 'approved@example.test', AccountStatus::Approved);

        $this->actingAs($this->reviewer)->get('/admin/registrations')
            ->assertOk()
            ->assertViewHas('filters', fn (array $filters): bool => $filters['status'] === 'pending')
            ->assertViewHas('users', fn ($users): bool => $users->contains($kier) && $users->contains($emailMatch) && ! $users->contains($approved));

        $this->actingAs($this->reviewer)->get('/admin/registrations?search=Kier')
            ->assertOk()
            ->assertSee('Kier Ecle')
            ->assertDontSee('Arvin Salvador');

        $this->actingAs($this->reviewer)->get('/admin/registrations?search=office.match')
            ->assertOk()
            ->assertSee('Arvin Salvador')
            ->assertDontSee('Kier Ecle');
    }

    public function test_type_date_filters_and_status_counts_share_the_non_status_scope(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 12:00:00', 'Asia/Manila'));
        $recentPending = $this->applicant('Recent Faculty', 'recent@example.test', AccountStatus::Pending, 'faculty', CarbonImmutable::now()->subDay());
        $this->applicant('Recent Approved Faculty', 'approved-faculty@example.test', AccountStatus::Approved, 'faculty', CarbonImmutable::now()->subDays(2));
        $this->applicant('Old Faculty', 'old@example.test', AccountStatus::Pending, 'faculty', CarbonImmutable::now()->subDays(40));
        $this->applicant('Recent Staff', 'staff@example.test', AccountStatus::Pending, 'staff', CarbonImmutable::now()->subDay());

        $response = $this->actingAs($this->reviewer)->get('/admin/registrations?status=pending&type=faculty&registered=last_7_days');

        $response->assertOk()
            ->assertViewHas('users', fn ($users): bool => $users->count() === 1 && $users->first()->is($recentPending))
            ->assertViewHas('statusCounts', fn ($counts): bool => $counts['pending'] === 1 && $counts['approved'] === 1 && $counts['all'] === 2)
            ->assertSee('Recent Faculty')
            ->assertDontSee('Old Faculty')
            ->assertDontSee('Recent Staff');

        $this->actingAs($this->reviewer)->get('/admin/registrations?registered=custom&from=2026-10-23&to=2026-10-23')
            ->assertOk()
            ->assertViewHas('users', fn ($users): bool => $users->contains($recentPending));

        CarbonImmutable::setTestNow();
    }

    public function test_each_registration_status_tab_has_an_isolated_server_side_result(): void
    {
        $records = [
            'pending' => $this->applicant('Pending Applicant', 'pending@example.test'),
            'needs_correction' => $this->applicant('Correction Applicant', 'correction@example.test', AccountStatus::NeedsCorrection),
            'approved' => $this->applicant('Approved Applicant', 'approved@example.test', AccountStatus::Approved),
            'rejected' => $this->applicant('Rejected Applicant', 'rejected@example.test', AccountStatus::Rejected),
        ];

        foreach ($records as $status => $expected) {
            $this->actingAs($this->reviewer)->get('/admin/registrations?status='.$status)
                ->assertOk()
                ->assertViewHas('users', fn ($users): bool => $users->count() === 1 && $users->first()->is($expected));
        }

        $this->actingAs($this->reviewer)->get('/admin/registrations?status=all')
            ->assertOk()
            ->assertViewHas('users', fn ($users): bool => $users->total() === 4);
    }

    public function test_pagination_page_sizes_and_filter_query_state_are_bounded(): void
    {
        User::factory()->count(55)->create([
            'status' => AccountStatus::Pending->value,
            'user_type' => 'faculty',
        ]);

        $this->actingAs($this->reviewer)->get('/admin/registrations?status=pending&type=faculty&sort=oldest&per_page=50&page=2')
            ->assertOk()
            ->assertViewHas('users', fn ($users): bool => $users->total() === 55 && $users->count() === 5 && $users->currentPage() === 2)
            ->assertSee('type=faculty', false)
            ->assertSee('sort=oldest', false)
            ->assertSee('per_page=50', false);

        $this->actingAs($this->reviewer)->get('/admin/registrations?per_page=50')
            ->assertViewHas('users', fn ($users): bool => $users->perPage() === 50);
        $this->actingAs($this->reviewer)->get('/admin/registrations?per_page=100')
            ->assertViewHas('users', fn ($users): bool => $users->perPage() === 100);
        $this->actingAs($this->reviewer)->get('/admin/registrations?per_page=50000&status=invalid&sort=drop_table&type=unknown')
            ->assertOk()
            ->assertViewHas('users', fn ($users): bool => $users->perPage() === 20)
            ->assertViewHas('filters', fn (array $filters): bool => $filters['status'] === 'pending' && $filters['sort'] === 'newest' && $filters['type'] === '');
    }

    public function test_all_supported_sort_orders_are_deterministic(): void
    {
        $alpha = $this->applicant('Alpha Applicant', 'alpha@example.test', createdAt: now()->subDays(2));
        $zulu = $this->applicant('Zulu Applicant', 'zulu@example.test', createdAt: now()->subDay());

        $cases = [
            'newest' => $zulu,
            'oldest' => $alpha,
            'applicant_asc' => $alpha,
            'applicant_desc' => $zulu,
        ];

        foreach ($cases as $sort => $expected) {
            $this->actingAs($this->reviewer)->get('/admin/registrations?sort='.$sort)
                ->assertOk()
                ->assertViewHas('users', fn ($users): bool => $users->first()->is($expected));
        }
    }

    public function test_empty_states_responsive_markup_and_action_labels_are_clear(): void
    {
        $this->actingAs($this->reviewer)->get('/admin/registrations')
            ->assertOk()
            ->assertSee('No pending registrations')
            ->assertSee('There are currently no account requests waiting for review.');

        $pending = $this->applicant('Mobile Pending', 'mobile-pending@example.test');
        $this->applicant('Mobile Approved', 'mobile-approved@example.test', AccountStatus::Approved);

        $this->actingAs($this->reviewer)->get('/admin/registrations?search=does-not-exist')
            ->assertOk()
            ->assertSee('No registrations found')
            ->assertSee('Clear Filters');

        $this->actingAs($this->reviewer)->get('/admin/registrations')
            ->assertOk()
            ->assertSee('mobile-card-table', false)
            ->assertSee('data-label="Institutional Type"', false)
            ->assertSee('Review')
            ->assertSee(route('registrations.show', $pending), false);

        $this->actingAs($this->reviewer)->get('/admin/registrations?status=approved')
            ->assertOk()
            ->assertSee('View');
    }

    public function test_authorization_and_filtered_return_location_are_preserved_safely(): void
    {
        $requester = User::factory()->create(['status' => AccountStatus::Approved->value]);
        $requester->assignRole('Requester');
        $applicant = $this->applicant('Return Applicant', 'return@example.test');
        $returnTo = '/admin/registrations?status=pending&type=faculty&page=2';

        $this->actingAs($requester)->get('/admin/registrations')->assertForbidden();

        $this->actingAs($this->reviewer)->get(route('registrations.show', ['user' => $applicant, 'return_to' => $returnTo]))
            ->assertOk()
            ->assertSee('name="return_to"', false)
            ->assertSee('/admin/registrations?status=pending&amp;type=faculty&amp;page=2', false);

        $this->actingAs($this->reviewer)->post(route('registrations.review', $applicant), [
            'action' => 'approve',
            'return_to' => $returnTo,
        ])->assertRedirect($returnTo)->assertSessionHas('success', 'Registration approved successfully.');
        $this->assertSame(AccountStatus::Approved, $applicant->fresh()->status);

        $second = $this->applicant('Safe Redirect', 'safe-redirect@example.test');
        $this->actingAs($this->reviewer)->post(route('registrations.review', $second), [
            'action' => 'approve',
            'return_to' => '//evil.example/steal',
        ])->assertRedirect(route('registrations.index'));
    }

    private function applicant(
        string $name,
        string $email,
        AccountStatus $status = AccountStatus::Pending,
        string $type = 'staff',
        mixed $createdAt = null,
    ): User {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'status' => $status->value,
            'user_type' => $type,
            'approved_at' => $status === AccountStatus::Approved ? ($createdAt ?? now()) : null,
            'approved_by' => $status === AccountStatus::Approved ? $this->reviewer->id : null,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);
    }
}
