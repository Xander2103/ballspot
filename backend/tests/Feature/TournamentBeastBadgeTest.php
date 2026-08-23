<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Sport;
use App\Models\TournamentFinish;
use App\Models\User;
use App\Services\BadgeService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentBeastBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    private function league(User $owner): League
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);

        return League::create([
            'name'           => 'L-' . uniqid(),
            'join_code'      => strtoupper(substr(md5(uniqid()), 0, 6)),
            'owner_user_id'  => $owner->id,
            'sport_id'       => $sport->id,
            'duration_days'  => 1,
            'rounds_per_day' => 1,
            'status'         => 'completed',
        ]);
    }

    /** A finish row already persisted from an earlier tournament. */
    private function priorFinish(User $user, int $placement): void
    {
        TournamentFinish::create([
            'league_id'   => $this->league($user)->id,
            'user_id'     => $user->id,
            'placement'   => $placement,
            'total_score' => 100,
        ]);
    }

    public function test_third_podium_awards_tournament_beast(): void
    {
        // evaluateTournamentFinish runs BEFORE the current finish row is
        // persisted (see TournamentCompletionService) — two prior podiums
        // plus the current one make three.
        $user = User::factory()->create();
        $this->priorFinish($user, 1);
        $this->priorFinish($user, 3);

        app(BadgeService::class)->evaluateTournamentFinish($user, $this->league($user), 2);

        $this->assertTrue($user->badges()->where('code', 'tournament_beast')->exists());
    }

    public function test_second_podium_does_not_award(): void
    {
        $user = User::factory()->create();
        $this->priorFinish($user, 2);

        app(BadgeService::class)->evaluateTournamentFinish($user, $this->league($user), 1);

        $this->assertFalse($user->badges()->where('code', 'tournament_beast')->exists());
    }

    public function test_non_podium_finishes_do_not_count(): void
    {
        $user = User::factory()->create();
        $this->priorFinish($user, 4);
        $this->priorFinish($user, 5);

        app(BadgeService::class)->evaluateTournamentFinish($user, $this->league($user), 1);

        $this->assertFalse($user->badges()->where('code', 'tournament_beast')->exists());
    }

    public function test_completion_replay_does_not_double_count_same_league(): void
    {
        // Replay: the finish row for the CURRENT league is already persisted
        // when completion logic re-runs. It must not count twice.
        $user = User::factory()->create();
        $this->priorFinish($user, 4); // non-podium noise

        $current = $this->league($user);
        TournamentFinish::create([
            'league_id'   => $current->id,
            'user_id'     => $user->id,
            'placement'   => 1,
            'total_score' => 100,
        ]);

        app(BadgeService::class)->evaluateTournamentFinish($user, $current, 1);

        // Only ONE distinct podium exists (the current league) — no badge.
        $this->assertFalse($user->badges()->where('code', 'tournament_beast')->exists());
    }
}
