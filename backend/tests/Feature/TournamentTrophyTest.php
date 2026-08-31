<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\TournamentFinish;
use App\Models\User;
use App\Services\BadgeService;
use App\Services\TournamentCompletionService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v1.9.3 tournament skill trophies: Sharpshooter (closest single guess) and
 * Most Consistent (best average over enough rounds), plus the >= 3 players
 * podium guard. All awarded exactly once on fresh completion only.
 */
class TournamentTrophyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    private function service(): TournamentCompletionService
    {
        return app(TournamentCompletionService::class);
    }

    /** Active league with $rounds open rounds and the owner as first member. */
    private function activeLeague(int $rounds = 2, string $status = 'active'): array
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id' => $sport->id, 'title' => 'C', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active', 'hidden_image_path' => 'x.jpg',
        ]);
        $owner = User::factory()->create();
        $league = League::create([
            'name' => 'T', 'join_code' => strtoupper(Str::random(6)), 'owner_user_id' => $owner->id,
            'sport_id' => $sport->id, 'duration_days' => 1, 'rounds_per_day' => $rounds, 'status' => $status,
        ]);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $owner->id, 'joined_at' => now()]);
        for ($i = 1; $i <= $rounds; $i++) {
            LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $challenge->id, 'round_number' => $i, 'status' => 'open']);
        }
        return [$league, $owner];
    }

    /** Add a member and play every round; $perRound = [['score' => int, 'distance' => float|null], ...]. */
    private function memberPlays(League $league, array $perRound, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        if (!$league->members()->where('user_id', $user->id)->exists()) {
            LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
        }
        foreach ($league->rounds()->orderBy('round_number')->get()->values() as $i => $round) {
            $spec = $perRound[$i] ?? $perRound[array_key_last($perRound)];
            Guess::create([
                'league_round_id' => $round->id, 'user_id' => $user->id,
                'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
                'distance' => array_key_exists('distance', $spec) ? $spec['distance'] : 0.2,
                'score' => $spec['score'], 'submitted_at' => now(),
            ]);
        }
        return $user;
    }

    private function hasBadge(User $user, string $code): bool
    {
        return $user->fresh()->badges()->where('code', $code)->exists();
    }

    private function badgeHolders(string $code): int
    {
        return \DB::table('user_badges')
            ->join('badges', 'badges.id', '=', 'user_badges.badge_id')
            ->where('badges.code', $code)->count();
    }

    public function test_three_player_completion_awards_winner_and_top3(): void
    {
        [$league, $owner] = $this->activeLeague(1);
        $this->memberPlays($league, [['score' => 90, 'distance' => 0.05]], $owner);
        $second = $this->memberPlays($league, [['score' => 60, 'distance' => 0.2]]);
        $third  = $this->memberPlays($league, [['score' => 30, 'distance' => 0.4]]);

        $this->assertNotNull($this->service()->completeIfFinished($league->fresh()));

        $this->assertTrue($this->hasBadge($owner, 'tournament_winner'));
        $this->assertTrue($this->hasBadge($owner, 'podium_finish'));
        $this->assertTrue($this->hasBadge($second, 'podium_finish'));
        $this->assertTrue($this->hasBadge($third, 'podium_finish'));
        $this->assertFalse($this->hasBadge($second, 'tournament_winner'));
    }

    public function test_sharpshooter_goes_to_closest_distance(): void
    {
        [$league, $owner] = $this->activeLeague(2);
        // Owner wins on total score, but $sniper has the single closest guess.
        $this->memberPlays($league, [['score' => 90, 'distance' => 0.10], ['score' => 90, 'distance' => 0.10]], $owner);
        $sniper = $this->memberPlays($league, [['score' => 99, 'distance' => 0.01], ['score' => 10, 'distance' => 0.90]]);

        $result = $this->service()->completeIfFinished($league->fresh());

        $this->assertTrue($this->hasBadge($sniper, 'sharpshooter'));
        $this->assertFalse($this->hasBadge($owner, 'sharpshooter'));
        $codes = collect($result['per_user'][$sniper->id]['new_badges'])->pluck('code');
        $this->assertTrue($codes->contains('sharpshooter'));
    }

    public function test_sharpshooter_distance_tie_broken_by_earliest_submission(): void
    {
        // guesses.distance is NOT NULL in the schema, so the score fallback in
        // sharpshooterUserId() is defensive-only; ties are the real edge case.
        [$league, $owner] = $this->activeLeague(1);
        $round = $league->rounds()->first();

        LeagueMember::create(['league_id' => $league->id, 'user_id' => ($late = User::factory()->create())->id, 'joined_at' => now()]);
        Guess::create([
            'league_round_id' => $round->id, 'user_id' => $late->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
            'distance' => 0.05, 'score' => 90, 'submitted_at' => now()->addMinute(),
        ]);
        Guess::create([
            'league_round_id' => $round->id, 'user_id' => $owner->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
            'distance' => 0.05, 'score' => 90, 'submitted_at' => now(),
        ]);

        $this->service()->completeIfFinished($league->fresh());

        $this->assertTrue($this->hasBadge($owner, 'sharpshooter'));
        $this->assertFalse($this->hasBadge($late, 'sharpshooter'));
    }

    public function test_most_consistent_goes_to_best_average_excluding_partial_players(): void
    {
        [$league, $owner] = $this->activeLeague(2);
        $this->memberPlays($league, [['score' => 80, 'distance' => 0.2], ['score' => 80, 'distance' => 0.2]], $owner); // avg 80
        $steady = $this->memberPlays($league, [['score' => 85, 'distance' => 0.2], ['score' => 85, 'distance' => 0.2]]); // avg 85

        // A departed player guessed only round 1 with a perfect score (avg 100)
        // but has too few guesses to be eligible.
        $partial = User::factory()->create();
        Guess::create([
            'league_round_id' => $league->rounds()->orderBy('round_number')->first()->id,
            'user_id' => $partial->id, 'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
            'distance' => 0.15, 'score' => 100, 'submitted_at' => now(),
        ]);

        $this->service()->completeIfFinished($league->fresh());

        $this->assertTrue($this->hasBadge($steady, 'most_consistent'));
        $this->assertFalse($this->hasBadge($partial, 'most_consistent'));
        $this->assertFalse($this->hasBadge($owner, 'most_consistent'));
    }

    public function test_most_consistent_skipped_for_single_round_tournament(): void
    {
        [$league, $owner] = $this->activeLeague(1);
        $this->memberPlays($league, [['score' => 90, 'distance' => 0.1]], $owner);
        $this->memberPlays($league, [['score' => 40, 'distance' => 0.3]]);

        $this->service()->completeIfFinished($league->fresh());

        $this->assertSame(0, $this->badgeHolders('most_consistent'));
        $this->assertDatabaseHas('leagues', ['id' => $league->id, 'status' => 'completed']);
    }

    public function test_solo_tournament_awards_no_skill_trophies(): void
    {
        [$league, $owner] = $this->activeLeague(1);
        $this->memberPlays($league, [['score' => 90, 'distance' => 0.05]], $owner);

        $this->service()->completeIfFinished($league->fresh());

        $this->assertFalse($this->hasBadge($owner, 'sharpshooter'));
        $this->assertFalse($this->hasBadge($owner, 'most_consistent'));
        $this->assertFalse($this->hasBadge($owner, 'tournament_winner'));
    }

    public function test_double_close_does_not_duplicate_trophies(): void
    {
        [$league, $owner] = $this->activeLeague(2);
        $this->memberPlays($league, [['score' => 90, 'distance' => 0.05], ['score' => 90, 'distance' => 0.05]], $owner);
        $this->memberPlays($league, [['score' => 40, 'distance' => 0.3], ['score' => 40, 'distance' => 0.3]]);

        $first  = $this->service()->completeIfFinished($league->fresh());
        $second = $this->service()->completeIfFinished($league->fresh());

        $this->assertNotNull($first);
        $this->assertNull($second);
        foreach (['sharpshooter', 'most_consistent', 'tournament_winner'] as $code) {
            $this->assertLessThanOrEqual(1, $this->badgeHolders($code), $code);
        }
        $this->assertSame(1, \DB::table('user_badges')
            ->join('badges', 'badges.id', '=', 'user_badges.badge_id')
            ->where('badges.code', 'sharpshooter')->where('user_badges.user_id', $owner->id)->count());
    }

    public function test_lobby_and_cancelled_tournaments_award_nothing(): void
    {
        foreach (['lobby', 'cancelled'] as $status) {
            [$league, $owner] = $this->activeLeague(1, $status);
            $this->memberPlays($league, [['score' => 90, 'distance' => 0.05]], $owner);
            $this->memberPlays($league, [['score' => 40, 'distance' => 0.3]]);

            $this->assertNull($this->service()->completeIfFinished($league->fresh()), $status);
            $this->assertFalse($this->hasBadge($owner, 'sharpshooter'), $status);
            $this->assertDatabaseHas('leagues', ['id' => $league->id, 'status' => $status]);
        }
    }

    public function test_two_player_finishes_do_not_count_toward_tournament_beast(): void
    {
        // Podium (and therefore Tournament Beast progress) uses the same
        // >= 3 players definition everywhere: two prior 2-player finishes are
        // not podiums, so this user's first real podium must not award beast.
        $user = User::factory()->create();
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $makeLeague = fn () => League::create([
            'name' => 'T', 'join_code' => strtoupper(Str::random(6)), 'owner_user_id' => $user->id,
            'sport_id' => $sport->id, 'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'completed',
        ]);

        foreach ([1, 2] as $placement) {
            TournamentFinish::create([
                'league_id' => $makeLeague()->id, 'user_id' => $user->id,
                'placement' => $placement, 'total_score' => 100,
                'metadata' => ['total_players' => 2],
            ]);
        }

        app(BadgeService::class)->evaluateTournamentFinish($user, $makeLeague(), 3, 3);

        $this->assertTrue($this->hasBadge($user, 'podium_finish'));
        $this->assertFalse($this->hasBadge($user, 'tournament_beast'));
    }

    public function test_league_with_no_guesses_does_not_crash_standings(): void
    {
        // Old/edge league: active with rounds but zero guesses. Completion never
        // fires (isComplete false) and standings math must not crash.
        [$league] = $this->activeLeague(1);
        $this->assertNull($this->service()->completeIfFinished($league->fresh()));
        $this->assertSame([], $this->service()->calculateStandings($league));
    }
}
