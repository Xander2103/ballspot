<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\PackAttempt;
use App\Models\Sport;
use App\Models\User;
use App\Models\XpEvent;
use App\Services\PackPlayService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackPlayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
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
            'name'       => 'Play Pack',
            'slug'       => 'play-pack',
            'status'     => ChallengePack::STATUS_ACTIVE,
            'visibility' => ChallengePack::VISIBILITY_PUBLIC,
            'sport_id'   => $this->sport()->id,
        ], $attrs));

        for ($i = 1; $i <= $challenges; $i++) {
            $pack->challenges()->attach($this->readyChallenge("C{$i}")->id, ['sort_order' => $i]);
        }

        return $pack;
    }

    public function test_user_can_start_an_active_public_pack(): void
    {
        [, $token] = $this->auth();
        $this->pack();

        $res = $this->withToken($token)->postJson('/api/packs/play-pack/start');

        $res->assertOk()
            ->assertJsonPath('attempt.status', 'active')
            ->assertJsonPath('attempt.current_index', 0)
            ->assertJsonPath('attempt.total_challenges', 2)
            ->assertJsonStructure(['attempt' => ['id'], 'challenge' => ['id', 'hidden_image_url']]);
        // The current challenge never leaks the ball position.
        $this->assertArrayNotHasKey('ball_x_ratio', $res->json('challenge'));
    }

    public function test_draft_or_hidden_pack_cannot_be_started(): void
    {
        [, $token] = $this->auth();
        $this->pack(1, ['slug' => 'draft-pack', 'status' => 'draft']);
        $this->pack(1, ['slug' => 'hidden-pack', 'visibility' => 'hidden']);

        $this->withToken($token)->postJson('/api/packs/draft-pack/start')->assertNotFound();
        $this->withToken($token)->postJson('/api/packs/hidden-pack/start')->assertNotFound();
    }

    public function test_pack_with_no_ready_challenges_cannot_be_started(): void
    {
        [, $token] = $this->auth();
        $pack = ChallengePack::create([
            'name' => 'Empty', 'slug' => 'empty-pack',
            'status' => 'active', 'visibility' => 'public',
        ]);
        // Attach a NOT-ready (draft) challenge — should not count.
        $draft = Challenge::create([
            'sport_id' => $this->sport()->id, 'title' => 'D',
            'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'draft', 'hidden_image_path' => 'x.jpg',
        ]);
        $pack->challenges()->attach($draft->id);

        $this->withToken($token)->postJson('/api/packs/empty-pack/start')->assertStatus(422);
    }

    public function test_start_resumes_the_active_attempt(): void
    {
        [, $token] = $this->auth();
        $this->pack();

        $first  = $this->withToken($token)->postJson('/api/packs/play-pack/start')->json('attempt.id');
        $second = $this->withToken($token)->postJson('/api/packs/play-pack/start')->json('attempt.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, PackAttempt::count());
    }

    public function test_user_can_submit_a_guess_and_progress_advances(): void
    {
        [, $token] = $this->auth();
        $pack = $this->pack();
        $start = $this->withToken($token)->postJson('/api/packs/play-pack/start')->json();
        $challengeId = $start['challenge']['id'];
        $attemptId = $start['attempt']['id'];

        $res = $this->withToken($token)->postJson("/api/pack-attempts/{$attemptId}/guess", [
            'challenge_id' => $challengeId,
            'guessed_x'    => 0.5,
            'guessed_y'    => 0.5,
        ]);

        $res->assertOk()
            ->assertJsonPath('result.score', 100)
            ->assertJsonPath('pack_completed', false)
            ->assertJsonPath('progress.completed_count', 1)
            ->assertJsonPath('progress.total_challenges', 2);
        $this->assertNotNull($res->json('next_challenge'));
    }

    public function test_cannot_submit_guess_for_the_wrong_challenge(): void
    {
        [, $token] = $this->auth();
        $pack = $this->pack();
        $start = $this->withToken($token)->postJson('/api/packs/play-pack/start')->json();
        $attemptId = $start['attempt']['id'];
        // The 2nd challenge is NOT the current expected one.
        $wrongId = $pack->challenges()->orderByDesc('challenge_id')->first()->id;
        // ensure it's actually different from current
        if ($wrongId === $start['challenge']['id']) {
            $wrongId = $pack->challenges->last()->id;
        }

        $this->withToken($token)->postJson("/api/pack-attempts/{$attemptId}/guess", [
            'challenge_id' => $wrongId + 9999, // definitely not current
            'guessed_x'    => 0.5,
            'guessed_y'    => 0.5,
        ])->assertStatus(422);
    }

    public function test_cannot_submit_to_another_users_attempt(): void
    {
        [$a] = $this->auth();
        [, $tokenB] = $this->auth();
        $pack = $this->pack();
        // A's attempt created directly (avoids cross-user auth caching in tests).
        $attempt = app(PackPlayService::class)->startOrResume($a, $pack);
        $currentId = $attempt->challengeIds()[0];

        $this->withToken($tokenB)->postJson("/api/pack-attempts/{$attempt->id}/guess", [
            'challenge_id' => $currentId,
            'guessed_x'    => 0.5,
            'guessed_y'    => 0.5,
        ])->assertStatus(403);
    }

    public function test_pack_completes_after_final_challenge_with_xp_and_badge(): void
    {
        [$user, $token] = $this->auth();
        $this->pack(2);

        $start = $this->withToken($token)->postJson('/api/packs/play-pack/start')->json();
        $attemptId = $start['attempt']['id'];

        // First guess (perfect).
        $r1 = $this->withToken($token)->postJson("/api/pack-attempts/{$attemptId}/guess", [
            'challenge_id' => $start['challenge']['id'], 'guessed_x' => 0.5, 'guessed_y' => 0.5,
        ]);
        $r1->assertOk()->assertJsonPath('pack_completed', false);

        // Second/final guess (perfect) -> completes.
        $r2 = $this->withToken($token)->postJson("/api/pack-attempts/{$attemptId}/guess", [
            'challenge_id' => $r1->json('next_challenge.id'), 'guessed_x' => 0.5, 'guessed_y' => 0.5,
        ]);

        $r2->assertOk()
            ->assertJsonPath('pack_completed', true)
            ->assertJsonPath('final_score', 200)
            ->assertJsonPath('completion_xp', 250);

        // Attempt is completed & historical.
        $this->assertDatabaseHas('pack_attempts', ['id' => $attemptId, 'status' => 'completed', 'total_score' => 200]);

        // XP: per-guess (x2) + completion + badge unlocks all recorded.
        $this->assertSame(2, XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_PACK_GUESS)->count());
        $this->assertSame(1, XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_PACK_COMPLETION)->count());

        // Badges: first_pack_completed + perfect_pack (all guesses perfect).
        $codes = collect($r2->json('new_badges'))->pluck('code')->all();
        $this->assertContains('first_pack_completed', $codes);
        $this->assertContains('perfect_pack', $codes);
    }

    public function test_perfect_pack_badge_not_awarded_when_a_guess_is_imperfect(): void
    {
        [$user, $token] = $this->auth();
        $this->pack(2);

        $start = $this->withToken($token)->postJson('/api/packs/play-pack/start')->json();
        $attemptId = $start['attempt']['id'];

        // First guess imperfect (far away -> score 0).
        $r1 = $this->withToken($token)->postJson("/api/pack-attempts/{$attemptId}/guess", [
            'challenge_id' => $start['challenge']['id'], 'guessed_x' => 0.0, 'guessed_y' => 0.0,
        ]);
        // Final guess perfect.
        $r2 = $this->withToken($token)->postJson("/api/pack-attempts/{$attemptId}/guess", [
            'challenge_id' => $r1->json('next_challenge.id'), 'guessed_x' => 0.5, 'guessed_y' => 0.5,
        ]);

        $codes = collect($r2->json('new_badges'))->pluck('code')->all();
        $this->assertContains('first_pack_completed', $codes);
        $this->assertNotContains('perfect_pack', $codes);
    }

    public function test_trophy_room_includes_pack_completions(): void
    {
        [$user, $token] = $this->auth();
        $this->pack(1);

        $start = $this->withToken($token)->postJson('/api/packs/play-pack/start')->json();
        $this->withToken($token)->postJson("/api/pack-attempts/{$start['attempt']['id']}/guess", [
            'challenge_id' => $start['challenge']['id'], 'guessed_x' => 0.5, 'guessed_y' => 0.5,
        ])->assertOk()->assertJsonPath('pack_completed', true);

        $res = $this->withToken($token)->getJson('/api/me/pack-completions');

        $res->assertOk()
            ->assertJsonPath('data.0.pack.slug', 'play-pack')
            ->assertJsonPath('data.0.is_perfect', true)
            ->assertJsonPath('data.0.challenge_count', 1)
            ->assertJsonPath('data.0.total_score', 100);
    }

    public function test_packs_list_includes_user_progress(): void
    {
        [, $token] = $this->auth();
        $this->pack(2);

        // Before playing: no progress.
        $before = $this->withToken($token)->getJson('/api/packs');
        $before->assertOk()->assertJsonPath('data.0.progress', null);

        // Start + one guess -> in progress.
        $start = $this->withToken($token)->postJson('/api/packs/play-pack/start')->json();
        $this->withToken($token)->postJson("/api/pack-attempts/{$start['attempt']['id']}/guess", [
            'challenge_id' => $start['challenge']['id'], 'guessed_x' => 0.5, 'guessed_y' => 0.5,
        ]);

        $after = $this->withToken($token)->getJson('/api/packs');
        $after->assertOk()
            ->assertJsonPath('data.0.progress.status', 'active')
            ->assertJsonPath('data.0.progress.completed_count', 1);
    }
}
