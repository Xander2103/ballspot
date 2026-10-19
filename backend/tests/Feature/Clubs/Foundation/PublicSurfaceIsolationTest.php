<?php

namespace Tests\Feature\Clubs\Foundation;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Enums\NormalPlayPolicy;
use App\Exceptions\ClubDomainException;
use App\Models\Badge;
use App\Models\Challenge;
use App\Models\ClubCompetition;
use App\Models\DailyChallenge;
use App\Models\PackAttempt;
use App\Models\User;
use App\Services\Clubs\PackAccessResolver;
use App\Services\DailyChallengeScheduler;
use App\Services\LeagueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Phase-0 hardening: club content never reaches a public surface
 * (plan §2.11 / §6.5, AT-ISO-01/02/03, G2–G5, G7) and the normal-track
 * access gate (contracts §3.7).
 */
class PublicSurfaceIsolationTest extends TestCase
{
    use CreatesClubs;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableClubs();
    }

    // ------------------------------------------------------------ AT-ISO-01 / G2

    public function test_club_packs_never_appear_on_the_public_pack_endpoints_even_for_members(): void
    {
        $member = User::factory()->create();
        $club   = $this->makeClub([], $member);
        $clubPack   = $this->makeClubPack($club, 2);
        $publicPack = $this->makePublicPack(2);
        $token = $this->clubToken($member);

        $this->withToken($token)->getJson('/api/packs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $publicPack->slug);

        $this->withToken($token)->getJson('/api/packs/' . $clubPack->slug)->assertNotFound();
        $this->withToken($token)->postJson('/api/packs/' . $clubPack->slug . '/start')->assertNotFound();
        $this->withToken($token)->getJson('/api/packs/' . $clubPack->slug . '/attempt')->assertNotFound();
        $this->assertSame(0, PackAttempt::count());

        // Public behaviour is unchanged.
        $this->withToken($token)->postJson('/api/packs/' . $publicPack->slug . '/start')->assertOk();
    }

    // ------------------------------------------------------------ AT-ISO-02 / G5

    public function test_club_challenges_are_excluded_from_every_pool_even_in_a_public_pool_value(): void
    {
        $club = $this->makeClub();
        // Worst case: a club challenge mislabeled with a public pool value.
        $clubGeneral = $this->makeClubChallenge($club, ['usage_pool' => Challenge::POOL_GENERAL]);
        $clubDaily   = $this->makeClubChallenge($club, ['usage_pool' => Challenge::POOL_DAILY]);
        $public      = $this->makePublicChallenge(['usage_pool' => Challenge::POOL_GENERAL]);

        $this->assertSame([$public->id], Challenge::dailyPool()->pluck('id')->all());
        $this->assertSame([$public->id], Challenge::tournamentEligible()->pluck('id')->all());
        $this->assertSame([$public->id], Challenge::tournamentEligibleStrict()->pluck('id')->all());
        $this->assertSame([$public->id], app(DailyChallengeScheduler::class)->eligibleChallenges()->pluck('id')->all());
        $this->assertSame([$public->id], app(LeagueService::class)->eligibleTournamentChallenges($public->sport_id)->pluck('id')->all());
        $this->assertSame([$public->id], Challenge::publicContent()->pluck('id')->all());

        foreach ([$clubGeneral, $clubDaily] as $c) {
            $this->assertFalse($c->isInDailyPool());
            $this->assertFalse($c->isInTournamentPool());
            $this->assertFalse($c->isDailyEligible());
            $this->assertFalse($c->isTournamentEligible());
        }
        $this->assertSame(2, Challenge::tournamentBlocked()->count());
    }

    public function test_the_daily_scheduler_and_admin_set_as_daily_refuse_club_challenges(): void
    {
        $club = $this->makeClub();
        $clubChallenge = $this->makeClubChallenge($club, ['usage_pool' => Challenge::POOL_GENERAL]);

        $result = app(DailyChallengeScheduler::class)->schedule([$clubChallenge->id], now()->addDay());
        $this->assertSame([], $result['created']);
        $this->assertSame(DailyChallengeScheduler::SKIP_WRONG_POOL, $result['skipped'][0]['reason']);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)
            ->from('/admin/challenges')
            ->post('/admin/challenges/' . $clubChallenge->id . '/set-as-daily', ['date' => now()->addDays(2)->toDateString()])
            ->assertRedirect('/admin/challenges')
            ->assertSessionHas('error');

        $this->assertSame(0, DailyChallenge::count());
    }

    // ------------------------------------------------------------ AT-ISO-03 / G3

    public function test_club_badges_are_absent_from_the_catalogue_my_badges_and_public_profiles(): void
    {
        $club   = $this->makeClub();
        $global = Badge::create(['code' => 'global_one', 'name' => 'Global One', 'description' => 'x']);
        $secret = Badge::create(['code' => 'pack_99_completed', 'name' => 'Secret Club Pack Trophy', 'description' => 'x', 'club_id' => $club->id]);

        $player = User::factory()->create();
        $player->badges()->attach($global->id, ['earned_at' => now()]);
        $player->badges()->attach($secret->id, ['earned_at' => now()]);
        $viewer = User::factory()->create();

        $catalogue = $this->withToken($this->clubToken($player))->getJson('/api/badges')->assertOk();
        $this->assertSame(['global_one'], array_column($catalogue->json('data'), 'code'));

        $mine = $this->actingWithToken($this->clubToken($player))->getJson('/api/me/badges')->assertOk();
        $this->assertSame(1, $mine->json('earned_count'));
        $this->assertSame(1, $mine->json('total_count'));
        $this->assertStringNotContainsString('Secret Club', $mine->getContent());

        $profile = $this->actingWithToken($this->clubToken($viewer))->getJson('/api/users/' . $player->id . '/public-profile')->assertOk();
        $this->assertSame(1, $profile->json('data.badges.earned_count'));
        $this->assertSame(1, $profile->json('data.badges.total_count'));
        $this->assertStringNotContainsString('Secret Club', $profile->getContent());
    }

    // ------------------------------------------------------------ G4

    public function test_trophy_room_pack_completions_list_public_packs_only(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], $user);
        $clubPack   = $this->makeClubPack($club, 1);
        $publicPack = $this->makePublicPack(1);

        foreach ([$clubPack, $publicPack] as $pack) {
            PackAttempt::create([
                'user_id' => $user->id, 'challenge_pack_id' => $pack->id, 'status' => PackAttempt::STATUS_COMPLETED,
                'started_at' => now(), 'completed_at' => now(), 'total_score' => 100, 'current_index' => 1,
                'metadata' => ['challenge_ids' => $pack->challenges()->pluck('challenges.id')->all()],
            ]);
        }

        $res = $this->withToken($this->clubToken($user))->getJson('/api/me/pack-completions')->assertOk();

        $this->assertSame([$publicPack->slug], array_column(array_column($res->json('data'), 'pack'), 'slug'));
    }

    // ------------------------------------------------------------ G7

    public function test_club_co_membership_never_feeds_friend_suggestions(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $club = $this->makeClub([], $a);
        $this->addMember($club, $b);

        // Control: a tournament co-member IS suggested, so the signal works.
        $c = User::factory()->create();
        $league = \App\Models\League::create([
            'name' => 'L', 'join_code' => 'LG1234', 'owner_user_id' => $a->id, 'sport_id' => $this->clubSport()->id,
            'duration_days' => 3, 'rounds_per_day' => 1, 'status' => 'lobby',
        ]);
        foreach ([$a, $c] as $u) {
            \App\Models\LeagueMember::create(['league_id' => $league->id, 'user_id' => $u->id, 'joined_at' => now()]);
        }

        $res = $this->withToken($this->clubToken($a))->getJson('/api/friends/suggestions')->assertOk();

        $ids = array_column($res->json('data'), 'id');
        $this->assertContains($c->id, $ids);
        $this->assertNotContains($b->id, $ids);
    }

    // ------------------------------------------------------------ gate on the guess endpoint

    private function clubAttempt(User $user, \App\Models\ChallengePack $pack): PackAttempt
    {
        return PackAttempt::create([
            'user_id' => $user->id, 'challenge_pack_id' => $pack->id, 'status' => PackAttempt::STATUS_ACTIVE,
            'started_at' => now(), 'total_score' => 0, 'current_index' => 0,
            'metadata' => ['challenge_ids' => $pack->challenges()->pluck('challenges.id')->all()],
        ]);
    }

    private function guess(User $user, PackAttempt $attempt)
    {
        return $this->actingWithToken($this->clubToken($user))->postJson('/api/pack-attempts/' . $attempt->id . '/guess', [
            'challenge_id' => $attempt->challengeIds()[$attempt->fresh()->current_index],
            'guessed_x'    => 0.5,
            'guessed_y'    => 0.5,
        ]);
    }

    public function test_guesses_on_a_club_pack_attempt_recheck_membership_and_club_status(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], User::factory()->create());
        $this->addMember($club, $user);
        $pack = $this->makeClubPack($club, 3);
        $attempt = $this->clubAttempt($user, $pack);

        // Active member of an active club: works through the existing endpoint,
        // with a signed (private) URL for the next club image.
        $ok = $this->guess($user, $attempt)->assertOk();
        $this->assertStringContainsString('/media/clubs/', $ok->json('next_challenge.hidden_image_url'));
        $this->assertStringContainsString('/media/clubs/', $ok->json('result.reveal_image_url'));

        // Suspended club → read-only.
        $club->update(['status' => ClubStatus::Suspended]);
        $this->guess($user, $attempt)->assertStatus(423)->assertJsonPath('code', ClubDomainException::CLUB_UNAVAILABLE);

        // Removed member → 404, nothing stored (AT-ISO-07).
        $club->update(['status' => ClubStatus::Active]);
        $this->addMember($club, $user, ClubRole::Member, ClubMemberStatus::Removed);
        $this->guess($user, $attempt)->assertNotFound()->assertJsonPath('code', ClubDomainException::CLUB_NOT_FOUND);

        $this->assertSame(1, $attempt->guesses()->count());
    }

    public function test_public_pack_guesses_are_unaffected_by_the_gate(): void
    {
        $user = User::factory()->create();
        $pack = $this->makePublicPack(2);
        $attempt = $this->clubAttempt($user, $pack);
        // Even an archived public pack lets an in-flight attempt finish, as before.
        $pack->update(['status' => 'archived']);

        $this->guess($user, $attempt)->assertOk()->assertJsonPath('progress.current_index', 1);
    }

    // ------------------------------------------------------------ ClubPackAccess

    public function test_club_pack_access_rules_and_competition_reservation(): void
    {
        $gate   = app(PackAccessResolver::class);
        $member = User::factory()->create();
        $club   = $this->makeClub([], $member);
        $pack   = $this->makeClubPack($club, 1);
        $outsider = User::factory()->create();
        $admin    = User::factory()->create(['is_admin' => true]);

        $gate->assertCanPlay($member, $pack);
        $this->assertDomain(ClubDomainException::CLUB_NOT_FOUND, fn () => $gate->assertCanPlay($outsider, $pack));
        // Platform admins are outsiders on the member track.
        $this->assertDomain(ClubDomainException::CLUB_NOT_FOUND, fn () => $gate->assertCanPlay($admin, $pack));

        $competition = $this->makeCompetition($club, [$pack]); // draft: no reservation
        $gate->assertCanPlay($member, $pack);
        $this->assertFalse($gate->isReservedForCompetition($pack));

        $competition->update(['state' => 'published', 'published_at' => now()]);
        $e = $this->assertDomain(ClubDomainException::PACK_RESERVED_FOR_COMPETITION, fn () => $gate->assertCanPlay($member, $pack));
        $this->assertSame(409, $e->status());
        $this->assertTrue($gate->isReservedForCompetition($pack));
        // In-flight attempts are frozen too (security review 2026-10).
        $this->assertDomain(ClubDomainException::PACK_RESERVED_FOR_COMPETITION, fn () => $gate->assertCanContinue($member, $pack));

        $competition->update(['normal_play_policy' => NormalPlayPolicy::Allowed]);
        $gate->assertCanPlay($member, $pack);

        $competition->update(['normal_play_policy' => NormalPlayPolicy::Blocked, 'state' => 'finalized', 'finalized_at' => now()]);
        $gate->assertCanPlay($member, $pack);

        // Another club's competition never reserves this pack.
        $other = $this->makeClub();
        ClubCompetition::factory()->published()->create(['club_id' => $other->id]);
        $gate->assertCanPlay($member, $pack);

        $pack->update(['status' => 'draft']);
        $this->assertDomain(ClubDomainException::CLUB_NOT_FOUND, fn () => $gate->assertCanPlay($member, $pack));

        $pack->update(['status' => 'active']);
        $club->update(['status' => ClubStatus::Archived]);
        $this->assertDomain(ClubDomainException::CLUB_NOT_FOUND, fn () => $gate->assertCanPlay($member, $pack->fresh()));
    }

    public function test_public_pack_access_matches_the_public_routes(): void
    {
        $gate = app(PackAccessResolver::class);
        $user = User::factory()->create();
        $pack = $this->makePublicPack(1);

        $gate->assertCanPlay($user, $pack);

        $pack->update(['visibility' => 'hidden']);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $gate->assertCanPlay($user, $pack);
    }

    private function assertDomain(string $code, callable $fn): ClubDomainException
    {
        try {
            $fn();
        } catch (ClubDomainException $e) {
            $this->assertSame($code, $e->errorCode());

            return $e;
        }
        $this->fail("expected {$code}");
    }
}
