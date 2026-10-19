<?php

namespace Tests\Feature\Clubs\Foundation;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Exceptions\ClubDomainException;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubInviteCode;
use App\Models\ClubMember;
use App\Models\User;
use App\Services\Clubs\ClubInviteCodeService;
use App\Services\Clubs\ClubJoinFailureCounter;
use App\Services\Clubs\ClubMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Membership & invite codes at service level (plan §13 AT-JOIN-01…06).
 */
class ClubMembershipServiceTest extends TestCase
{
    use CreatesClubs;
    use RefreshDatabase;

    private ClubMembershipService $service;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ClubMembershipService::class);
        $this->admin   = User::factory()->create(['is_admin' => true]);
    }

    private function code(Club $club, array $attrs = []): ClubInviteCode
    {
        return ClubInviteCode::factory()->create(array_merge(['club_id' => $club->id, 'code' => 'ABCDEFGH'], $attrs));
    }

    private function assertDomainError(string $code, callable $fn): ClubDomainException
    {
        try {
            $fn();
        } catch (ClubDomainException $e) {
            $this->assertSame($code, $e->errorCode());

            return $e;
        }
        $this->fail("Expected ClubDomainException [{$code}].");
    }

    // ------------------------------------------------------------------ AT-JOIN-01

    public function test_valid_code_in_any_case_or_with_dash_joins_and_repeat_is_idempotent(): void
    {
        $club = $this->makeClub([], User::factory()->create());
        $code = $this->code($club);
        $user = User::factory()->create();

        $first = $this->service->joinWithCode($user, ' abcd-efgh ', '10.0.0.1');

        $this->assertTrue($first['joined']);
        $this->assertSame($club->id, $first['club']->id);
        $this->assertSame(ClubRole::Member, $first['member']->role);
        $this->assertSame(1, $code->fresh()->uses_count);
        $this->assertSame(2, $club->fresh()->members_count);
        $this->assertNotNull($club->fresh()->last_activity_at);

        $again = $this->service->joinWithCode($user, 'AB CD EF GH', null);

        $this->assertFalse($again['joined']);
        $this->assertSame($first['member']->id, $again['member']->id);
        $this->assertSame(1, $code->fresh()->uses_count, 'uses_count counts once');
        $this->assertSame(1, ClubAuditEvent::where('action', 'member.joined')->count());

        $event = ClubAuditEvent::where('action', 'member.joined')->first();
        $this->assertSame(ClubAuditEvent::ACTOR_MEMBER, $event->actor_kind);
        $this->assertSame('ABCD-••••', $event->metadata['code_masked']);
        $this->assertStringNotContainsString('ABCDEFGH', json_encode($event->metadata));
    }

    public function test_code_grants_its_configured_role(): void
    {
        $club = $this->makeClub();
        $this->code($club, ['grants_role' => ClubRole::Admin]);

        $result = $this->service->joinWithCode(User::factory()->create(), 'ABCDEFGH', null);

        $this->assertSame(ClubRole::Admin, $result['member']->role);
    }

    public function test_normalise_and_format(): void
    {
        $codes = app(ClubInviteCodeService::class);

        $this->assertSame('ABCDEFGH', $codes->normalise(' abcd-efgh '));
        $this->assertSame('ABCDEFGH', $codes->normalise("ab cd\tef-gh"));
        $this->assertSame('ABCD-EFGH', $codes->format('abcdefgh'));
    }

    // ------------------------------------------------------------------ AT-JOIN-02

    public function test_every_unusable_code_answers_the_same_invalid_error(): void
    {
        $user = User::factory()->create();

        $cases = [
            'unknown'       => fn () => null,
            'revoked'       => fn () => $this->code($this->makeClub(), ['code' => 'REVOKED2', 'revoked_at' => now()]),
            'expired'       => fn () => $this->code($this->makeClub(), ['code' => 'EXPIRED2', 'expires_at' => now()->subMinute()]),
            'exhausted'     => fn () => $this->code($this->makeClub(), ['code' => 'EXHAUST2', 'max_uses' => 2, 'uses_count' => 2]),
            'join_disabled' => fn () => $this->code($this->makeClub(['join_enabled' => false]), ['code' => 'DISABLE2']),
            'suspended'     => fn () => $this->code($this->makeClub(['status' => ClubStatus::Suspended]), ['code' => 'SUSPEND2']),
            'archived'      => fn () => $this->code($this->makeClub(['status' => ClubStatus::Archived]), ['code' => 'ARCHIVE2']),
        ];

        $messages = [];
        foreach ($cases as $label => $make) {
            $code = $make();
            $raw  = $code?->code ?? 'NOPE2345';
            $e = $this->assertDomainError(ClubDomainException::CLUB_CODE_INVALID, fn () => $this->service->joinWithCode($user, $raw, null));
            $this->assertSame(422, $e->status(), $label);
            $this->assertSame([], $e->extra(), $label);
            $messages[] = $e->getMessage();
        }

        $this->assertCount(1, array_unique($messages), 'no signal about WHY a code is invalid');
        $this->assertSame(0, ClubMember::count());
        $this->assertSame(count($cases), app(ClubJoinFailureCounter::class)->failures($user));
    }

    // ------------------------------------------------------------------ AT-JOIN-03

    public function test_failed_codes_lock_joining_and_a_valid_code_does_not_count(): void
    {
        config(['ballspot.clubs.join.failures_per_hour' => 3]);
        $club = $this->makeClub();
        $this->code($club);
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->assertDomainError(ClubDomainException::CLUB_CODE_INVALID, fn () => $this->service->joinWithCode($user, 'WRONG234', null));
        }

        // Locked now — even the correct code is refused, with retry_after.
        $e = $this->assertDomainError(ClubDomainException::CLUB_JOIN_LOCKED, fn () => $this->service->joinWithCode($user, 'ABCDEFGH', null));
        $this->assertSame(429, $e->status());
        $this->assertGreaterThan(0, $e->extra()['retry_after']);
        $this->assertSame(0, ClubMember::count());

        // Another user is unaffected, and a successful join is not a failure.
        $other = User::factory()->create();
        $this->service->joinWithCode($other, 'ABCDEFGH', null);
        $this->assertSame(0, app(ClubJoinFailureCounter::class)->failures($other));

        // The window decays.
        $this->travel(61)->minutes();
        $this->assertTrue($this->service->joinWithCode($user, 'ABCDEFGH', null)['joined']);
    }

    // ------------------------------------------------------------------ AT-JOIN-04

    public function test_club_member_cap_refuses_with_club_full(): void
    {
        $club = $this->makeClub(['max_members' => 2]);
        $this->addMember($club, User::factory()->create());
        $this->addMember($club, User::factory()->create());
        $code = $this->code($club);

        $this->assertDomainError(ClubDomainException::CLUB_FULL, fn () => $this->service->joinWithCode(User::factory()->create(), 'ABCDEFGH', null));
        $this->assertSame(0, $code->fresh()->uses_count);
    }

    public function test_config_member_cap_applies_without_a_club_override(): void
    {
        config(['ballspot.clubs.limits.max_members' => 1]);
        $club = $this->makeClub();
        $this->addMember($club, User::factory()->create());
        $this->code($club);

        $this->assertDomainError(ClubDomainException::CLUB_FULL, fn () => $this->service->joinWithCode(User::factory()->create(), 'ABCDEFGH', null));
    }

    public function test_per_player_club_cap_refuses_with_club_membership_limit(): void
    {
        config(['ballspot.clubs.limits.max_clubs_per_user' => 2]);
        $user = User::factory()->create();
        $this->addMember($this->makeClub(), $user);
        $this->addMember($this->makeClub(), $user);
        // An archived club does not count against the cap.
        $this->addMember($this->makeClub(['status' => ClubStatus::Archived]), $user);
        $club = $this->makeClub();
        $this->code($club);

        $this->assertDomainError(ClubDomainException::CLUB_MEMBERSHIP_LIMIT, fn () => $this->service->joinWithCode($user, 'ABCDEFGH', null));
    }

    // ------------------------------------------------------------------ AT-JOIN-05

    public function test_banned_user_with_a_valid_code_gets_the_generic_invalid_error(): void
    {
        $club = $this->makeClub();
        $code = $this->code($club);
        $user = User::factory()->create();
        $this->addMember($club, $user, ClubRole::Member, ClubMemberStatus::Banned);

        $this->assertDomainError(ClubDomainException::CLUB_CODE_INVALID, fn () => $this->service->joinWithCode($user, 'ABCDEFGH', null));
        $this->assertSame(ClubMemberStatus::Banned, ClubMember::first()->status);
        $this->assertSame(0, $code->fresh()->uses_count);
        $this->assertSame(1, app(ClubJoinFailureCounter::class)->failures($user));
    }

    public function test_left_and_removed_users_rejoin_by_reactivating_their_row(): void
    {
        $club = $this->makeClub();
        $this->code($club);

        foreach ([ClubMemberStatus::Left, ClubMemberStatus::Removed] as $status) {
            $user = User::factory()->create();
            $row  = $this->addMember($club, $user, ClubRole::Admin, $status);

            $result = $this->service->joinWithCode($user, 'ABCDEFGH', null);

            $this->assertTrue($result['joined']);
            $this->assertSame($row->id, $result['member']->id, 'the history row is reused');
            $this->assertSame(ClubMemberStatus::Active, $result['member']->fresh()->status);
            $this->assertSame(ClubRole::Member, $result['member']->fresh()->role, 'role comes from the code');
            $this->assertNull($result['member']->fresh()->left_at);
        }

        $this->assertSame(2, ClubMember::count());
    }

    public function test_unban_allows_rejoining(): void
    {
        $club = $this->makeClub();
        $this->code($club);
        $user = User::factory()->create();
        $member = $this->addMember($club, $user);

        $this->service->ban($member, $this->admin, ClubAuditEvent::ACTOR_PLATFORM_ADMIN, 'spam');
        $this->assertNull($this->service->membership($club, $user));
        $this->assertSame('spam', $member->fresh()->removal_reason);

        $this->service->unban($member, $this->admin, ClubAuditEvent::ACTOR_PLATFORM_ADMIN);
        $this->assertSame(ClubMemberStatus::Removed, $member->fresh()->status);

        $this->assertTrue($this->service->joinWithCode($user, 'ABCDEFGH', null)['joined']);
        $this->assertSame(
            ['member.banned', 'member.unbanned', 'member.joined'],
            ClubAuditEvent::orderBy('id')->pluck('action')->all(),
        );
    }

    // ------------------------------------------------------------------ AT-JOIN-06

    public function test_sole_owner_cannot_leave_but_a_co_owner_and_members_can(): void
    {
        $owner = User::factory()->create();
        $club  = $this->makeClub([], $owner);
        $member = User::factory()->create();
        $this->addMember($club, $member);

        $this->assertDomainError(ClubDomainException::CLUB_OWNER_MUST_TRANSFER, fn () => $this->service->leave($club, $owner));

        $this->service->leave($club, $member);
        $this->assertSame(ClubMemberStatus::Left, ClubMember::where('user_id', $member->id)->first()->status);
        $this->assertSame(1, $club->fresh()->members_count);

        $coOwner = User::factory()->create();
        $this->addMember($club, $coOwner, ClubRole::Owner);
        $this->service->leave($club, $owner);
        $this->assertNull($this->service->membership($club, $owner));
        $this->assertFalse($club->fresh()->needs_owner);

        $this->assertDomainError(ClubDomainException::CLUB_NOT_FOUND, fn () => $this->service->leave($club, $member));
    }

    // ------------------------------------------------------------------ roles & owners

    public function test_last_owner_cannot_be_demoted_removed_or_banned(): void
    {
        $owner = User::factory()->create();
        $club  = $this->makeClub([], $owner);
        $row   = ClubMember::where('user_id', $owner->id)->first();

        $this->assertDomainError(ClubDomainException::CLUB_LAST_OWNER, fn () => $this->service->changeRole($row, ClubRole::Admin, $this->admin, 'platform_admin'));
        $this->assertDomainError(ClubDomainException::CLUB_LAST_OWNER, fn () => $this->service->remove($row, $this->admin, 'platform_admin', null));
        $this->assertDomainError(ClubDomainException::CLUB_LAST_OWNER, fn () => $this->service->ban($row, $this->admin, 'platform_admin', null));
        $this->assertSame(ClubRole::Owner, $row->fresh()->role);
    }

    public function test_change_role_is_audited_with_from_and_to(): void
    {
        $club = $this->makeClub([], User::factory()->create());
        $row  = $this->addMember($club, User::factory()->create());

        $this->service->changeRole($row, ClubRole::Admin, $this->admin, 'platform_admin');

        $this->assertSame(ClubRole::Admin, $row->role);
        $event = ClubAuditEvent::where('action', 'member.role_changed')->firstOrFail();
        $this->assertSame(['target_user_id' => $row->user_id, 'from_role' => 'member', 'to_role' => 'admin'], $event->metadata);
        $this->assertSame($this->admin->id, $event->actor_user_id);
    }

    public function test_transfer_ownership_demotes_previous_owners_to_admin(): void
    {
        $owner = User::factory()->create();
        $club  = $this->makeClub([], $owner);
        $next  = User::factory()->create();
        $this->addMember($club, $next);

        $this->service->transferOwnership($club, $next, $owner, ClubAuditEvent::ACTOR_CLUB_ADMIN);

        $this->assertSame(ClubRole::Admin, ClubMember::where('user_id', $owner->id)->first()->role);
        $this->assertSame(ClubRole::Owner, ClubMember::where('user_id', $next->id)->first()->role);
        $this->assertSame(1, $this->service->activeOwnerCount($club));
        $event = ClubAuditEvent::where('action', 'member.ownership_transferred')->firstOrFail();
        $this->assertSame([$owner->id], $event->metadata['previous_owner_ids']);

        $this->assertDomainError(
            ClubDomainException::CLUB_MEMBER_NOT_FOUND,
            fn () => $this->service->transferOwnership($club, User::factory()->create(), $owner, 'club_admin'),
        );
    }

    public function test_admin_add_is_idempotent_refuses_banned_and_flags_owner(): void
    {
        $club = $this->makeClub();
        $this->assertTrue($club->needs_owner, 'a club without an owner is flagged (D6)');

        $owner = User::factory()->create();
        $row   = $this->service->add($club, $owner, ClubRole::Owner, $this->admin, 'platform_admin');
        $again = $this->service->add($club, $owner, ClubRole::Member, $this->admin, 'platform_admin');

        $this->assertSame($row->id, $again->id);
        $this->assertSame(ClubRole::Owner, $again->role);
        $this->assertFalse($club->fresh()->needs_owner);
        $this->assertSame($this->admin->id, $row->added_by_user_id);
        $this->assertSame(1, ClubAuditEvent::where('action', 'member.added')->count());

        $banned = User::factory()->create();
        $this->addMember($club, $banned, ClubRole::Member, ClubMemberStatus::Banned);
        $this->assertDomainError(ClubDomainException::CLUB_MEMBER_BANNED, fn () => $this->service->add($club, $banned, ClubRole::Member, $this->admin, 'platform_admin'));
    }

    public function test_remove_ends_the_membership_and_is_audited_with_reason(): void
    {
        $club = $this->makeClub([], User::factory()->create());
        $user = User::factory()->create();
        $row  = $this->addMember($club, $user);

        $this->service->remove($row, $this->admin, 'platform_admin', 'Left the team');

        $this->assertSame(ClubMemberStatus::Removed, $row->fresh()->status);
        $this->assertSame($this->admin->id, $row->fresh()->removed_by_user_id);
        $this->assertSame(1, $club->fresh()->members_count);
        $this->assertSame('Left the team', ClubAuditEvent::where('action', 'member.removed')->first()->metadata['reason']);

        // Idempotent: removing again changes nothing and writes no second event.
        $this->service->remove($row->fresh(), $this->admin, 'platform_admin', null);
        $this->assertSame(1, ClubAuditEvent::where('action', 'member.removed')->count());

        // A member who left cannot be "removed" (nothing to end).
        $left = $this->addMember($club, User::factory()->create(), ClubRole::Member, ClubMemberStatus::Left);
        $this->assertDomainError(ClubDomainException::CLUB_MEMBER_NOT_FOUND, fn () => $this->service->remove($left, $this->admin, 'platform_admin', null));
    }

    public function test_account_deletion_hook_removes_memberships_and_flags_needs_owner(): void
    {
        $owner = User::factory()->create();
        $club  = $this->makeClub([], $owner);
        $other = $this->makeClub();
        $this->addMember($other, $owner);

        $this->service->removeAllForDeletedUser($owner);

        $this->assertSame(0, ClubMember::active()->where('user_id', $owner->id)->count());
        $this->assertTrue($club->fresh()->needs_owner);
        $this->assertSame(1, Club::needsOwner()->whereKey($club->id)->count());
        $this->assertSame(2, ClubAuditEvent::where('action', 'member.removed')->where('actor_kind', 'system')->count());
    }

    public function test_membership_lookup_is_memoised_per_request_and_ignores_inactive_rows(): void
    {
        $club = $this->makeClub();
        $user = User::factory()->create();
        $this->addMember($club, $user, ClubRole::Member, ClubMemberStatus::Left);

        $this->assertNull($this->service->membership($club, $user));
        $this->assertNotNull($this->service->membershipRecord($club, $user));

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->service->membership($club, $user);
        $this->service->membership($club, $user);
        $this->assertCount(0, \Illuminate\Support\Facades\DB::getQueryLog(), 'second lookup is memoised');
    }

    public function test_refresh_counters_recomputes_from_source_rows(): void
    {
        $club = $this->makeClub([], User::factory()->create());
        $this->makeClubPack($club, 1);
        $this->makeClubPack($club, 1, ['status' => 'draft']);
        Club::whereKey($club->id)->update(['members_count' => 99, 'active_packs_count' => 99]);

        $this->service->refreshCounters($club);

        $fresh = $club->fresh();
        $this->assertSame(1, $fresh->members_count);
        $this->assertSame(1, $fresh->active_packs_count);
        $this->assertSame(1, $fresh->version, 'counters never bump the edit version');
    }
}
