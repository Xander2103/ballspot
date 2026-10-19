<?php

namespace Tests\Feature\Clubs\Api;

use App\Enums\CompetitionAttemptStatus;
use App\Enums\CompetitionState;
use App\Models\Badge;
use App\Models\ChallengePack;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionAttemptGuess;
use App\Models\ClubPackRewardClaim;
use App\Models\PackAttempt;
use App\Models\User;
use App\Models\XpEvent;
use App\Services\BadgeService;
use App\Services\Clubs\ClubRewardService;
use Database\Seeders\BadgeSeeder;

/**
 * Rewards without duplication (plan §5.8, contracts §3.7): AT-RWD-01, the
 * normal-track part of AT-RWD-03, AT-RWD-04 and AT-RWD-05, plus the hooks
 * the competition track calls.
 */
class ClubRewardTest extends ClubApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    private function xp(User $user, ?string $source = null): int
    {
        return (int) XpEvent::where('user_id', $user->id)->when($source, fn ($q) => $q->where('source_type', $source))->sum('amount');
    }

    private function claimByCompetition(User $user, ChallengePack $pack): ClubCompetitionAttempt
    {
        $club = $pack->club;
        $competition = $this->makeCompetition($club, [$pack], ['state' => CompetitionState::Finalized, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
        $attempt = ClubCompetitionAttempt::create([
            'club_competition_id' => $competition->id, 'challenge_pack_id' => $pack->id, 'user_id' => $user->id,
            'attempt_number' => 1, 'status' => CompetitionAttemptStatus::Completed, 'active_slot' => null,
            'challenge_ids' => [], 'current_index' => 0, 'guesses_count' => 0, 'total_score' => 0,
            'started_at' => now()->subDays(2),
        ]);
        $this->assertTrue(app(ClubRewardService::class)->claim($user, $pack, ClubPackRewardClaim::SOURCE_COMPETITION_ATTEMPT, $attempt->id));

        return $attempt;
    }

    // ------------------------------------------------------------ AT-RWD-01

    public function test_the_first_normal_run_of_a_club_pack_earns_guess_and_completion_xp_once(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], $user);
        $pack = $this->makeClubPack($club, 3);

        $last = $this->playClubPack($user, $club, $pack, 0.6); // 0.1 off → 75 each

        $last->assertJsonPath('pack_completed', true)->assertJsonPath('completion_xp', 250);
        $this->assertSame(225, $this->xp($user, XpEvent::SOURCE_PACK_GUESS));
        $this->assertSame(250, $this->xp($user, XpEvent::SOURCE_PACK_COMPLETION));
        $this->assertSame(3, XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_PACK_GUESS)->count());

        $claim = ClubPackRewardClaim::sole();
        $this->assertSame([$user->id, $pack->id, 'pack_attempt', PackAttempt::sole()->id],
            [(int) $claim->user_id, (int) $claim->challenge_pack_id, $claim->source_type, $claim->source_id]);
        $this->assertContains('first_pack_completed', array_column($last->json('new_badges'), 'code'));
    }

    // ------------------------------------------------------------ non-rewarded run

    public function test_a_normal_run_after_another_run_claimed_the_pack_earns_nothing_global(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], $user);
        $pack = $this->makeClubPack($club, 2);
        $this->claimByCompetition($user, $pack);
        $xpBefore = $this->xp($user);

        $attemptId = $this->startClubPack($user, $club, $pack)->assertOk()->json('attempt.id');
        $first = $this->guessCurrent($user, $attemptId)->assertOk()
            ->assertJsonPath('result.score', 100)
            ->assertJsonPath('rank_progress.xp_gained', 0)
            ->assertJsonPath('new_badges', []);
        $this->assertNull($first->json('completion_xp'));

        $this->guessCurrent($user, $attemptId)->assertOk()
            ->assertJsonPath('pack_completed', true)
            ->assertJsonPath('final_score', 200)
            ->assertJsonPath('rank_progress.xp_gained', 0)
            ->assertJsonPath('completion_xp', 0)
            ->assertJsonPath('new_badges', [])
            ->assertJsonPath('completion.completion_xp', 0);

        $this->assertSame($xpBefore, $this->xp($user));
        $this->assertSame(0, XpEvent::where('user_id', $user->id)->whereIn('source_type', ['pack_guess', 'pack_completion'])->count());
        $this->assertSame(0, $user->badges()->count(), 'No badge evaluation for a non-rewarded run');
        $this->assertSame('competition_attempt', ClubPackRewardClaim::sole()->source_type);
    }

    // ------------------------------------------------------------ AT-RWD-03 (normal track)

    public function test_pack_badges_count_public_completions_plus_each_club_pack_once(): void
    {
        $user   = User::factory()->create();
        $club   = $this->makeClub([], $user);
        $badges = app(BadgeService::class);

        $clubA = $this->makeClubPack($club, 1);
        $clubB = $this->makeClubPack($club, 1);
        $this->playClubPack($user, $club, $clubA);

        // A second, non-rewarded completed run of club pack B (claimed by a
        // competition run that never completed) does not count.
        $this->claimByCompetition($user, $clubB)->update(['status' => CompetitionAttemptStatus::Expired]);
        $this->playClubPack($user, $club, $clubB);

        $this->assertSame(1, $badges->completedPackCount($user));
        $this->assertSame(1, $badges->distinctCompletedPackCount($user));
        $this->assertFalse($user->badges()->where('code', 'pack_explorer')->exists());

        // Two public packs → three distinct packs → pack_explorer.
        foreach ([$this->makePublicPack(1), $this->makePublicPack(1)] as $public) {
            $attemptId = $this->api($user)->postJson('/api/packs/' . $public->slug . '/start')->assertOk()->json('attempt.id');
            $last = $this->guessCurrent($user, $attemptId)->assertOk();
        }
        $this->assertSame(3, $badges->completedPackCount($user));
        $this->assertSame(3, $badges->distinctCompletedPackCount($user));
        $this->assertContains('pack_explorer', array_column($last->json('new_badges'), 'code'));
    }

    public function test_a_completed_rewarded_competition_run_counts_once_for_pack_badges(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], $user);
        $pack = $this->makeClubPack($club, 1);
        $attempt = $this->claimByCompetition($user, $pack); // completed + rewarded

        $badges = app(BadgeService::class);
        $this->assertSame(1, $badges->completedPackCount($user));

        // The normal run afterwards is not rewarded and does not add a count.
        $this->playClubPack($user, $club, $pack);
        $this->assertSame(1, $badges->completedPackCount($user));
        $this->assertSame(1, $badges->distinctCompletedPackCount($user));
        $this->assertNotNull($attempt);
    }

    public function test_club_trophy_badges_go_to_the_rewarded_run_only_and_stay_out_of_the_catalogue(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $club  = $this->makeClub([], $user);
        $this->addMember($club, $other);
        $trophy = Badge::create(['code' => 'club_pack_trophy_x', 'name' => 'Club Trophy X', 'description' => 'x', 'icon' => '🏆',
            'category' => 'pack', 'rarity' => 'rare', 'club_id' => $club->id]);
        $pack = $this->makeClubPack($club, 1, ['completion_badge_id' => $trophy->id]);

        $done = $this->playClubPack($user, $club, $pack);
        $this->assertContains('club_pack_trophy_x', array_column($done->json('new_badges'), 'code'));
        $this->assertTrue($done->json('completion.trophy.earned'));

        // Not rewarded → no trophy.
        $this->claimByCompetition($other, $pack);
        $this->playClubPack($other, $club, $pack)->assertJsonPath('new_badges', []);
        $this->assertFalse($other->badges()->where('badges.id', $trophy->id)->exists());

        $this->assertNotContains('club_pack_trophy_x', array_column($this->api($user)->getJson('/api/badges')->assertOk()->json('data') ?? [], 'code'));
    }

    // ------------------------------------------------------------ AT-RWD-04

    public function test_concurrent_claims_end_with_exactly_one_claim_through_the_unique_index(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], $user);
        $pack = $this->makeClubPack($club, 1);
        $rewards = app(ClubRewardService::class);

        // Both tracks race: the insert-first claim makes the second one hit the
        // unique (user_id, challenge_pack_id) index and lose.
        $this->assertTrue($rewards->claim($user, $pack, ClubPackRewardClaim::SOURCE_COMPETITION_ATTEMPT, 41));
        $this->assertFalse($rewards->claim($user, $pack, ClubPackRewardClaim::SOURCE_PACK_ATTEMPT, 7));
        // A retry of the winner stays rewarded (idempotent).
        $this->assertTrue($rewards->claim($user, $pack, ClubPackRewardClaim::SOURCE_COMPETITION_ATTEMPT, 41));

        $this->assertSame(1, ClubPackRewardClaim::count());
        $this->assertTrue($rewards->isRewarded($user, $pack, 'competition_attempt', 41));
        $this->assertFalse($rewards->isRewarded($user, $pack, 'pack_attempt', 7));

        // The losing normal run over HTTP is a non-rewarded run.
        $attemptId = $this->startClubPack($user, $club, $pack)->assertOk()->json('attempt.id');
        $this->guessCurrent($user, $attemptId)->assertOk()->assertJsonPath('completion_xp', 0);
        $this->assertSame(1, ClubPackRewardClaim::count());
    }

    public function test_another_players_claim_does_not_affect_mine(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $club = $this->makeClub([], $a);
        $this->addMember($club, $b);
        $pack = $this->makeClubPack($club, 1);

        $this->playClubPack($a, $club, $pack)->assertJsonPath('completion_xp', 250);
        $this->playClubPack($b, $club, $pack)->assertJsonPath('completion_xp', 250);
        $this->assertSame(2, ClubPackRewardClaim::count());
    }

    public function test_claims_are_only_for_club_packs(): void
    {
        $user = User::factory()->create();
        $public = $this->makePublicPack(1);

        $this->assertFalse(app(ClubRewardService::class)->claim($user, $public, 'pack_attempt', 1));
        $this->expectException(\InvalidArgumentException::class);
        app(ClubRewardService::class)->claim($user, $this->makeClubPack($this->makeClub(), 1), 'bogus', 1);
    }

    // ------------------------------------------------------------ AT-RWD-05

    public function test_public_pack_rewards_are_unchanged_and_never_create_claims(): void
    {
        $user = User::factory()->create();
        $pack = $this->makePublicPack(2);

        $attemptId = $this->api($user)->postJson('/api/packs/' . $pack->slug . '/start')->assertOk()->json('attempt.id');
        $this->guessCurrent($user, $attemptId, 0.6)->assertOk()->assertJsonPath('rank_progress.xp_gained', 75);
        $this->guessCurrent($user, $attemptId, 0.6)->assertOk()->assertJsonPath('completion_xp', 250);

        $this->assertSame(150, $this->xp($user, XpEvent::SOURCE_PACK_GUESS));
        $this->assertSame(250, $this->xp($user, XpEvent::SOURCE_PACK_COMPLETION));
        $this->assertSame(0, ClubPackRewardClaim::count());
    }

    // ------------------------------------------------------------ competition hooks

    public function test_competition_hooks_pay_the_rewarded_run_once_and_nothing_otherwise(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], $user);
        $pack = $this->makeClubPack($club, 2);
        $rewards = app(ClubRewardService::class);

        $competition = $this->makeCompetition($club, [$pack], ['state' => CompetitionState::Published, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $rewarded = ClubCompetitionAttempt::create([
            'club_competition_id' => $competition->id, 'challenge_pack_id' => $pack->id, 'user_id' => $user->id,
            'attempt_number' => 1, 'challenge_ids' => [], 'started_at' => now(),
        ]);
        $this->assertTrue($rewards->claim($user, $pack, ClubPackRewardClaim::SOURCE_COMPETITION_ATTEMPT, $rewarded->id));

        $challengeIds = $pack->challenges()->pluck('challenges.id')->all();
        foreach ($challengeIds as $i => $challengeId) {
            $guess = ClubCompetitionAttemptGuess::create([
                'attempt_id' => $rewarded->id, 'challenge_id' => $challengeId, 'position' => $i,
                'guess_x' => 0.5, 'guess_y' => 0.5, 'distance' => 0, 'score' => 100, 'submitted_at' => now(),
            ]);
            $this->assertSame(100, $rewards->awardCompetitionGuessXp($user, $rewarded, $guess->id, 100));
            $this->assertSame(0, $rewards->awardCompetitionGuessXp($user, $rewarded, $guess->id, 100), 'ledger dedupe');
        }
        $rewarded->markInactive(CompetitionAttemptStatus::Completed)->save();

        $done = $rewards->awardCompetitionCompletion($user, $rewarded);
        $this->assertSame(250, $done['completion_xp']);
        $codes = array_map(fn ($b) => $b->code, $done['new_badges']);
        $this->assertContains('first_pack_completed', $codes);
        $this->assertContains('perfect_pack', $codes);
        $this->assertSame(['completion_xp' => 0, 'new_badges' => []], $rewards->awardCompetitionCompletion($user, $rewarded));

        $this->assertSame(200, $this->xp($user, XpEvent::SOURCE_CLUB_COMPETITION_GUESS));
        $this->assertSame(250, $this->xp($user, XpEvent::SOURCE_CLUB_COMPETITION_COMPLETION));

        // A second competition attempt is never rewarded.
        $extra = ClubCompetitionAttempt::create([
            'club_competition_id' => $competition->id, 'challenge_pack_id' => $pack->id, 'user_id' => $user->id,
            'attempt_number' => 2, 'challenge_ids' => [], 'started_at' => now(),
        ]);
        $this->assertFalse($rewards->claim($user, $pack, ClubPackRewardClaim::SOURCE_COMPETITION_ATTEMPT, $extra->id));
        $this->assertSame(0, $rewards->awardCompetitionGuessXp($user, $extra, 999999, 100));
        $this->assertSame(['completion_xp' => 0, 'new_badges' => []], $rewards->awardCompetitionCompletion($user, $extra));
    }
}
