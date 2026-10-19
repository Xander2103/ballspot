<?php

namespace Tests\Feature\Clubs\Api;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Models\Club;
use App\Models\ClubInviteCode;
use App\Models\ClubMember;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * POST /api/clubs/join and POST /api/clubs/{club}/leave over HTTP
 * (AT-JOIN-01…06).
 */
class ClubJoinLeaveApiTest extends ClubApiTestCase
{
    private function code(Club $club, array $attrs = []): ClubInviteCode
    {
        return ClubInviteCode::factory()->create(array_merge(['club_id' => $club->id, 'code' => 'ABCDEFGH'], $attrs));
    }

    private function join(User $user, string $code)
    {
        return $this->api($user)->postJson('/api/clubs/join', ['code' => $code]);
    }

    // ------------------------------------------------------------ AT-JOIN-01

    public function test_a_valid_code_in_any_case_or_with_a_dash_joins_and_repeating_is_idempotent(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub(['name' => 'KFC Example', 'city' => 'Gent'], User::factory()->create());
        $this->makeClubPack($club, 2);
        $code = $this->code($club);

        $res = $this->join($user, 'abcd-efgh')->assertOk()
            ->assertJsonPath('joined', true)
            ->assertJsonPath('data.id', $club->public_id)
            ->assertJsonPath('data.name', 'KFC Example')
            ->assertJsonPath('data.city', 'Gent')
            ->assertJsonPath('data.role', 'member')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.packs_count', 1)
            ->assertJsonPath('data.competitions', ['active' => 0, 'upcoming' => 0]);

        $this->assertSame([
            'id', 'name', 'slug', 'logo_url', 'city', 'country_code', 'club_type', 'sport', 'role', 'status',
            'packs_count', 'competitions', 'joined_at',
        ], array_keys($res->json('data')));
        $this->assertNotNull($res->json('data.joined_at'));
        $this->assertSame(1, $code->fresh()->uses_count);

        $this->join($user, ' ab cd ef gh ')->assertOk()->assertJsonPath('joined', false)->assertJsonPath('data.id', $club->public_id);
        $this->join($user, 'ABCDEFGH')->assertOk()->assertJsonPath('joined', false);

        $this->assertSame(1, $code->fresh()->uses_count);
        $this->assertSame(1, ClubMember::where('club_id', $club->id)->where('user_id', $user->id)->count());
    }

