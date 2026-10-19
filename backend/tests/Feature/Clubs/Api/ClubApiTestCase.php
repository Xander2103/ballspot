<?php

namespace Tests\Feature\Clubs\Api;

use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\PackAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Shared HTTP helpers for the member API suites (tests/Feature/Clubs/Api).
 * Every request goes through the real routes with a Sanctum token, switching
 * users safely with actingWithToken().
 */
abstract class ClubApiTestCase extends TestCase
{
    use CreatesClubs;
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableClubs();
    }

    protected function api(User $user): self
    {
        $this->tokens[$user->id] ??= $this->clubToken($user);

        return $this->actingWithToken($this->tokens[$user->id]);
    }

    protected function clubPath(Club $club, string $suffix = ''): string
    {
        return '/api/clubs/' . $club->public_id . $suffix;
    }

    protected function packPath(Club $club, ChallengePack $pack, string $suffix = ''): string
    {
        return $this->clubPath($club, '/packs/' . $pack->slug . $suffix);
    }

    /** Start (or resume) a normal club-pack attempt over HTTP. */
    protected function startClubPack(User $user, Club $club, ChallengePack $pack): TestResponse
    {
        return $this->api($user)->postJson($this->packPath($club, $pack, '/start'));
    }

    /** Guess the attempt's current challenge (at the given ratios). */
    protected function guessCurrent(User $user, int $attemptId, float $x = 0.5, float $y = 0.5): TestResponse
    {
        $attempt     = PackAttempt::findOrFail($attemptId);
        $challengeId = $attempt->challengeIds()[$attempt->current_index] ?? $attempt->challengeIds()[0];

        return $this->api($user)->postJson("/api/pack-attempts/{$attemptId}/guess", [
            'challenge_id' => $challengeId,
            'guessed_x'    => $x,
            'guessed_y'    => $y,
        ]);
    }

    /** Play a club pack to the end over HTTP; returns the final guess response. */
    protected function playClubPack(User $user, Club $club, ChallengePack $pack, float $x = 0.5): TestResponse
    {
        $attemptId = $this->startClubPack($user, $club, $pack)->assertOk()->json('attempt.id');
        $last      = null;
        $total     = PackAttempt::findOrFail($attemptId)->totalChallenges();
        for ($i = 0; $i < $total; $i++) {
            $last = $this->guessCurrent($user, $attemptId, $x)->assertOk();
        }

        return $last;
    }
}
