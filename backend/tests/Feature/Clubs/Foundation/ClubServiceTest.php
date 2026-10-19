<?php

namespace Tests\Feature\Clubs\Foundation;

use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Exceptions\ClubDomainException;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubCompetition;
use App\Models\ClubInviteCode;
use App\Models\ClubMember;
use App\Models\User;
use App\Services\Clubs\ClubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

class ClubServiceTest extends TestCase
{
    use CreatesClubs;
    use RefreshDatabase;

    private ClubService $clubs;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clubs = app(ClubService::class);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function expectDomain(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected {$code}");
        } catch (ClubDomainException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    public function test_create_with_an_owner(): void
    {
        $owner = User::factory()->create();

        $club = $this->clubs->create([
            'name' => 'KFC Example', 'club_type' => 'sports_club', 'city' => 'Gent', 'country_code' => 'BE',
            'contact_email' => 'secretary@example.test', 'owner_user_id' => $owner->id,
            'settings' => ['member_list_visibility' => 'members'],
        ], $this->admin);

        $this->assertSame('kfc-example', $club->slug);
        $this->assertSame(ClubStatus::Active, $club->status);
        $this->assertSame(26, strlen($club->public_id));
        $this->assertFalse($club->needs_owner);
        $this->assertSame(1, $club->members_count);
        $this->assertSame($this->admin->id, $club->created_by_user_id);
        $this->assertTrue($club->memberListVisibleToMembers());
        $this->assertSame(ClubRole::Owner, ClubMember::where('club_id', $club->id)->first()->role);
        $this->assertSame(['club.created', 'member.added'], ClubAuditEvent::orderBy('id')->pluck('action')->all());
        $this->assertStringNotContainsString('secretary@', json_encode(ClubAuditEvent::pluck('metadata')));
    }

    public function test_create_without_owner_is_flagged_and_slugs_stay_unique(): void
    {
        $a = $this->clubs->create(['name' => 'Same Name'], $this->admin);
        $b = $this->clubs->create(['name' => 'Same Name'], $this->admin);

        $this->assertTrue($a->needs_owner);
        $this->assertSame('same-name', $a->slug);
        $this->assertSame('same-name-2', $b->slug);
    }

    public function test_update_uses_the_optimistic_version(): void
    {
        $club = $this->makeClub(['name' => 'Old']);

        $updated = $this->clubs->update($club, ['name' => 'New', 'city' => 'Leuven', 'contact_email' => 'x@example.test'], $this->admin, 1);

        $this->assertSame('New', $updated->name);
        $this->assertSame(2, $updated->version);
        $event = ClubAuditEvent::where('action', 'club.updated')->firstOrFail();
        $this->assertEqualsCanonicalizing(['name', 'city', 'contact_email'], $event->metadata['fields']);
        $this->assertArrayNotHasKey('contact_email', $event->metadata['after']);

        // A second admin still holding version 1 is refused, nothing overwritten (AT-ADM-06).
        $this->expectDomain(ClubDomainException::STALE_VERSION, fn () => $this->clubs->update(Club::find($club->id), ['name' => 'Stale'], $this->admin, 1));
        $this->assertSame('New', $club->fresh()->name);
    }

    public function test_status_machine(): void
    {
        $club = $this->makeClub();

        $this->clubs->suspend($club, $this->admin, 'Payment dispute');
        $this->assertSame(ClubStatus::Suspended, $club->fresh()->status);
        $this->assertSame('Payment dispute', $club->fresh()->suspension_reason);

        $this->clubs->reactivate($club, $this->admin);
        $this->assertSame(ClubStatus::Active, $club->fresh()->status);
        $this->assertNull($club->fresh()->suspended_at);

        $this->clubs->archive($club, $this->admin, 'Season over');
        $this->assertSame(ClubStatus::Archived, $club->fresh()->status);

        // archived → suspended is not a valid transition.
        $this->expectDomain(ClubDomainException::CLUB_STATUS_INVALID, fn () => $this->clubs->suspend($club, $this->admin, 'x'));

        $this->clubs->reactivate($club, $this->admin);
        $this->assertSame(ClubStatus::Active, $club->fresh()->status);

        $this->assertSame(
            ['club.suspended', 'club.reactivated', 'club.archived', 'club.reactivated'],
            ClubAuditEvent::orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame('Payment dispute', ClubAuditEvent::where('action', 'club.suspended')->first()->metadata['reason']);
    }

    public function test_repeated_transition_is_idempotent(): void
    {
        $club = $this->makeClub();
        $this->clubs->suspend($club, $this->admin, 'x');
        $this->clubs->suspend($club, $this->admin, 'x');

        $this->assertSame(1, ClubAuditEvent::where('action', 'club.suspended')->count());
    }

    public function test_delete_if_empty(): void
    {
        $empty = $this->clubs->create(['name' => 'Typo Club', 'owner_user_id' => User::factory()->create()->id], $this->admin);
        ClubInviteCode::factory()->create(['club_id' => $empty->id]);

        $this->clubs->deleteIfEmpty($empty, $this->admin);

        $this->assertNull(Club::find($empty->id));
        $deleted = ClubAuditEvent::where('action', 'club.deleted')->firstOrFail();
        $this->assertNull($deleted->club_id, 'the audit row survives the hard delete');
        $this->assertSame('typo-club', $deleted->metadata['slug']);
    }

    public function test_delete_refused_with_history(): void
    {
        $withPack = $this->makeClub();
        $this->makeClubPack($withPack, 1);

        $withCompetition = $this->makeClub();
        ClubCompetition::factory()->create(['club_id' => $withCompetition->id]);

        $withJoin = $this->makeClub();
        $this->addMember($withJoin, User::factory()->create()); // a plain member = someone joined

        foreach ([$withPack, $withCompetition, $withJoin] as $club) {
            $this->expectDomain(ClubDomainException::CLUB_HAS_HISTORY, fn () => $this->clubs->deleteIfEmpty($club, $this->admin));
            $this->assertNotNull(Club::find($club->id));
        }
    }

    public function test_update_settings_audits_before_and_after_of_changed_keys_only(): void
    {
        $club = $this->makeClub();

        $this->clubs->updateSettings($club, [
            'member_list_visibility' => 'members',
            'join_enabled'           => false,
            'max_members'            => 120,
            'leaderboard_identity'   => 'username', // unchanged
            'unknown_key'            => 'ignored',
        ], $this->admin, 'platform_admin');

        $fresh = $club->fresh();
        $this->assertFalse($fresh->join_enabled);
        $this->assertSame(120, $fresh->max_members);
        $this->assertSame('members', $fresh->setting('member_list_visibility'));
        $this->assertArrayNotHasKey('unknown_key', $fresh->settings);

        $event = ClubAuditEvent::where('action', 'club.settings_changed')->firstOrFail();
        $this->assertSame(['join_enabled' => true, 'max_members' => null, 'member_list_visibility' => 'admins'], $event->metadata['before']);
        $this->assertSame(['join_enabled' => false, 'max_members' => 120, 'member_list_visibility' => 'members'], $event->metadata['after']);

        // No change → no event.
        $this->clubs->updateSettings($club->fresh(), ['join_enabled' => false], $this->admin, 'platform_admin');
        $this->assertSame(1, ClubAuditEvent::where('action', 'club.settings_changed')->count());
    }

    public function test_invalid_setting_values_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->clubs->updateSettings($this->makeClub(), ['member_list_visibility' => 'everyone'], $this->admin, 'platform_admin');
    }
}
