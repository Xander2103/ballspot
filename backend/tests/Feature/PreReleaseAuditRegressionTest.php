<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use App\Notifications\LoginVerificationCodeNotification;
use App\Services\LeagueService;
use App\Support\FailedLoginLimiter;
use Database\Seeders\BadgeSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Regression tests for the October 2026 pre-release audit.
 *
 * Each test pins one defect that was found and fixed; the name states what
 * must never come back.
 */
class PreReleaseAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{0: User, 1: string} */
    private function auth(): array
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'password' => bcrypt('password123')]);
    }

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
    }

    private function challenge(string $title = 'Audit Challenge'): Challenge
    {
        return Challenge::create([
            'sport_id'          => $this->sport()->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/' . strtolower(str_replace(' ', '-', $title)) . '.jpg',
        ]);
    }

    /** Enough unique tournament photos for the shortest (7-day) tournament. */
    private function tournamentPool(int $count = 7): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->challenge("Pool {$i}");
        }
    }

    private function league(User $owner, string $status = 'active', int $roundsPerDay = 1): League
    {
        $league = League::create([
            'name'           => 'Audit League',
            'join_code'      => strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            'owner_user_id'  => $owner->id,
            'sport_id'       => $this->sport()->id,
            'duration_days'  => 7,
            'rounds_per_day' => $roundsPerDay,
            'status'         => $status,
        ]);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $owner->id, 'joined_at' => now()]);

        return $league;
    }

    private function round(League $league, int $number): LeagueRound
    {
        return LeagueRound::create([
            'league_id'    => $league->id,
            'challenge_id' => $this->challenge("Round {$number}")->id,
            'round_number' => $number,
            'status'       => 'open',
        ]);
    }

    private function todayDaily(): DailyChallenge
    {
        return DailyChallenge::create([
            'challenge_id'   => $this->challenge('Daily Photo')->id,
            'challenge_date' => today()->toDateString(),
            'status'         => 'active',
        ]);
    }

    private function guessPayload(): array
    {
        return ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5];
    }

    // ------------------------------------------------------------------
    // 1. Admin: a hard delete must never cascade into player history.
    //    (daily_challenges -> daily_challenge_guesses, league_rounds ->
    //    guesses and pack_attempt_guesses all cascade on challenge delete.)
    // ------------------------------------------------------------------

    public function test_admin_cannot_delete_a_challenge_that_was_played_as_a_daily(): void
    {
        [$player] = $this->auth();
        $daily = $this->todayDaily();
        DailyChallengeGuess::create([
            'daily_challenge_id' => $daily->id, 'user_id' => $player->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.1, 'score' => 75, 'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->delete("/admin/challenges/{$daily->challenge_id}")
            ->assertRedirect('/admin/challenges')
            ->assertSessionHas('error');

        $this->assertDatabaseHas('challenges', ['id' => $daily->challenge_id]);
        $this->assertDatabaseCount('daily_challenges', 1);
        $this->assertDatabaseCount('daily_challenge_guesses', 1);
    }

    public function test_admin_cannot_delete_a_challenge_used_in_a_tournament_round(): void
    {
        [$owner] = $this->auth();
        $league = $this->league($owner);
        $round  = $this->round($league, 1);
        Guess::create([
            'league_round_id' => $round->id, 'user_id' => $owner->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.1, 'score' => 75, 'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->delete("/admin/challenges/{$round->challenge_id}")
            ->assertRedirect('/admin/challenges')
            ->assertSessionHas('error');

        $this->assertDatabaseHas('challenges', ['id' => $round->challenge_id]);
        $this->assertDatabaseCount('league_rounds', 1);
        $this->assertDatabaseCount('guesses', 1);
    }

    public function test_admin_cannot_delete_a_challenge_with_pack_attempt_guesses(): void
    {
        [, $token] = $this->auth();
        $pack = ChallengePack::create([
            'name' => 'Audit Pack', 'slug' => 'audit-pack',
            'status' => ChallengePack::STATUS_ACTIVE, 'visibility' => ChallengePack::VISIBILITY_PUBLIC,
            'sport_id' => $this->sport()->id,
        ]);
        $first  = $this->challenge('Pack One');
        $second = $this->challenge('Pack Two');
        $pack->challenges()->attach($first->id, ['sort_order' => 1]);
        $pack->challenges()->attach($second->id, ['sort_order' => 2]);

        $attemptId = $this->withToken($token)->postJson('/api/packs/audit-pack/start')->assertOk()->json('attempt.id');
        $this->withToken($token)->postJson("/api/pack-attempts/{$attemptId}/guess", [
            'challenge_id' => $first->id, 'guessed_x' => 0.5, 'guessed_y' => 0.5,
        ])->assertOk();
        $this->assertDatabaseCount('pack_attempt_guesses', 1);

        $this->actingAs($this->admin())
            ->delete("/admin/challenges/{$first->id}")
            ->assertRedirect('/admin/challenges')
            ->assertSessionHas('error');

        $this->assertDatabaseHas('challenges', ['id' => $first->id]);
        $this->assertDatabaseCount('pack_attempt_guesses', 1);
    }

    public function test_admin_can_still_delete_a_challenge_nobody_has_played(): void
    {
        $challenge = $this->challenge('Unused');

        $this->actingAs($this->admin())
            ->delete("/admin/challenges/{$challenge->id}")
            ->assertRedirect('/admin/challenges')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('challenges', ['id' => $challenge->id]);
    }

    public function test_non_admins_cannot_reach_the_challenge_delete_route(): void
    {
        [$user] = $this->auth();
        $challenge = $this->challenge('Protected');

        // Anonymous first (actingAs() below sticks for the rest of the test).
        $this->delete("/admin/challenges/{$challenge->id}")->assertRedirect('/admin/login');
        $this->actingAs($user)->delete("/admin/challenges/{$challenge->id}")->assertStatus(403);

        $this->assertDatabaseHas('challenges', ['id' => $challenge->id]);
    }

    // ------------------------------------------------------------------
    // 2. Login 2FA: a mail outage is a stable 503, never a raw 500.
    // ------------------------------------------------------------------

    public function test_login_code_mail_failure_is_a_stable_503_with_a_code_and_no_orphan_row(): void
    {
        config(['ballspot.auth.force_login_2fa' => true]);
        [$user] = $this->auth();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('smtp down at mail.example.internal'));

        $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);

        $res->assertStatus(503)
            ->assertJsonPath('code', 'two_factor_send_failed')
            ->assertJsonStructure(['message', 'code', 'errors' => ['email']]);
        $this->assertStringNotContainsString('mail.example.internal', $res->getContent());
        $this->assertStringNotContainsString('RuntimeException', $res->getContent());
        // No pending code is left behind: the user simply logs in again.
        $this->assertDatabaseCount('login_verification_codes', 0);
    }

    public function test_login_code_resend_mail_failure_keeps_the_previous_code_valid(): void
    {
        config(['ballspot.auth.force_login_2fa' => true]);
        [$user] = $this->auth();

        Notification::fake();
        $login = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()->assertJsonPath('requires_2fa', true);
        $code = null;
        Notification::assertSentTo($user, LoginVerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $verificationId = $login->json('verification_id');

        // Past the resend cooldown, with the mail transport now down.
        $this->travel(61)->seconds();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('smtp down'));

        $this->postJson('/api/login/resend-code', ['verification_id' => $verificationId])
            ->assertStatus(503)
            ->assertJsonPath('code', 'two_factor_send_failed');

        // The code from the first email still completes the login.
        $this->postJson('/api/login/verify', ['verification_id' => $verificationId, 'code' => $code])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    // ------------------------------------------------------------------
    // 3. Per-email login ceiling: counts FAILED attempts only, so 50 junk
    //    requests with a victim's address can no longer lock the victim (or
    //    the one admin) out for an hour.
    // ------------------------------------------------------------------

    public function test_successful_logins_never_consume_the_per_email_ceiling(): void
    {
        [$user] = $this->auth();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])->assertOk();
        }

        $this->assertSame(0, FailedLoginLimiter::failures(FailedLoginLimiter::SCOPE_LOGIN, $user->email));
    }

    public function test_a_correct_password_clears_earlier_failures(): void
    {
        [$user] = $this->auth();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => "wrong-{$i}"])->assertStatus(422);
        }
        $this->assertSame(3, FailedLoginLimiter::failures(FailedLoginLimiter::SCOPE_LOGIN, $user->email));

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])->assertOk();

        $this->assertSame(0, FailedLoginLimiter::failures(FailedLoginLimiter::SCOPE_LOGIN, $user->email));
    }

    public function test_the_failure_ceiling_still_locks_an_address_with_a_clean_429(): void
    {
        [$user] = $this->auth();
        for ($i = 0; $i < FailedLoginLimiter::MAX_FAILURES; $i++) {
            FailedLoginLimiter::recordFailure(FailedLoginLimiter::SCOPE_LOGIN, $user->email);
        }

        $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);

        $res->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message', 'retry_after']);
        $this->assertStringNotContainsString('token', $res->getContent());
    }

    public function test_admin_login_counts_only_failures_and_locks_after_the_ceiling(): void
    {
        $admin = $this->admin();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertSame(1, FailedLoginLimiter::failures(FailedLoginLimiter::SCOPE_ADMIN, $admin->email));

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password123'])->assertRedirect('/admin/challenges');
        $this->assertSame(0, FailedLoginLimiter::failures(FailedLoginLimiter::SCOPE_ADMIN, $admin->email));

        for ($i = 0; $i < FailedLoginLimiter::MAX_FAILURES; $i++) {
            FailedLoginLimiter::recordFailure(FailedLoginLimiter::SCOPE_ADMIN, $admin->email);
        }
        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password123'])->assertStatus(429);
    }

    public function test_the_middleware_login_limiters_are_per_ip_only(): void
    {
        $request = Request::create('/api/login', 'POST', ['email' => 'victim@example.com']);

        $login = RateLimiter::limiter('login')($request);
        $this->assertIsArray($login);
        foreach ($login as $limit) {
            $this->assertInstanceOf(Limit::class, $limit);
            $this->assertStringNotContainsString('login-email|', (string) $limit->key);
        }

        $admin = RateLimiter::limiter('admin-login')($request);
        $this->assertInstanceOf(Limit::class, $admin);
        $this->assertStringContainsString('admin-login|', (string) $admin->key);
        $this->assertStringNotContainsString('victim@example.com', (string) $admin->key);
    }

    // ------------------------------------------------------------------
    // 4. Guess submits that race the exists() check: the unique index keeps
    //    one row and the response is the normal replay 422, never a 500.
    // ------------------------------------------------------------------

    public function test_a_duplicate_round_guess_that_races_the_check_is_a_422_not_a_500(): void
    {
        [$user, $token] = $this->auth();
        $league = $this->league($user);
        $round  = $this->round($league, 1);

        // The concurrent request: its row lands after our exists() check and
        // before our insert.
        Guess::creating(function () use ($round, $user) {
            (new Guess)->forceFill([
                'league_round_id' => $round->id, 'user_id' => $user->id,
                'guess_x_ratio' => 0.4, 'guess_y_ratio' => 0.4, 'distance' => 0.1, 'score' => 50, 'submitted_at' => now(),
            ])->saveQuietly();
        });

        $res = $this->withToken($token)->postJson("/api/rounds/{$round->id}/guess", $this->guessPayload());

        $res->assertStatus(422)->assertJsonPath('code', 'already_submitted');
        $this->assertLessThanOrEqual(1, Guess::where('league_round_id', $round->id)->where('user_id', $user->id)->count());
    }

    public function test_a_sequential_duplicate_round_guess_carries_the_stable_code(): void
    {
        [$user, $token] = $this->auth();
        $league = $this->league($user);
        $this->round($league, 2); // a second open round so the first guess does not complete the tournament
        $round = $this->round($league, 1);

        $this->withToken($token)->postJson("/api/rounds/{$round->id}/guess", $this->guessPayload())->assertSuccessful();
        $this->withToken($token)->postJson("/api/rounds/{$round->id}/guess", $this->guessPayload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'already_submitted');

        $this->assertSame(1, Guess::where('user_id', $user->id)->count());
    }

    public function test_the_rounds_per_day_budget_still_holds_through_the_locked_path(): void
    {
        [$user, $token] = $this->auth();
        $league = $this->league($user, roundsPerDay: 1);
        $rounds = [$this->round($league, 1), $this->round($league, 2), $this->round($league, 3)];

        $this->withToken($token)->postJson("/api/rounds/{$rounds[0]->id}/guess", $this->guessPayload())->assertSuccessful();
        $this->withToken($token)->postJson("/api/rounds/{$rounds[1]->id}/guess", $this->guessPayload())->assertStatus(422);
        $this->withToken($token)->postJson("/api/rounds/{$rounds[2]->id}/guess", $this->guessPayload())->assertStatus(422);

        $this->assertSame(1, Guess::where('user_id', $user->id)->count());
    }

    public function test_a_duplicate_daily_guess_that_races_the_check_is_a_422_not_a_500(): void
    {
        [$user, $token] = $this->auth();
        $daily = $this->todayDaily();

        DailyChallengeGuess::creating(function () use ($daily, $user) {
            (new DailyChallengeGuess)->forceFill([
                'daily_challenge_id' => $daily->id, 'user_id' => $user->id,
                'guess_x_ratio' => 0.4, 'guess_y_ratio' => 0.4, 'distance' => 0.1, 'score' => 50, 'submitted_at' => now(),
            ])->saveQuietly();
        });

        $res = $this->withToken($token)->postJson("/api/daily/{$daily->id}/guess", $this->guessPayload());

        $res->assertStatus(422)->assertJsonPath('message', __('messages.daily.already_played'));
        $this->assertSame(1, DailyChallengeGuess::where('daily_challenge_id', $daily->id)->where('user_id', $user->id)->count());
    }

    // ------------------------------------------------------------------
    // 5. Tournament start is atomic: the status is re-read under a row lock
    //    so a second "Start" can never add a second set of rounds.
    // ------------------------------------------------------------------

    public function test_starting_from_a_stale_lobby_instance_is_refused_under_the_lock(): void
    {
        [$owner] = $this->auth();
        $this->tournamentPool();
        $league = $this->league($owner, status: 'lobby');

        $stale = League::find($league->id);
        // Another request won the race and started the tournament already.
        League::whereKey($league->id)->update(['status' => 'active']);

        try {
            app(LeagueService::class)->start($stale, $owner->id);
            $this->fail('Expected a 422 for a tournament that is no longer in the lobby.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertDatabaseCount('league_rounds', 0);
    }

    public function test_owner_can_start_once_and_a_second_start_adds_no_rounds(): void
    {
        [$owner, $token] = $this->auth();
        $this->tournamentPool();
        $league = $this->league($owner, status: 'lobby');

        $this->withToken($token)->postJson("/api/leagues/{$league->id}/start")->assertOk();
        $this->assertDatabaseCount('league_rounds', 7);

        $this->withToken($token)->postJson("/api/leagues/{$league->id}/start")->assertStatus(422);
        $this->assertDatabaseCount('league_rounds', 7);
        $this->assertSame('active', $league->fresh()->status);
    }
}
