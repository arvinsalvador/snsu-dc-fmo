<?php

namespace Tests\Feature;

use App\Models\AccountAuditEvent;
use App\Models\Building;
use App\Models\Campus;
use App\Models\FmoPersonnel;
use App\Models\TransportRequest;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Support\Initials;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', config('database.connections.'.config('database.default').'.database'));
        Storage::fake('local');
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_user_views_own_profile_and_guests_cannot(): void
    {
        $head = $this->user('FMO Head', ['name' => 'Arvin S. Salvador']);

        $this->get(route('profile.show'))->assertRedirect();
        $this->actingAs($head)->get(route('profile.show'))->assertOk()->assertSee('My Profile')->assertSee('Personal information')->assertSee('Password &amp; security', false)->assertSee('FMO Head');
    }

    public function test_user_updates_own_name_and_normalises_spacing(): void
    {
        $user = $this->user('Requester');

        $this->actingAs($user)->put(route('profile.personal'), ['name' => '  Maria   Clara  Santos ', 'contact_number' => '0917 000 0000'])->assertRedirect(route('profile.show'));

        $this->assertSame('Maria Clara Santos', $user->fresh()->name);
        $this->assertSame('0917 000 0000', $user->fresh()->requesterProfile->contact_number);
        $this->assertSame('profile_updated', AccountAuditEvent::where('user_id', $user->id)->value('event'));
    }

    public function test_blank_name_is_rejected(): void
    {
        $user = $this->user('Requester', ['name' => 'Original Name']);

        $this->actingAs($user)->put(route('profile.personal'), ['name' => '   '])->assertSessionHasErrors('name');
        $this->assertSame('Original Name', $user->fresh()->name);
    }

    public function test_users_cannot_change_their_own_email_and_the_page_shows_it_read_only(): void
    {
        $user = $this->user('Requester', ['email' => 'owner@example.test']);

        foreach ([['email' => 'someone.else@example.test'], ['email' => null], ['email' => ''], ['email' => 'OWNER@example.test']] as $payload) {
            $this->actingAs($user)->put(route('profile.personal'), ['name' => 'Same Person'] + $payload)->assertSessionHasNoErrors();
            $this->assertSame('owner@example.test', $user->fresh()->email);
        }
        $this->assertSame(0, AccountAuditEvent::where('event', 'email_changed')->count());
        $page = $this->actingAs($user)->get(route('profile.show'))->assertOk()->assertSee('owner@example.test')->assertSee('can only be changed by FMO administration');
        $this->assertStringNotContainsString('name="email"', $page->getContent());
    }

    public function test_an_administrator_cannot_set_an_email_that_belongs_to_another_user(): void
    {
        $head = $this->user('FMO Head');
        $other = $this->user('Requester', ['email' => 'taken@example.test']);
        $user = $this->user('Requester', ['email' => 'mine@example.test']);

        $this->actingAs($head)->put(route('users.profile', $user), ['name' => $user->name, 'email' => 'taken@example.test'])
            ->assertSessionHasErrors(['email' => 'An account with this email address already exists.']);
        $this->actingAs($head)->put(route('users.profile', $user), ['name' => $user->name, 'email' => 'TAKEN@Example.test'])->assertSessionHasErrors('email');
        $this->assertSame('mine@example.test', $user->fresh()->email);
        $this->assertSame('taken@example.test', $other->fresh()->email);
    }

    public function test_an_administrator_cannot_blank_or_remove_an_email(): void
    {
        $head = $this->user('FMO Head');
        $user = $this->user('Requester', ['email' => 'keep@example.test']);

        foreach ([['email' => ''], ['email' => null], ['email' => '   '], []] as $payload) {
            $this->actingAs($head)->put(route('users.profile', $user), ['name' => $user->name] + $payload)->assertSessionHasErrors('email');
        }
        $this->assertSame('keep@example.test', $user->fresh()->email);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        $this->user('Requester', ['email' => 'registered@example.test']);
        $payload = ['name' => 'Second Person', 'user_type' => 'staff', 'password' => 'a-long-password-123', 'password_confirmation' => 'a-long-password-123'];

        $this->post('/register', $payload + ['email' => 'registered@example.test'])->assertSessionHasErrors(['email' => 'An account with this email address already exists.']);
        $this->post('/register', $payload + ['email' => 'REGISTERED@example.test'])->assertSessionHasErrors('email');
        $this->assertSame(1, User::where('email', 'registered@example.test')->count());
    }

    public function test_database_enforces_unique_emails(): void
    {
        $this->assertTrue(collect(Schema::getIndexes('users'))->contains(fn ($index) => $index['unique'] && $index['columns'] === ['email']));
        $this->user('Requester', ['email' => 'dup@example.test']);

        $this->expectException(QueryException::class);
        DB::table('users')->insert(['name' => 'Dup', 'email' => 'dup@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_valid_images_become_a_square_avatar_that_renders(): void
    {
        $user = $this->user('Requester');

        foreach (['photo.jpg', 'photo.png', 'photo.webp'] as $name) {
            $this->actingAs($user)->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image($name, 900, 600)])->assertSessionHasNoErrors();
            $user->refresh();
            $this->assertTrue($user->hasAvatar(), $name);
            [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($user->avatar_path));
            $this->assertSame([320, 320], [$width, $height]);
        }
        $this->actingAs($user)->get($user->avatarUrl())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($user)->get(route('profile.show'))->assertOk()->assertSee('Profile picture of '.$user->name)->assertSee('/avatars/'.$user->id, false);
        $this->assertSame('photo_changed', AccountAuditEvent::where('user_id', $user->id)->latest('created_at')->value('event'));
    }

    public function test_non_image_and_oversized_files_are_rejected_as_avatars(): void
    {
        $user = $this->user('Requester');

        foreach ([UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'), UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo 1;'), UploadedFile::fake()->create('big.jpg', 4000, 'image/jpeg')] as $file) {
            $this->actingAs($user)->post(route('profile.photo.store'), ['photo' => $file])->assertSessionHasErrors('photo');
        }
        $this->assertFalse($user->fresh()->hasAvatar());
        $this->assertSame([], Storage::disk('local')->allFiles('avatars'));
    }

    public function test_replacing_the_photo_removes_the_old_file_and_serves_the_new_one(): void
    {
        $user = $this->user('Requester');
        $this->actingAs($user)->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image('a.jpg', 400, 400)]);
        $first = $user->fresh()->avatar_path;
        $firstUrl = $user->fresh()->avatarUrl();

        $this->travel(5)->seconds();
        $this->actingAs($user)->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image('b.png', 500, 500)]);
        $user->refresh();

        $this->assertNotSame($first, $user->avatar_path);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($user->avatar_path);
        $this->assertNotSame($firstUrl, $user->avatarUrl(), 'The URL changes so a stale copy is never shown.');
        $this->actingAs($user)->get($user->avatarUrl())->assertOk();
    }

    public function test_removing_the_photo_falls_back_to_initials_and_keeps_the_account(): void
    {
        $user = $this->user('Requester', ['name' => 'Arvin S. Salvador', 'email' => 'arvin@example.test']);
        $this->actingAs($user)->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image('a.jpg', 400, 400)]);
        $path = $user->fresh()->avatar_path;

        $this->actingAs($user)->delete(route('profile.photo.destroy'))->assertRedirect(route('profile.show'));

        $user->refresh();
        $this->assertFalse($user->hasAvatar());
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('arvin@example.test', $user->email);
        $this->actingAs($user)->get(route('profile.show'))->assertOk()->assertSee('>AS<', false);
        $this->actingAs($user)->get('/avatars/'.$user->id)->assertNotFound();
        $this->assertSame('photo_removed', AccountAuditEvent::where('user_id', $user->id)->latest('created_at')->value('event'));
    }

    public function test_initials_use_first_and_last_name_and_skip_suffixes(): void
    {
        $this->assertSame('AS', Initials::from('Arvin S. Salvador'));
        $this->assertSame('JE', Initials::from('Joey G. Escauso'));
        $this->assertSame('KE', Initials::from('Kier L. Ecle'));
        $this->assertSame('ET', Initials::from('Elmer O. Toldo'));
        $this->assertSame('BV', Initials::from('Blas C. Vicio Jr.'));
        $this->assertSame('M', Initials::from('Madonna'));
        $this->assertSame('?', Initials::from('  '));
    }

    public function test_work_order_shows_the_requesters_picture_or_initials(): void
    {
        $head = $this->user('FMO Head');
        $withPhoto = $this->user('Requester', ['name' => 'Joey G. Escauso']);
        $withoutPhoto = $this->user('Requester', ['name' => 'Kier L. Ecle']);
        $this->actingAs($withPhoto)->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image('a.jpg', 400, 400)]);
        $photoOrder = $this->workOrder($withPhoto, 'WO-2026-000101');
        $plainOrder = $this->workOrder($withoutPhoto, 'WO-2026-000102');

        $this->actingAs($head)->get(route('work-orders.show', $photoOrder))->assertOk()->assertSee('Profile picture of Joey G. Escauso')->assertSee('/avatars/'.$withPhoto->id, false);
        $this->actingAs($head)->get(route('work-orders.show', $plainOrder))->assertOk()->assertDontSee('Profile picture of Kier L. Ecle')->assertSee('>KE<', false);
        $this->actingAs($head)->get(route('work-orders.index'))->assertOk()->assertSee('/avatars/'.$withPhoto->id, false);
    }

    public function test_anyone_signed_in_can_load_a_colleagues_picture_but_guests_cannot(): void
    {
        $staff = $this->user('FMO Staff');
        $requester = $this->user('Requester');
        $this->actingAs($requester)->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image('a.jpg', 400, 400)]);

        $this->actingAs($staff)->get($requester->fresh()->avatarUrl())->assertOk();
        auth()->logout();
        $this->get($requester->fresh()->avatarUrl())->assertRedirect();
    }

    public function test_transport_request_shows_requester_and_driver_avatars(): void
    {
        $head = $this->user('FMO Head');
        $requester = $this->user('Requester', ['name' => 'Joey G. Escauso']);
        $driverUser = $this->user('FMO Staff', ['name' => 'Elmer O. Toldo']);
        $driver = FmoPersonnel::create(['user_id' => $driverUser->id, 'personnel_identifier' => 'DRV-1', 'designation' => 'Driver', 'personnel_status' => 'ACTIVE']);
        $this->actingAs($driverUser)->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image('d.jpg', 300, 300)]);
        $transport = TransportRequest::create([
            'request_number' => 'TR-2026-0001', 'requester_id' => $requester->id, 'purpose' => 'Meeting', 'origin' => 'Campus', 'destination' => 'Dapa',
            'travel_date' => '2026-11-12', 'passenger_count' => 2, 'status' => 'PENDING_APPROVAL', 'driver_personnel_id' => $driver->id,
        ]);

        $this->actingAs($head)->get(route('transport.show', $transport))->assertOk()->assertSee('>JE<', false)->assertSee('Profile picture of Elmer O. Toldo')->assertSee('/avatars/'.$driverUser->id, false);
        $this->actingAs($head)->get(route('transport.index'))->assertOk()->assertSee('>JE<', false);
    }

    public function test_user_changes_own_password_with_the_current_password(): void
    {
        $user = $this->user('Requester', ['password' => 'old-password-value-1']);
        $token = $user->createToken('mobile');

        $this->actingAs($user)->put(route('profile.password'), ['current_password' => 'old-password-value-1', 'password' => 'brand-new-password-9', 'password_confirmation' => 'brand-new-password-9'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Password updated successfully.');

        $this->assertTrue(Hash::check('brand-new-password-9', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count(), 'Signed-in apps must sign in again.');
        $event = AccountAuditEvent::where('event', 'password_changed')->sole();
        $this->assertStringNotContainsString('brand-new-password-9', json_encode($event->toArray()));
        $this->assertNotNull($token);
    }

    public function test_wrong_current_password_or_mismatch_or_weak_password_is_rejected(): void
    {
        $user = $this->user('Requester', ['password' => 'old-password-value-1']);
        $hash = $user->password;

        $this->actingAs($user)->put(route('profile.password'), ['current_password' => 'wrong', 'password' => 'brand-new-password-9', 'password_confirmation' => 'brand-new-password-9'])->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put(route('profile.password'), ['current_password' => 'old-password-value-1', 'password' => 'brand-new-password-9', 'password_confirmation' => 'different-password-9'])->assertSessionHasErrors('password');
        $this->actingAs($user)->put(route('profile.password'), ['current_password' => 'old-password-value-1', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->actingAs($user)->put(route('profile.password'), ['current_password' => 'old-password-value-1', 'password' => 'old-password-value-1', 'password_confirmation' => 'old-password-value-1'])->assertSessionHasErrors('password');
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_fmo_head_resets_another_users_password_and_it_is_audited_without_the_password(): void
    {
        $head = $this->user('FMO Head', ['password' => 'head-password-value-1', 'name' => 'Arvin S. Salvador']);
        $staff = $this->user('FMO Staff', ['password' => 'staff-old-password-1']);
        $token = $staff->createToken('mobile');

        $this->actingAs($head)->put(route('users.password', $staff), ['admin_password' => 'head-password-value-1', 'password' => 'temporary-pass-2026', 'password_confirmation' => 'temporary-pass-2026'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertTrue(Hash::check('temporary-pass-2026', $staff->fresh()->password));
        $this->assertSame(0, $staff->tokens()->count());
        $event = AccountAuditEvent::where('event', 'password_reset')->sole();
        $this->assertSame([$staff->id, $head->id], [$event->user_id, $event->actor_id]);
        $this->assertStringNotContainsString('temporary-pass-2026', json_encode($event->toArray()));
        $this->actingAs($head)->get(route('users.show', $staff))->assertOk()->assertSee('Password reset by an administrator')->assertSee('Arvin S. Salvador');
        $this->assertNotNull($token);
    }

    public function test_password_reset_needs_authority_the_admins_own_password_and_respects_administrators(): void
    {
        $head = $this->user('FMO Head', ['password' => 'head-password-value-1']);
        $staff = $this->user('FMO Staff');
        $other = $this->user('FMO Staff');
        $admin = $this->user('System Administrator');
        $payload = ['admin_password' => 'head-password-value-1', 'password' => 'temporary-pass-2026', 'password_confirmation' => 'temporary-pass-2026'];

        $this->actingAs($staff)->put(route('users.password', $other), $payload)->assertForbidden();
        $this->actingAs($head)->put(route('users.password', $other), ['admin_password' => 'wrong'] + $payload)->assertSessionHasErrors('admin_password');
        $this->actingAs($head)->put(route('users.password', $other), ['password_confirmation' => 'nope'] + $payload)->assertSessionHasErrors('password');
        $this->actingAs($head)->put(route('users.password', $head), $payload)->assertForbidden();
        $this->actingAs($head)->put(route('users.password', $admin), $payload)->assertForbidden();
        $this->assertSame(0, AccountAuditEvent::where('event', 'password_reset')->count());
    }

    public function test_passwords_and_hashes_are_never_disclosed(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff', ['password' => 'staff-old-password-1']);
        $hash = $staff->fresh()->password;
        $token = $staff->createToken('t')->plainTextToken;

        $page = $this->actingAs($head)->get(route('users.show', $staff))->assertOk();
        $page->assertDontSee($hash, false)->assertDontSee('staff-old-password-1')->assertSee('Existing passwords are never shown');
        $this->actingAs($head)->get(route('users.index'))->assertOk()->assertDontSee($hash, false);
        $this->actingAs($staff)->get(route('profile.show'))->assertDontSee($hash, false);
        $json = $this->withToken($token)->getJson('/api/v1/profile')->assertOk()->json();
        $this->assertStringNotContainsString($hash, json_encode($json));
        $this->assertArrayNotHasKey('password', $json['data']);
        $this->assertArrayNotHasKey('remember_token', $json['data']);
    }

    public function test_profile_update_ignores_roles_status_and_other_privileged_fields(): void
    {
        $user = $this->user('Requester', ['email' => 'self@example.test']);

        $this->actingAs($user)->put(route('profile.personal'), [
            'name' => 'Self Edited', 'role' => 'System Administrator', 'roles' => ['System Administrator'],
            'permissions' => ['users.assign_roles'], 'status' => 'APPROVED', 'account_status' => 'approved', 'is_admin' => true, 'user_type' => 'faculty', 'id' => 1,
        ])->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertSame('Self Edited', $fresh->name);
        $this->assertSame(['Requester'], $fresh->getRoleNames()->all());
        $this->assertSame([], $fresh->getDirectPermissions()->pluck('name')->all());
        $this->assertSame($user->user_type, $fresh->user_type);
        $this->assertSame($user->id, $fresh->id);
    }

    public function test_pending_account_stays_pending_after_changing_email(): void
    {
        $pending = $this->user('Requester', ['status' => 'PENDING']);

        $this->actingAs($pending)->put(route('profile.personal'), ['name' => 'Changed Name', 'status' => 'APPROVED'])->assertRedirect();

        $this->assertSame('PENDING', $pending->fresh()->status->value);
        $this->assertNotSame('Changed Name', $pending->fresh()->name, 'Unapproved accounts cannot use the application, including profile changes.');
    }

    public function test_one_user_cannot_modify_another_through_the_self_profile_or_admin_endpoints(): void
    {
        $victim = $this->user('Requester', ['name' => 'Victim', 'email' => 'victim@example.test']);
        $attacker = $this->user('Requester');

        $this->actingAs($attacker)->put(route('profile.personal'), ['name' => 'Hacked', 'user_id' => $victim->id, 'id' => $victim->id])->assertSessionHasNoErrors();
        $this->actingAs($attacker)->put(route('users.profile', $victim), ['name' => 'Hacked', 'email' => 'hacked2@example.test'])->assertForbidden();
        $this->actingAs($attacker)->get(route('users.show', $victim))->assertForbidden();

        $this->assertSame(['Victim', 'victim@example.test'], [$victim->fresh()->name, $victim->fresh()->email]);
    }

    public function test_fmo_head_edits_name_and_email_of_another_user_but_not_roles(): void
    {
        $head = $this->user('FMO Head');
        $staff = $this->user('FMO Staff', ['email' => 'staff@example.test']);
        $taken = $this->user('Requester', ['email' => 'taken@example.test']);

        $this->actingAs($head)->put(route('users.profile', $staff), ['name' => 'Renamed Staff', 'email' => 'staff.new@example.test', 'roles' => ['System Administrator']])->assertSessionHasNoErrors();
        $this->actingAs($head)->put(route('users.profile', $staff), ['name' => 'Renamed Staff', 'email' => 'taken@example.test'])->assertSessionHasErrors('email');

        $this->assertSame(['Renamed Staff', 'staff.new@example.test'], [$staff->fresh()->name, $staff->fresh()->email]);
        $event = AccountAuditEvent::where('event', 'email_changed')->sole();
        $this->assertSame([$staff->id, $head->id], [$event->user_id, $event->actor_id]);
        $this->assertSame(['FMO Staff'], $staff->fresh()->getRoleNames()->all());
        $this->assertNotNull($taken);
    }

    public function test_api_profile_supports_reading_updating_photo_and_password(): void
    {
        $user = $this->user('Requester', ['password' => 'old-password-value-1', 'email' => 'api@example.test']);
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.email', 'api@example.test')->assertJsonPath('data.avatar_url', null)->assertJsonPath('data.initials', Initials::from($user->name));
        $this->withToken($token)->patchJson('/api/v1/profile', ['name' => 'Api User', 'email' => 'api.new@example.test'])->assertOk()->assertJsonPath('data.name', 'Api User')->assertJsonPath('data.email', 'api@example.test');
        $this->withToken($token)->patchJson('/api/v1/profile', ['name' => 'Api User', 'email' => null])->assertOk()->assertJsonPath('data.email', 'api@example.test');
        $this->withToken($token)->patchJson('/api/v1/profile', ['name' => ''])->assertUnprocessable();
        $this->withToken($token)->post('/api/v1/profile/photo', ['photo' => UploadedFile::fake()->image('a.jpg', 400, 400)], ['Accept' => 'application/json'])->assertOk();
        $url = $user->fresh()->avatarUrl(true);
        $this->assertNotNull($url);
        $this->withToken($token)->get($url)->assertOk();
        $this->withToken($token)->deleteJson('/api/v1/profile/photo')->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->withToken($token)->putJson('/api/v1/profile/password', ['current_password' => 'wrong', 'password' => 'brand-new-password-9', 'password_confirmation' => 'brand-new-password-9'])->assertUnprocessable();
        $this->withToken($token)->putJson('/api/v1/profile/password', ['current_password' => 'old-password-value-1', 'password' => 'brand-new-password-9', 'password_confirmation' => 'brand-new-password-9'])->assertOk();
        $this->assertTrue(Hash::check('brand-new-password-9', $user->fresh()->password));
        $this->withToken($token)->getJson('/api/v1/profile')->assertOk();
    }

    /** @param array<string, mixed> $attributes */
    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['status' => 'APPROVED', 'user_type' => 'staff']);
        $user->assignRole($role);

        return $user;
    }

    private function workOrder(User $requester, string $number): WorkOrder
    {
        $campus = Campus::firstOrCreate(['code' => 'DC'], ['name' => 'Del Carmen', 'is_active' => true]);
        $building = Building::firstOrCreate(['campus_id' => $campus->id, 'name' => 'Academic'], ['is_active' => true]);
        $category = WorkOrderCategory::firstOrCreate(['name' => 'Electrical'], ['is_active' => true]);

        return WorkOrder::create([
            'work_order_number' => $number, 'requester_id' => $requester->id, 'campus_id' => $campus->id, 'building_id' => $building->id,
            'work_order_category_id' => $category->id, 'subject' => 'Repair light', 'description' => 'Light is broken.', 'urgency' => 'NORMAL',
            'status' => 'SUBMITTED', 'submitted_at' => now(),
        ]);
    }
}
