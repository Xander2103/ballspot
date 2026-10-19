<?php

namespace Tests\Feature\Clubs\Competitions;

use App\Enums\ClubRole;
use App\Enums\CompetitionState;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Clubs\Api\ClubApiTestCase;

/**
 * Shared fixtures for the competition suites (tests/Feature/Clubs/Competitions).
 * The clock is frozen (Carbon::setTestNow) so window edges and tie-breaks are
 * exact; tick() moves it forward. Every challenge has its ball at (0.5, 0.5),
 * so a guess at x = 0.5 + (100 − score) / 250 scores exactly `score`.
 *
 * No SQLite-only SQL: these suites must also pass with DB_CONNECTION
 * overridden (MariaDB / MySQL concurrency run).
 */
abstract class CompetitionTestCase extends ClubApiTestCase
{
    protected Club $club;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $this->owner = User::factory()->create(['username' => 'owner']);
        $this->club  = $this->makeClub(['timezone' => 'Europe/Brussels'], $this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Advance the frozen clock. */
    protected function tick(int $seconds = 60): void
    {
        Carbon::setTestNow(now()->addSeconds($seconds));
    }

    protected function member(?string $username = null): User
    {
        $user = User::factory()->create($username ? ['username' => $username] : []);
        $this->addMember($this->club, $user, ClubRole::Member);

        return $user;
    }

    /** A published competition inside its window (rules lock lazily / by tick). */
    protected function liveCompetition(array $packs, array $attrs = []): ClubCompetition
    {
        return $this->makeCompetition($this->club, $packs, array_merge([
            'state'        => CompetitionState::Published,
            'published_at' => now()->subDay(),
            'starts_at'    => now()->subHour(),
            'ends_at'      => now()->addDays(7),
        ], $attrs));
    }

    protected function multiple(int $max, string $rule): array
    {
        return ['replay_mode' => 'multiple_attempts', 'max_attempts' => $max, 'score_rule' => $rule];
    }

    protected function competitionPath(ClubCompetition $c, string $suffix = ''): string
    {
        return $this->clubPath($this->club, '/competitions/' . $c->public_id . $suffix);
    }

    protected function startAttempt(User $user, ClubCompetition $c, ChallengePack $pack, int $number = 1): TestResponse
    {
        return $this->api($user)->postJson($this->competitionPath($c, '/packs/' . $pack->slug . '/attempts'), ['attempt_number' => $number]);
    }

    protected static function xFor(int $score): float
    {
        return 0.5 + (100 - $score) / 250;
    }

    /** Guess the attempt's current challenge so that it scores exactly $score. */
    protected function guess(User $user, int $attemptId, int $score = 100, ?int $challengeId = null): TestResponse
    {
        $attempt = ClubCompetitionAttempt::findOrFail($attemptId);
        $challengeId ??= $attempt->challengeIds()[$attempt->current_index] ?? $attempt->challengeIds()[0];

        return $this->api($user)->postJson("/api/club-competition-attempts/{$attemptId}/guess", [
            'challenge_id' => $challengeId,
            'guessed_x'    => self::xFor($score),
            'guessed_y'    => 0.5,
        ]);
    }

    /**
     * Start attempt $number and guess the given per-challenge scores (one
     * minute apart). Returns the attempt id.
     *
     * @param list<int> $scores
     */
    protected function play(User $user, ClubCompetition $c, ChallengePack $pack, array $scores, int $number = 1): int
    {
        $id = (int) $this->startAttempt($user, $c, $pack, $number)->assertSuccessful()->json('attempt.id');
        foreach ($scores as $score) {
            $this->tick();
            $this->guess($user, $id, $score)->assertOk();
        }

        return $id;
    }
}
