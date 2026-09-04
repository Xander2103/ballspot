<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\PackAttempt;
use App\Models\PackAttemptGuess;
use App\Models\Sport;
use App\Models\User;
use App\Models\XpEvent;
use App\Services\BadgeService;
use App\Support\AppLog;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * Launch-hardening regression tests for the final pack challenge:
 *  - the last valid guess completes the attempt and returns a completion payload
 *  - a duplicate submit after completion is idempotent (never a 422)
 *  - a completed pack cannot be replayed (no second trophy-eligible attempt)
 *  - the completion overview is readable afterwards
 */
class PackCompletionFlowTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);
    }

    private function auth(): array
    {
        $user = User::factory()->create();
        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
    }

    private function readyChallenge(string $title): Challenge
    {
        return Challenge::create([
            'sport_id'          => $this->sport()->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);
    }

    private function pack(int $challenges = 2, array $attrs = []): ChallengePack
    {
        $pack = ChallengePack::create(array_merge([
            'name'       => 'Flow Pack',
            'slug'       => 'flow-pack',
            'status'     => ChallengePack::STATUS_ACTIVE,
            'visibility' => ChallengePack::VISIBILITY_PUBLIC,
            'sport_id'   => $this->sport()->id,
        ], $attrs));

        for ($i = 1; $i <= $challenges; $i++) {
            $pack->challenges()->attach($this->readyChallenge("C{$i}")->id, ['sort_order' => $i]);
        }

        return $pack;
    }

    /** Play every challenge; returns [attemptId, lastChallengeId, finalResponse]. */
    private function playThrough(string $token, array $scores = [0.5, 0.5]): array
    {
        $start     = $this->withToken($token)->postJson('/api/packs/flow-pack/start')->json();
        $attemptId = $start['attempt']['id'];
        $challenge = $start['challenge']['id'];
        $res       = null;

        foreach ($scores as $i => $x) {
            $res = $this->withToken($token)->postJson("/api/pack-attempts/{$attemptId}/guess", [
                'challenge_id' => $challenge, 'guessed_x' => $x, 'guessed_y' => 0.5,
            ]);
            $res->assertOk();
            if ($res->json('next_challenge.id')) {
                $challenge = $res->json('next_challenge.id');
            }
        }

        return [$attemptId, $challenge, $res];
    }

    private function logged(string $message): array
    {
        return array_values(array_filter(
            $this->records->getRecords(),
            fn ($r) => $r->message === $message,
        ));
    }

    // ------------------------------------------------------------------
    // Final guess completes the pack
    // ------------------------------------------------------------------

    public function test_final_guess_completes_the_pack_and_returns_completion_payload(): void
    {
        [$user, $token] = $this->auth();
        $badge = Badge::where('code', 'first_pack_completed')->first();
        $this->pack(2, ['completion_badge_id' => $badge->id]);

        [$attemptId, , $final] = $this->playThrough($token, [0.5, 0.0]);

        $final->assertOk()
            ->assertJsonPath('pack_completed', true)
            ->assertJsonPath('already_completed', false)
            ->assertJsonPath('progress.status', 'completed')
            ->assertJsonPath('progress.completed_count', 2)
            ->assertJsonPath('completion.total_challenges', 2)
            ->assertJsonPath('completion.completed_count', 2)
            ->assertJsonPath('completion.total_score', 100)
            ->assertJsonPath('completion.max_score', 200)
            ->assertJsonPath('completion.average_score', 50)
            ->assertJsonPath('completion.average_pct', 50)
            ->assertJsonPath('completion.best_guess.score', 100)
            ->assertJsonPath('completion.best_guess.title', 'C1')
            ->assertJsonPath('completion.completion_xp', 250)
            ->assertJsonPath('completion.trophy.code', 'first_pack_completed')
            ->assertJsonPath('completion.trophy.earned', true);

        $this->assertNull($final->json('next_challenge'));
        $this->assertDatabaseHas('pack_attempts', ['id' => $attemptId, 'status' => 'completed']);
        $this->assertSame(2, PackAttemptGuess::where('pack_attempt_id', $attemptId)->count());
        $this->assertSame(1, XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_PACK_COMPLETION)->count());
        $this->assertNotEmpty($this->logged('pack.completed'));
    }

    // ------------------------------------------------------------------
    // Duplicate submit after completion
    // ------------------------------------------------------------------

    public function test_duplicate_final_submit_is_idempotent_not_a_422(): void
    {
        [$user, $token] = $this->auth();
        $this->pack(2);
        [$attemptId, $lastChallenge, $final] = $this->playThrough($token);
        $xpAfterFirst = XpEvent::where('user_id', $user->id)->sum('amount');

        // The app retries the same final guess (lost response / double tap).
        $dup = $this->withToken($token)->postJson("/api/pack-attempts/{$attemptId}/guess", [
            'challenge_id' => $lastChallenge, 'guessed_x' => 0.1, 'guessed_y' => 0.1,
        ]);

        $dup->assertOk()
            ->assertJsonPath('pack_completed', true)
            ->assertJsonPath('already_completed', true)
            ->assertJsonPath('progress.status', 'completed')
            // The stored (first) guess is echoed back — the retry never rescored.
            ->assertJsonPath('result.score', $final->json('result.score'))
            ->assertJsonPath('result.guessed_x', 0.5)
            ->assertJsonPath('rank_progress.xp_gained', 0)
            ->assertJsonPath('completion.total_score', $final->json('completion.total_score'));

        $this->assertSame(2, PackAttemptGuess::where('pack_attempt_id', $attemptId)->count());
        $this->assertEquals($xpAfterFirst, XpEvent::where('user_id', $user->id)->sum('amount'));
        $this->assertSame([], $dup->json('new_badges'));
        $this->assertNotEmpty($this->logged('pack.duplicate_submit'));
    }

    public function test_submit_for_a_different_challenge_on_a_completed_attempt_is_a_friendly_409(): void
    {
        [, $token] = $this->auth();
        $this->pack(2);
        [$attemptId] = $this->playThrough($token);

        $this->withToken($token)->postJson("/api/pack-attempts/{$attemptId}/guess", [
            'challenge_id' => 999999, 'guessed_x' => 0.5, 'guessed_y' => 0.5,
        ])->assertStatus(409)
            ->assertJsonPath('pack_completed', true)
            ->assertJsonPath('message', 'You have already completed this pack.');
    }

    // ------------------------------------------------------------------
    // Replay is disabled for launch
    // ------------------------------------------------------------------

    public function test_completed_pack_cannot_be_started_again(): void
    {
        [, $token] = $this->auth();
        $this->pack(1);
        [$attemptId] = $this->playThrough($token, [0.5]);

        $res = $this->withToken($token)->postJson('/api/packs/flow-pack/start');

        $res->assertStatus(409)
            ->assertJsonPath('message', 'You have already completed this pack.')
            ->assertJsonPath('attempt.id', $attemptId)
            ->assertJsonPath('attempt.status', 'completed')
            ->assertJsonPath('completion.total_challenges', 1);

        $this->assertSame(1, PackAttempt::count());
    }

    public function test_start_still_resumes_an_active_attempt_even_if_an_older_completion_exists(): void
    {
        [$user, $token] = $this->auth();
        $pack = $this->pack(1);
        // Historical state from before replay was disabled: one completed and
        // one in-flight attempt. The in-flight one must stay playable.
        PackAttempt::create([
            'user_id' => $user->id, 'challenge_pack_id' => $pack->id, 'status' => 'completed',
            'started_at' => now()->subDay(), 'completed_at' => now()->subDay(),
            'current_index' => 1, 'total_score' => 100, 'metadata' => ['challenge_ids' => [$pack->challenges->first()->id]],
        ]);
        $active = PackAttempt::create([
            'user_id' => $user->id, 'challenge_pack_id' => $pack->id, 'status' => 'active',
            'started_at' => now(), 'current_index' => 0, 'total_score' => 0,
            'metadata' => ['challenge_ids' => [$pack->challenges->first()->id]],
        ]);

        $this->withToken($token)->postJson('/api/packs/flow-pack/start')
            ->assertOk()->assertJsonPath('attempt.id', $active->id);
    }

    // ------------------------------------------------------------------
    // Completion overview
    // ------------------------------------------------------------------

    public function test_attempt_endpoint_returns_the_completion_overview(): void
    {
        [, $token] = $this->auth();
        $this->pack(2);
        $this->playThrough($token, [0.5, 0.0]);

        $res = $this->withToken($token)->getJson('/api/packs/flow-pack/attempt');

        $res->assertOk()
            ->assertJsonPath('attempt.status', 'completed')
            ->assertJsonPath('challenge', null)
            ->assertJsonPath('completion.total_score', 100)
            ->assertJsonPath('completion.average_score', 50)
            ->assertJsonPath('completion.best_guess.title', 'C1')
            ->assertJsonPath('completion.is_perfect', false)
            ->assertJsonPath('completion.trophy', null);
    }

    public function test_attempt_endpoint_has_no_completion_while_active(): void
    {
        [, $token] = $this->auth();
        $this->pack(2);
        $this->withToken($token)->postJson('/api/packs/flow-pack/start')->assertOk();

        $this->withToken($token)->getJson('/api/packs/flow-pack/attempt')
            ->assertOk()
            ->assertJsonPath('attempt.status', 'active')
            ->assertJsonPath('completion', null);
    }

    public function test_packs_list_marks_completed_packs(): void
    {
        [, $token] = $this->auth();
        $this->pack(1);
        $this->playThrough($token, [0.5]);

        $this->withToken($token)->getJson('/api/packs')
            ->assertOk()
            ->assertJsonPath('data.0.progress.status', 'completed')
            ->assertJsonPath('data.0.progress.completed_count', 1);
    }

    // ------------------------------------------------------------------
    // Reward failures never turn a committed completion into a 500
    // ------------------------------------------------------------------

    public function test_reward_failure_after_completion_is_logged_and_still_returns_the_completion(): void
    {
        [, $token] = $this->auth();
        $this->pack(1);

        $badges = \Mockery::mock(BadgeService::class);
        $badges->shouldReceive('evaluatePackCompletion')->andThrow(new \RuntimeException('badge table exploded'));
        $this->app->instance(BadgeService::class, $badges);

        $start = $this->withToken($token)->postJson('/api/packs/flow-pack/start')->json();
        $res = $this->withToken($token)->postJson("/api/pack-attempts/{$start['attempt']['id']}/guess", [
            'challenge_id' => $start['challenge']['id'], 'guessed_x' => 0.5, 'guessed_y' => 0.5,
        ]);

        $res->assertOk()
            ->assertJsonPath('pack_completed', true)
            ->assertJsonPath('completion.total_score', 100);
        $this->assertSame([], $res->json('new_badges'));
        $this->assertDatabaseHas('pack_attempts', ['id' => $start['attempt']['id'], 'status' => 'completed']);

        $failures = $this->logged('pack.completion_reward_failed');
        $this->assertNotEmpty($failures);
        $this->assertSame('badge_evaluation', $failures[0]->context['stage']);
        $this->assertStringNotContainsString('exploded', json_encode($failures[0]->context));
    }
}
