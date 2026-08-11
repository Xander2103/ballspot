<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use App\Models\XpEvent;
use App\Services\TournamentCompletionService;
use Carbon\Carbon;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TournamentCompletionTest extends TestCase
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

    /** Add a member and play every round with the given per-round score. */
    private function memberPlaysAll(League $league, int $scorePerRound, ?User $user = null, ?Carbon $when = null): User
    {
        $user ??= User::factory()->create();
        if (!$league->members()->where('user_id', $user->id)->exists()) {
            LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
        }
        foreach ($league->rounds as $round) {
            Guess::create([
                'league_round_id' => $round->id, 'user_id' => $user->id,
                'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.1,
                'score' => $scorePerRound, 'submitted_at' => $when ?? now(),
            ]);
        }
        return $user;
    }

    public function test_incomplete_tournament_is_not_completed(): void
    {
        [$league, $owner] = $this->activeLeague(2);
        // Owner plays only round 1 (not all rounds).
        Guess::create([
            'league_round_id' => $league->rounds->first()->id, 'user_id' => $owner->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.1, 'score' => 50, 'submitted_at' => now(),
        ]);

        $this->assertFalse($this->service()->isComplete($league));
        $this->assertNull($this->service()->completeIfFinished($league));
        $this->assertDatabaseHas('leagues', ['id' => $league->id, 'status' => 'active']);
    }

    public function test_completed_tournament_marks_status_and_awards_winner(): void
    {
        [$league, $owner] = $this->activeLeague(2);
        $this->memberPlaysAll($league, 90, $owner);            // 180 total → winner
        $second = $this->memberPlaysAll($league, 40);          // 80 total → runner-up

        $result = $this->service()->completeIfFinished($league->fresh());
        $this->assertNotNull($result);
        $this->assertSame(2, $result['total_players']);

        $this->assertDatabaseHas('leagues', ['id' => $league->id, 'status' => 'completed']);

        // Finishes stored with correct placements.
        $this->assertDatabaseHas('tournament_finishes', ['league_id' => $league->id, 'user_id' => $owner->id, 'placement' => 1, 'total_score' => 180]);
        $this->assertDatabaseHas('tournament_finishes', ['league_id' => $league->id, 'user_id' => $second->id, 'placement' => 2, 'total_score' => 80]);

        // Winner badge + podium; runner-up gets podium only.
        $this->assertTrue($owner->fresh()->badges()->where('code', 'tournament_winner')->exists());
        $this->assertTrue($owner->fresh()->badges()->where('code', 'podium_finish')->exists());
        $this->assertFalse($second->fresh()->badges()->where('code', 'tournament_winner')->exists());
        $this->assertTrue($second->fresh()->badges()->where('code', 'podium_finish')->exists());

        // Tournament-win XP via the ledger.
        $this->assertDatabaseHas('xp_events', ['user_id' => $owner->id, 'source_type' => 'tournament_win', 'source_id' => $league->id, 'amount' => 1000]);
        $this->assertDatabaseHas('xp_events', ['user_id' => $second->id, 'source_type' => 'tournament_win', 'source_id' => $league->id, 'amount' => 500]);

        $this->assertSame(1, $result['per_user'][$owner->id]['placement']);
        $this->assertSame(1000, $result['per_user'][$owner->id]['xp_awarded']);
    }

    public function test_third_place_gets_podium_and_250_xp(): void
    {
        [$league, $owner] = $this->activeLeague(1);
        $this->memberPlaysAll($league, 90, $owner); // 1st
        $this->memberPlaysAll($league, 60);          // 2nd
        $third = $this->memberPlaysAll($league, 30);  // 3rd

        $this->service()->completeIfFinished($league->fresh());

        $this->assertDatabaseHas('tournament_finishes', ['league_id' => $league->id, 'user_id' => $third->id, 'placement' => 3]);
        $this->assertTrue($third->fresh()->badges()->where('code', 'podium_finish')->exists());
        $this->assertDatabaseHas('xp_events', ['user_id' => $third->id, 'source_type' => 'tournament_win', 'source_id' => $league->id, 'amount' => 250]);
    }

    public function test_completion_is_idempotent(): void
    {
        [$league, $owner] = $this->activeLeague(1);
        $this->memberPlaysAll($league, 90, $owner);
        $this->memberPlaysAll($league, 40);

        $first  = $this->service()->completeIfFinished($league->fresh());
        $second = $this->service()->completeIfFinished($league->fresh());

        $this->assertNotNull($first);
        $this->assertNull($second); // already completed → not fresh
        $this->assertSame(2, XpEvent::where('source_type', 'tournament_win')->where('source_id', $league->id)->count());
        $this->assertSame(2, \DB::table('tournament_finishes')->where('league_id', $league->id)->count());
    }

    public function test_tie_broken_by_earliest_completion(): void
    {
        [$league, $owner] = $this->activeLeague(1);
        // Equal scores; the owner finished LATER, so the other member wins the tie.
        $this->memberPlaysAll($league, 70, $owner, Carbon::parse('2026-07-20 12:00:00'));
        $early = $this->memberPlaysAll($league, 70, null, Carbon::parse('2026-07-20 10:00:00'));

        $this->service()->completeIfFinished($league->fresh());

        $this->assertDatabaseHas('tournament_finishes', ['league_id' => $league->id, 'user_id' => $early->id, 'placement' => 1]);
        $this->assertDatabaseHas('tournament_finishes', ['league_id' => $league->id, 'user_id' => $owner->id, 'placement' => 2]);
    }

    public function test_cancelled_tournament_is_not_completed(): void
    {
        [$league, $owner] = $this->activeLeague(1, 'cancelled');
        $this->memberPlaysAll($league, 90, $owner);

        $this->assertFalse($this->service()->isComplete($league));
        $this->assertNull($this->service()->completeIfFinished($league));
        $this->assertDatabaseMissing('tournament_finishes', ['league_id' => $league->id]);
        $this->assertSame(0, XpEvent::where('source_type', 'tournament_win')->where('source_id', $league->id)->count());
    }

    public function test_round_guess_completes_solo_tournament_without_rewards(): void
    {
        // 1-member, 1-round league: the owner's only guess finishes it. The
        // tournament still completes, but placement XP and podium badges are
        // WITHHELD — a solo tournament is otherwise an unbounded XP/badge farm
        // (create → start → one guess → "win" 1000 XP, on a loop).
        [$league, $owner] = $this->activeLeague(1);
        $token = $owner->createToken('t')->plainTextToken;
        $round = $league->rounds->first();

        $res = $this->withToken($token)->postJson("/api/rounds/{$round->id}/guess", [
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('tournament_completion.is_completed', true);
        $res->assertJsonPath('tournament_completion.placement', 1);
        $res->assertJsonPath('tournament_completion.total_players', 1);
        $res->assertJsonPath('tournament_completion.xp_awarded', 0);

        $codes = collect($res->json('new_badges'))->pluck('code');
        $this->assertFalse($codes->contains('tournament_winner'));
        $this->assertFalse($codes->contains('podium_finish'));
        $this->assertDatabaseHas('leagues', ['id' => $league->id, 'status' => 'completed']);
        $this->assertSame(0, XpEvent::where('source_type', 'tournament_win')->where('source_id', $league->id)->count());
    }

    public function test_two_player_tournament_still_awards_winner(): void
    {
        // The reward gate must not touch legitimate multiplayer tournaments.
        [$league, $owner] = $this->activeLeague(1);
        $this->memberPlaysAll($league, 90, $owner);
        $this->memberPlaysAll($league, 40);

        $result = $this->service()->completeIfFinished($league->fresh());

        $this->assertSame(2, $result['total_players']);
        $this->assertSame(1000, $result['per_user'][$owner->id]['xp_awarded']);
        $this->assertDatabaseHas('xp_events', ['source_type' => 'tournament_win', 'source_id' => $league->id, 'amount' => 1000]);
    }

    public function test_reopening_result_does_not_return_completion_again(): void
    {
        [$league, $owner] = $this->activeLeague(1);
        $token = $owner->createToken('t')->plainTextToken;
        $round = $league->rounds->first();

        $this->withToken($token)->postJson("/api/rounds/{$round->id}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5])->assertStatus(201);

        // The result endpoint never carries a completion payload.
        $result = $this->withToken($token)->getJson("/api/rounds/{$round->id}/result");
        $result->assertOk();
        $this->assertNull($result->json('tournament_completion'));
        // Solo tournaments are reward-gated, so there is no placement XP at all.
        $this->assertSame(0, XpEvent::where('source_type', 'tournament_win')->where('source_id', $league->id)->count());
    }

    public function test_trophy_finishes_endpoint_returns_user_finishes(): void
    {
        [$league, $owner] = $this->activeLeague(1);
        $this->memberPlaysAll($league, 90, $owner);
        $this->memberPlaysAll($league, 40);
        $this->service()->completeIfFinished($league->fresh());

        $token = $owner->createToken('t')->plainTextToken;
        $res = $this->withToken($token)->getJson('/api/me/tournament-finishes');

        $res->assertOk();
        $res->assertJsonStructure(['data' => [['id', 'placement', 'total_score', 'total_players', 'league' => ['id', 'name'], 'completed_at']]]);
        $res->assertJsonPath('data.0.placement', 1);
        $res->assertJsonPath('data.0.total_players', 2);
    }
}