    public function test_an_admin_code_grants_the_admin_role(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], User::factory()->create());
        $this->code($club, ['grants_role' => ClubRole::Admin]);

        $this->join($user, 'ABCDEFGH')->assertOk()->assertJsonPath('data.role', 'admin');
    }

    // ------------------------------------------------------------ AT-JOIN-02

    public function test_every_unusable_code_gives_the_identical_club_code_invalid_response(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], User::factory()->create());
        $this->code($club, ['code' => 'REVK2345', 'revoked_at' => now()]);
        $this->code($club, ['code' => 'EXPD2345', 'expires_at' => now()->subMinute()]);
        $this->code($club, ['code' => 'EXHS2345', 'max_uses' => 1, 'uses_count' => 1]);
        $disabled  = $this->makeClub(['join_enabled' => false], User::factory()->create());
        $this->code($disabled, ['code' => 'DSBL2345']);
        $suspended = $this->makeClub(['status' => ClubStatus::Suspended], User::factory()->create());
        $this->code($suspended, ['code' => 'SUSP2345']);
        $archived  = $this->makeClub(['status' => ClubStatus::Archived], User::factory()->create());
        $this->code($archived, ['code' => 'ARCH2345']);

        $bodies = [];
        foreach (['REVK2345', 'EXPD2345', 'EXHS2345', 'DSBL2345', 'SUSP2345', 'ARCH2345', 'NOPE2345', '!!', str_repeat('A', 40)] as $raw) {
            $bodies[$raw] = $this->join($user, $raw)->assertStatus(422)->assertJsonPath('code', 'club_code_invalid')->json();
        }

        $this->assertCount(1, array_unique(array_map('json_encode', $bodies)), 'Every failure must look the same');
        $this->assertSame(0, ClubMember::where('user_id', $user->id)->count());
    }

    public function test_a_missing_code_is_a_validation_error(): void
    {
        $this->api(User::factory()->create())->postJson('/api/clubs/join', [])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    // ------------------------------------------------------------ AT-JOIN-03

    public function test_fifteen_failed_codes_lock_joining_with_club_join_locked(): void
    {
        config(['ballspot.clubs.join.per_user_per_minute' => 100, 'ballspot.clubs.join.per_ip_per_hour' => 100]);
        $user = User::factory()->create();
        $club = $this->makeClub([], User::factory()->create());
        $this->code($club);

        for ($i = 0; $i < 15; $i++) {
            $this->join($user, 'WRNG' . str_pad((string) $i, 4, 'A'))->assertStatus(422)->assertJsonPath('code', 'club_code_invalid');
        }

        // Locked: even the valid code is refused now.
        $locked = $this->join($user, 'ABCDEFGH')->assertStatus(429)->assertJsonPath('code', 'club_join_locked');
        $this->assertGreaterThan(0, $locked->json('retry_after'));
        $this->assertNotNull($locked->headers->get('Retry-After'));
        $this->assertSame(0, ClubMember::where('user_id', $user->id)->count());

        // Another user is not affected.
        $this->join(User::factory()->create(), 'ABCDEFGH')->assertOk()->assertJsonPath('joined', true);
    }

    public function test_the_request_limiter_answers_429_club_join_rate_limited(): void
    {
        config(['ballspot.clubs.join.per_user_per_minute' => 3, 'ballspot.clubs.join.failures_per_hour' => 100]);
        $user = User::factory()->create();
        $club = $this->makeClub([], User::factory()->create());
        $this->code($club);

        for ($i = 0; $i < 3; $i++) {
            $this->join($user, 'ABCDEFGH')->assertOk();
        }
        $this->join($user, 'ABCDEFGH')->assertStatus(429)->assertJsonPath('code', 'club_join_rate_limited');
    }

    public function test_the_per_ip_limit_applies_across_users(): void
    {
        config(['ballspot.clubs.join.per_ip_per_hour' => 2, 'ballspot.clubs.join.failures_per_hour' => 100]);
        $club = $this->makeClub([], User::factory()->create());
        $this->code($club);

        $this->join(User::factory()->create(), 'ABCDEFGH')->assertOk();
        $this->join(User::factory()->create(), 'ABCDEFGH')->assertOk();
        $this->join(User::factory()->create(), 'ABCDEFGH')->assertStatus(429)->assertJsonPath('code', 'club_join_rate_limited');
        Cache::flush();
    }

    // ------------------------------------------------------------ AT-JOIN-04

    public function test_club_full_and_membership_limit(): void
    {
        $club = $this->makeClub(['max_members' => 2], User::factory()->create());
        $this->addMember($club, User::factory()->create());
        $this->code($club);

        $this->join(User::factory()->create(), 'ABCDEFGH')->assertStatus(422)->assertJsonPath('code', 'club_full');

        config(['ballspot.clubs.limits.max_clubs_per_user' => 2]);
        $busy = User::factory()->create();
        $this->addMember($this->makeClub(), $busy);
        $this->addMember($this->makeClub(), $busy);
        $other = $this->makeClub([], User::factory()->create());
        $this->code($other, ['code' => 'LMTS2345']);

        $this->join($busy, 'LMTS2345')->assertStatus(422)->assertJsonPath('code', 'club_membership_limit');
    }

    // ------------------------------------------------------------ AT-JOIN-05

    public function test_banned_users_are_refused_generically_and_left_users_are_reactivated(): void
    {
        $club = $this->makeClub([], User::factory()->create());
        $code = $this->code($club);

        $banned = User::factory()->create();
        $this->addMember($club, $banned, ClubRole::Member, ClubMemberStatus::Banned);
        $this->join($banned, 'ABCDEFGH')->assertStatus(422)->assertJsonPath('code', 'club_code_invalid');
        $this->assertSame(ClubMemberStatus::Banned, ClubMember::where('user_id', $banned->id)->first()->status);

        $left = User::factory()->create();
        $row  = $this->addMember($club, $left, ClubRole::Member, ClubMemberStatus::Left);
        $this->join($left, 'ABCDEFGH')->assertOk()->assertJsonPath('joined', true);
        $this->assertSame(ClubMemberStatus::Active, $row->fresh()->status);
        $this->assertSame(1, ClubMember::where('user_id', $left->id)->count());
        $this->assertSame(1, $code->fresh()->uses_count);
    }

    // ------------------------------------------------------------ AT-JOIN-06 + leave

    public function test_leave_and_the_sole_owner_rule(): void
    {
        $owner  = User::factory()->create();
        $member = User::factory()->create();
        $club   = $this->makeClub([], $owner);
        $this->addMember($club, $member);

        $this->api($owner)->postJson($this->clubPath($club, '/leave'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'club_owner_must_transfer');

        $this->api($member)->postJson($this->clubPath($club, '/leave'))->assertOk()->assertExactJson(['left' => true]);
        $this->assertSame(ClubMemberStatus::Left, ClubMember::where('user_id', $member->id)->first()->status);
        $this->assertSame(1, $club->fresh()->members_count);

        // Gone from My Clubs and the club itself is now 404 for them.
        $this->api($member)->getJson('/api/clubs')->assertOk()->assertJsonCount(0, 'data');
        $this->api($member)->getJson($this->clubPath($club))->assertNotFound()->assertJsonPath('code', 'club_not_found');
        $this->api($member)->postJson($this->clubPath($club, '/leave'))->assertNotFound();
    }

    public function test_a_co_owner_may_leave(): void
    {
        $owner = User::factory()->create();
        $co    = User::factory()->create();
        $club  = $this->makeClub([], $owner);
        $this->addMember($club, $co, ClubRole::Owner);

        $this->api($co)->postJson($this->clubPath($club, '/leave'))->assertOk();
    }

    public function test_members_of_a_suspended_club_may_still_leave(): void
    {
        $member = User::factory()->create();
        $club   = $this->makeClub([], User::factory()->create());
        $this->addMember($club, $member);
        $club->update(['status' => ClubStatus::Suspended]);

        $this->api($member)->postJson($this->clubPath($club, '/leave'))->assertOk();
    }
}
