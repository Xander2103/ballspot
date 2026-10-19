<?php

namespace Tests\Feature\Clubs\Admin;

use App\Contracts\PackAccessGate;
use App\Enums\ClubRole;
use App\Exceptions\ClubDomainException;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\User;
use App\Services\Clubs\ClubInviteCodeService;
use App\Services\Clubs\ClubMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Create / edit / settings through the admin forms, AT-ADM-03 (suspend /
 * archive / reactivate effects + audit with reason), AT-ADM-04 (delete only
 * when empty) and AT-ADM-06 (stale version, no overwrite).
 */
class AdminClubLifecycleTest extends TestCase
{
    use CreatesClubs, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'          => 'KFC Example',
            'slug'          => '',
            'description'   => 'Our club',
            'club_type'     => 'sports_club',
            'sport_id'      => $this->clubSport()->id,
            'city'          => 'Gent',
            'region'        => 'Oost-Vlaanderen',
            'country_code'  => 'be',
            'external_ref'  => 'KBVB-1',
            'contact_name'  => 'Jan Peeters',
            'contact_email' => 'Jan@Example.test',
            'timezone'      => 'Europe/Brussels',
            'join_enabled'  => '1',
        ], $overrides);
    }

    // ------------------------------------------------------------ create

    public function test_create_with_first_owner_and_logo(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['username' => 'jan_owner', 'email' => 'jan.owner@example.test']);

        $res = $this->actingAs($this->admin)->post('/admin/clubs', $this->payload([
            'owner' => 'JAN.OWNER@example.test',
            'logo'  => UploadedFile::fake()->image('logo.png', 400, 300),
        ]));

        $club = Club::where('name', 'KFC Example')->firstOrFail();
        $res->assertRedirect('/admin/clubs/' . $club->id)->assertSessionHas('success');

        $this->assertSame('kfc-example', $club->slug);
        $this->assertSame('BE', $club->country_code);
        $this->assertSame('jan@example.test', $club->contact_email);
        $this->assertTrue($club->join_enabled);
        $this->assertFalse($club->needs_owner);
        $this->assertSame(1, $club->members_count);
        $this->assertSame(ClubRole::Owner, $club->members()->first()->role);
        $this->assertSame($owner->id, $club->members()->first()->user_id);
        $this->assertStringStartsWith('clubs/logos/', $club->logo_path);
        Storage::disk('public')->assertExists($club->logo_path);

        $actions = ClubAuditEvent::where('club_id', $club->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['club.created', 'member.added', 'club.updated'], $actions);
        $this->assertStringNotContainsString('example.test', (string) ClubAuditEvent::pluck('metadata')->toJson(), 'no emails in the audit trail');
    }

    public function test_create_without_owner_flags_needs_owner_and_join_can_be_off(): void
    {
        $this->actingAs($this->admin)->post('/admin/clubs', $this->payload(['join_enabled' => '0', 'max_members' => '40']))->assertRedirect();

        $club = Club::firstOrFail();
        $this->assertTrue($club->needs_owner);
        $this->assertFalse($club->join_enabled);
        $this->assertSame(40, $club->max_members);
    }

    public function test_create_validation_keeps_input_and_writes_nothing(): void
    {
        $this->actingAs($this->admin)->from('/admin/clubs/create')
            ->post('/admin/clubs', $this->payload(['name' => '', 'country_code' => 'Belgium', 'owner' => 'nobody-here', 'timezone' => 'Mars/Base']))
            ->assertRedirect('/admin/clubs/create')
            ->assertSessionHasErrors(['name', 'country_code', 'timezone'])
            ->assertSessionHasInput('city', 'Gent');

        $this->actingAs($this->admin)->from('/admin/clubs/create')
            ->post('/admin/clubs', $this->payload(['owner' => 'nobody-here']))
            ->assertSessionHasErrors(['owner' => 'No active user with this username or email.']);

        $this->assertSame(0, Club::count());
        $this->assertSame(0, ClubAuditEvent::count());
    }

    public function test_slug_is_made_unique(): void
    {
        $this->makeClub(['slug' => 'kfc-example']);
        $this->actingAs($this->admin)->post('/admin/clubs', $this->payload())->assertRedirect();

        $this->assertSame('kfc-example-2', Club::where('name', 'KFC Example')->value('slug'));
    }

    // ------------------------------------------------------------ edit (AT-ADM-06)

    public function test_edit_bumps_version_and_audits_changed_fields(): void
    {
        $club = $this->makeClub(['name' => 'Old Name', 'city' => 'Gent']);

        $this->actingAs($this->admin)->put('/admin/clubs/' . $club->id, $this->payload(['name' => 'New Name', 'slug' => $club->slug, 'version' => 1]))
            ->assertRedirect('/admin/clubs/' . $club->id)
            ->assertSessionHas('success', 'Club details saved.');

        $fresh = $club->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame(2, $fresh->version);
        $event = ClubAuditEvent::where('action', 'club.updated')->firstOrFail();
        $this->assertContains('name', $event->metadata['fields']);
        $this->assertArrayNotHasKey('contact_email', $event->metadata['after'], 'personal data never in audit values');
    }

    public function test_stale_version_is_refused_without_overwriting(): void
    {
        $club = $this->makeClub(['name' => 'Original']);

        // Admin A saves first (version 1 → 2).
        $this->actingAs($this->admin)->put('/admin/clubs/' . $club->id, $this->payload(['name' => 'Saved by A', 'version' => 1]))->assertRedirect();

        // Admin B still holds version 1.
        $other = User::factory()->create(['is_admin' => true]);
        $this->actingAs($other)->from('/admin/clubs/' . $club->id . '/edit')
            ->put('/admin/clubs/' . $club->id, $this->payload(['name' => 'Saved by B', 'version' => 1]))
            ->assertRedirect('/admin/clubs/' . $club->id . '/edit')
            ->assertSessionHas('error', __('clubs.errors.stale_version'))
            ->assertSessionHasInput('name', 'Saved by B');

        $this->assertSame('Saved by A', $club->fresh()->name);
        $this->assertSame(1, ClubAuditEvent::where('action', 'club.updated')->count());

        // The settings form uses the same optimistic version.
        $this->actingAs($other)->from('/admin/clubs/' . $club->id . '?tab=settings')
            ->put('/admin/clubs/' . $club->id . '/settings', $this->settings(['version' => 1, 'join_enabled' => '0']))
            ->assertSessionHas('error', __('clubs.errors.stale_version'));
        $this->assertTrue($club->fresh()->join_enabled);
    }

    public function test_edit_replaces_and_removes_the_logo(): void
    {
        Storage::fake('public');
        $club = $this->makeClub();

        $this->actingAs($this->admin)->put('/admin/clubs/' . $club->id, $this->payload(['version' => 1, 'logo' => UploadedFile::fake()->image('a.jpg', 200, 200)]))->assertRedirect();
        $first = $club->fresh()->logo_path;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($this->admin)->put('/admin/clubs/' . $club->id, $this->payload(['version' => $club->fresh()->version, 'remove_logo' => '1']))->assertRedirect();
        $this->assertNull($club->fresh()->logo_path);
        Storage::disk('public')->assertMissing($first);
    }

    // ------------------------------------------------------------ settings

    private function settings(array $overrides = []): array
    {
        return array_merge([
            'version'                    => 1,
            'join_enabled'               => '1',
            'max_members'                => '',
            'member_list_visibility'     => 'admins',
            'leaderboard_identity'       => 'username',
            'default_replay_mode'        => 'single_attempt',
            'default_normal_play_policy' => 'blocked',
            'timezone'                   => 'Europe/Brussels',
        ], $overrides);
    }

    public function test_settings_are_saved_audited_and_validated(): void
    {
        $club = $this->makeClub(['timezone' => 'Europe/Brussels']);

        $this->actingAs($this->admin)->put('/admin/clubs/' . $club->id . '/settings', $this->settings([
            'join_enabled' => '0', 'max_members' => '120', 'member_list_visibility' => 'members',
            'default_replay_mode' => 'multiple_attempts', 'default_normal_play_policy' => 'allowed', 'timezone' => 'Europe/Amsterdam',
        ]))->assertRedirect('/admin/clubs/' . $club->id . '?tab=settings');

        $fresh = $club->fresh();
        $this->assertFalse($fresh->join_enabled);
        $this->assertSame(120, $fresh->max_members);
        $this->assertSame('Europe/Amsterdam', $fresh->timezone);
        $this->assertSame('members', $fresh->setting('member_list_visibility'));
        $this->assertSame('multiple_attempts', $fresh->setting('default_replay_mode'));
        $this->assertSame('allowed', $fresh->setting('default_normal_play_policy'));
        $this->assertSame('username', $fresh->setting('leaderboard_identity'));
        $this->assertSame(2, $fresh->version);

        $event = ClubAuditEvent::where('action', 'club.settings_changed')->firstOrFail();
        $this->assertSame(['join_enabled' => false, 'max_members' => 120, 'timezone' => 'Europe/Amsterdam', 'member_list_visibility' => 'members',
            'default_replay_mode' => 'multiple_attempts', 'default_normal_play_policy' => 'allowed'], $event->metadata['after']);

        $this->actingAs($this->admin)->from('/admin/clubs/' . $club->id . '?tab=settings')
            ->put('/admin/clubs/' . $club->id . '/settings', $this->settings(['version' => 2, 'member_list_visibility' => 'everyone', 'default_replay_mode' => 'endless']))
            ->assertSessionHasErrors(['member_list_visibility', 'default_replay_mode']);

        // The defaults resolve from config when never set.
        $this->assertSame('blocked', $this->makeClub()->setting('default_normal_play_policy'));
    }

    // ------------------------------------------------------------ AT-ADM-03

    public function test_suspend_blocks_play_and_join_and_reactivate_restores(): void
    {
        $club   = $this->makeClub([], User::factory()->create());
        $member = User::factory()->create();
        $this->addMember($club, $member);
        $pack   = $this->makeClubPack($club, 1);
        $code   = app(ClubInviteCodeService::class)->create($club, [], $this->admin, 'platform_admin');
        $gate   = app(PackAccessGate::class);

        // Reason is required.
        $this->actingAs($this->admin)->from('/admin/clubs')->post('/admin/clubs/' . $club->id . '/suspend', ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertTrue($club->fresh()->isActive());

        $this->actingAs($this->admin)->from('/admin/clubs')->post('/admin/clubs/' . $club->id . '/suspend', ['reason' => 'Unpaid federation fee'])
            ->assertRedirect('/admin/clubs')->assertSessionHas('success');

        $fresh = $club->fresh();
        $this->assertTrue($fresh->isSuspended());
        $this->assertSame('Unpaid federation fee', $fresh->suspension_reason);
        $event = ClubAuditEvent::where('action', 'club.suspended')->firstOrFail();
        $this->assertSame('Unpaid federation fee', $event->metadata['reason']);
        $this->assertSame($this->admin->id, $event->actor_user_id);

        $this->assertDomainCode(ClubDomainException::CLUB_UNAVAILABLE, fn () => $gate->assertCanPlay($member, $pack->fresh()));
        $joiner = User::factory()->create();
        $this->assertDomainCode(ClubDomainException::CLUB_CODE_INVALID, fn () => app(ClubMembershipService::class)->joinWithCode($joiner, $code->code, '127.0.0.1'));
        $this->assertSame(1, Club::listedForMembers()->whereKey($club->id)->count(), 'suspended clubs stay listed (read-only)');

        $this->actingAs($this->admin)->post('/admin/clubs/' . $club->id . '/reactivate')->assertRedirect();
        $this->assertTrue($club->fresh()->isActive());
        $gate->assertCanPlay($member, $pack->fresh());
        $this->assertTrue(app(ClubMembershipService::class)->joinWithCode($joiner, $code->code, '127.0.0.1')['joined']);
        $this->assertSame(1, ClubAuditEvent::where('action', 'club.reactivated')->count());
    }

    public function test_archive_hides_from_my_clubs_with_reason_and_can_be_reactivated(): void
    {
        $club = $this->makeClub();

        $this->actingAs($this->admin)->post('/admin/clubs/' . $club->id . '/archive', ['reason' => 'Club merged'])->assertRedirect();

        $this->assertTrue($club->fresh()->isArchived());
        $this->assertSame('Club merged', $club->fresh()->archived_reason);
        $this->assertSame(0, Club::listedForMembers()->whereKey($club->id)->count());
        $this->assertSame('Club merged', ClubAuditEvent::where('action', 'club.archived')->firstOrFail()->metadata['reason']);

        // Suspending an archived club is not a valid transition.
        $this->actingAs($this->admin)->from('/admin/clubs/' . $club->id)->post('/admin/clubs/' . $club->id . '/suspend', ['reason' => 'Nope'])
            ->assertRedirect('/admin/clubs/' . $club->id)
            ->assertSessionHas('error', __('clubs.errors.club_status_invalid'));

        $this->actingAs($this->admin)->post('/admin/clubs/' . $club->id . '/reactivate')->assertRedirect();
        $this->assertTrue($club->fresh()->isActive());
        $this->assertNull($club->fresh()->archived_reason);
    }

    // ------------------------------------------------------------ AT-ADM-04

    public function test_delete_is_refused_with_history_and_allowed_for_an_empty_club(): void
    {
        $withHistory = $this->makeClub(['name' => 'Has Packs']);
        $this->makeClubPack($withHistory, 1);

        $this->actingAs($this->admin)->from('/admin/clubs/' . $withHistory->id)
            ->delete('/admin/clubs/' . $withHistory->id)
            ->assertRedirect('/admin/clubs/' . $withHistory->id)
            ->assertSessionHas('error', __('clubs.errors.club_has_history'));
        $this->assertNotNull($withHistory->fresh());
        $this->actingAs($this->admin)->get('/admin/clubs/' . $withHistory->id)->assertSee('Delete is not possible: this club has history.');

        $joined = $this->makeClub(['name' => 'Has Member']);
        $this->addMember($joined, User::factory()->create());
        $this->actingAs($this->admin)->delete('/admin/clubs/' . $joined->id)->assertSessionHas('error');
        $this->assertNotNull($joined->fresh());

        $empty = $this->makeClub(['name' => 'Created By Mistake'], User::factory()->create()); // an assigned owner is not history
        $this->actingAs($this->admin)->get('/admin/clubs/' . $empty->id)->assertSee('Delete permanently…');
        $this->actingAs($this->admin)->delete('/admin/clubs/' . $empty->id)
            ->assertRedirect('/admin/clubs')
            ->assertSessionHas('success', 'Club "Created By Mistake" deleted.');
        $this->assertNull(Club::find($empty->id));
        $this->assertSame(1, ClubAuditEvent::where('action', 'club.deleted')->whereNull('club_id')->count());
    }

    private function assertDomainCode(string $code, callable $fn): void
    {
        try {
            $fn();
        } catch (ClubDomainException $e) {
            $this->assertSame($code, $e->errorCode());

            return;
        }
        $this->fail("Expected ClubDomainException {$code}");
    }
}
