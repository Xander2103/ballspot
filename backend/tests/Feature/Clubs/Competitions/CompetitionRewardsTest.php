<?php

namespace Tests\Feature\Clubs\Competitions;

use App\Models\ClubCompetitionAttempt;
use App\Models\ClubPackRewardClaim;
use App\Models\PackAttempt;
use App\Models\User;
use App\Models\XpEvent;
use App\Services\BadgeService;
use App\Services\Clubs\ClubRewardService;
use Carbon\Carbon;
use Database\Seeders\BadgeSeeder;

/**
 * Rewards on the competition track (plan §5.8, D3): AT-RWD-01 (competition
 * first run), AT-RWD-02, AT-RWD-03, AT-RWD-04; finishes carry no global XP.
 */
class CompetitionRewardsTest extends CompetitionTestCase
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

    public function test_a_first_competition_run_is_the_rewarded_run(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack]);
        $user = $this->member('player');

        $start = $this->startAttempt($user, $c, $pack)->assertCreated()->assertJsonPath('attempt.is_rewarded_run', true);
        $id = $start->json('attempt.id');
        $this->guess($user, $id, 80)->assertOk()->assertJsonPath('rank_progress.xp_gained', 80)->assertJsonPath('is_rewarded_run', true);
        $last = $this->guess($user, $id, 70)->assertOk()->assertJsonPath('pack_completed', true)
            ->assertJsonPath('completion_xp', (int) config('ballspot.xp.pack_completion', 250))
            ->assertJsonPath('final_score', 150);
        $this->assertContains('first_pack_completed', array_column($last->json('new_badges'), 'code'));

        $this->assertSame(150, $this->xp($user, XpEvent::SOURCE_CLUB_COMPETITION_GUESS));
        $this->assertSame(250, $this->xp($user, XpEvent::SOURCE_CLUB_COMPETITION_COMPLETION));
        $claim = ClubPackRewardClaim::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['competition_attempt', $id], [$claim->source_type, $claim->source_id]);
    }

    public function test_at_rwd_02_normal_completion_then_competition_attempt_earns_nothing_global(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $user = $this->member('player');
        $this->playClubPack($user, $this->club, $pack);           // normal track first (rewarded)
        $xpBefore     = $this->xp($user);
        $badgesBefore = $user->badges()->count();
        $this->assertGreaterThan(0, $xpBefore);

        $c  = $this->liveCompetition([$pack]);
        $id = $this->startAttempt($user, $c, $pack)->assertCreated()->assertJsonPath('attempt.is_rewarded_run', false)->json('attempt.id');
        $this->guess($user, $id, 100)->assertOk()->assertJsonPath('rank_progress.xp_gained', 0)->assertJsonPath('is_rewarded_run', false);
        $this->guess($user, $id, 100)->assertOk()->assertJsonPath('pack_completed', true)
            ->assertJsonPath('completion_xp', 0)->assertJsonPath('new_badges', [])->assertJsonPath('rank_progress.xp_gained', 0);

        $this->assertSame($xpBefore, $this->xp($user));
        $this->assertSame($badgesBefore, $user->badges()->count());
        $this->assertSame(0, XpEvent::whereIn('source_type', [XpEvent::SOURCE_CLUB_COMPETITION_GUESS, XpEvent::SOURCE_CLUB_COMPETITION_COMPLETION])->count());
        // …but it scores for the competition.
        $this->api($user)->getJson($this->competitionPath($c))->assertJsonPath('data.my_entry.total_score', 200);
    }

    public function test_at_rwd_03_only_the_rewarded_attempt_pays_and_badges_count_the_pack_once(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack], $this->multiple(3, 'best'));
        $user = $this->member('player');

        $one = $this->play($user, $c, $pack, [50, 50], 1);
        $two = $this->play($user, $c, $pack, [100, 100], 2);
        $three = $this->play($user, $c, $pack, [90, 90], 3);

        $this->assertTrue(ClubCompetitionAttempt::find($one)->is_rewarded_run);
        $this->assertFalse(ClubCompetitionAttempt::find($two)->is_rewarded_run);
        $this->assertFalse(ClubCompetitionAttempt::find($three)->is_rewarded_run);
        $this->assertSame(100, $this->xp($user, XpEvent::SOURCE_CLUB_COMPETITION_GUESS));
        $this->assertSame(250, $this->xp($user, XpEvent::SOURCE_CLUB_COMPETITION_COMPLETION));
        $this->assertSame(1, XpEvent::where('source_type', XpEvent::SOURCE_CLUB_COMPETITION_COMPLETION)->count());

        $badges = app(BadgeService::class);
        $this->assertSame(1, $badges->completedPackCount($user));
        $this->assertSame(1, $badges->distinctCompletedPackCount($user));
        $this->assertSame(1, ClubPackRewardClaim::count());
    }

    public function test_at_rwd_04_first_starts_on_both_tracks_end_with_exactly_one_claim(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack], ['normal_play_policy' => 'allowed']);
        $a    = $this->member('alpha');
        $b    = $this->member('beta');

        // Competition first, then normal (tracks run one after the other:
        // parallel runs on one pack are refused, security review 2026-10).
        $this->play($a, $c, $pack, [100, 100]);
        $normal = $this->startClubPack($a, $this->club, $pack)->assertOk()->json('attempt.id');
        $this->assertFalse(app(ClubRewardService::class)->isRewarded($a, $pack, ClubPackRewardClaim::SOURCE_PACK_ATTEMPT, $normal));
        $this->assertTrue((bool) \App\Models\ClubCompetitionAttempt::where('user_id', $a->id)->value('is_rewarded_run'));

        // Normal first, then competition.
        $this->playClubPack($b, $this->club, $pack)->assertJsonPath('pack_completed', true);
        $this->startAttempt($b, $c, $pack)->assertCreated()->assertJsonPath('attempt.is_rewarded_run', false);

        // A lost race: the other track committed its claim between our check and insert.
        $racer = $this->member('racer');
        $attemptId = PackAttempt::create(['user_id' => $racer->id, 'challenge_pack_id' => $pack->id, 'status' => 'completed',
            'started_at' => now(), 'metadata' => ['challenge_ids' => []]])->id;
        ClubPackRewardClaim::create(['user_id' => $racer->id, 'challenge_pack_id' => $pack->id, 'source_type' => 'pack_attempt',
            'source_id' => $attemptId, 'claimed_at' => now()]);
        $this->startAttempt($racer, $c, $pack)->assertCreated()->assertJsonPath('attempt.is_rewarded_run', false);

        $this->assertSame(1, ClubPackRewardClaim::where('user_id', $a->id)->count());
        $this->assertSame(1, ClubPackRewardClaim::where('user_id', $b->id)->count());
        $this->assertSame(1, ClubPackRewardClaim::where('user_id', $racer->id)->count());
    }

    public function test_finishes_award_no_global_xp_or_badges(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c    = $this->liveCompetition([$pack], ['ends_at' => now()->addHour()]);
        $user = $this->member('winner');
        $this->play($user, $c, $pack, [100]);
        $xp     = $this->xp($user);
        $badges = $user->badges()->count();

        Carbon::setTestNow($c->ends_at->copy()->addMinute());
        $this->artisan('ballspot:clubs:competition-tick')->assertSuccessful();

        $this->assertSame('finalized', $c->fresh()->state->value);
        $this->assertSame($xp, $this->xp($user));
        $this->assertSame($badges, $user->badges()->count());
        $this->api($user)->getJson('/api/me/club-finishes')->assertOk()->assertJsonPath('data.0.placement', 1);
    }
}
