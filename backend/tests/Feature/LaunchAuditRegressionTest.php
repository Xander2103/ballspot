<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use App\Services\CompetitionStandingsService;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * Regression tests for the 2026-09-21 production security & readiness audit.
 * One test per fix, grouped by audit area. Every assertion here pins a
 * behaviour that was either wrong or unguarded before the audit.
 */
class LaunchAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);
    }

    private function logged(string $message): array
    {
        return array_values(array_filter($this->records->getRecords(), fn ($r) => $r->message === $message));
    }

    private function routeMiddleware(string $uri, string $method): array
    {
        foreach (app('router')->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route->gatherMiddleware();
            }
        }
        $this->fail("Route {$method} {$uri} not found.");
    }

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football', 'status' => Sport::STATUS_ACTIVE, 'emoji' => '⚽', 'object_name' => 'ball']);
    }

    private function challenge(Sport $sport, string $title = 'Photo'): Challenge
    {
        return Challenge::create([
            'sport_id' => $sport->id, 'title' => $title, 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active', 'hidden_image_path' => 'challenges/hidden/' . uniqid() . '.jpg',
        ]);
    }

    private function player(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['is_admin' => false, 'email_verified_at' => now()], $overrides));
    }

    /** An active league with one open round, owned by $owner, with the given members. */
    private function activeLeague(User $owner, array $members = []): array
    {
        $sport  = $this->sport();
        $league = League::create([
            'name' => 'Cup', 'join_code' => strtoupper(substr(md5(uniqid('', true)), 0, 6)), 'owner_user_id' => $owner->id,
            'sport_id' => $sport->id, 'duration_days' => 7, 'rounds_per_day' => 1, 'status' => 'active', 'starts_at' => now(),
        ]);
        foreach (array_merge([$owner], $members) as $m) {
            LeagueMember::create(['league_id' => $league->id, 'user_id' => $m->id, 'joined_at' => now()]);
        }
        $round = LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $this->challenge($sport)->id, 'round_number' => 1, 'status' => 'open', 'opens_at' => now()]);

        return [$league, $round];
    }

    // ------------------------------------------------------------------
    // Authorization / route hygiene
    // ------------------------------------------------------------------

    public function test_admin_categories_show_route_is_not_registered(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        // Previously a guaranteed 500 (BadMethodCallException: no show()).
        $this->actingAs($admin)->get('/admin/categories/1')->assertStatus(405);
    }

    public function test_every_admin_route_carries_the_admin_middleware(): void
    {
        $unguarded = [];
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            if (!str_starts_with($uri, 'admin')) {
                continue;
            }
            if (in_array($uri, ['admin/login', 'admin/logout'], true)) {
                continue;
            }
            if (!in_array('admin', $route->gatherMiddleware(), true)) {
                $unguarded[] = implode('|', $route->methods()) . ' ' . $uri;
            }
        }

        $this->assertSame([], $unguarded, 'Every /admin/* route (except login/logout) must carry the admin middleware');
    }

    public function test_csrf_is_never_excluded_and_admin_forms_post(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringNotContainsString('validateCsrfTokens', $bootstrap, 'No CSRF exclusions may be registered');
        $this->assertSame([], glob(app_path('Http/Middleware/VerifyCsrfToken.php')) ?: [], 'No custom CSRF middleware with an $except list');
    }

    public function test_non_members_cannot_read_a_tournament_leaderboard_round_or_guess(): void
    {
        $owner = $this->player();
        [$league, $round] = $this->activeLeague($owner);
        $stranger = $this->player();
        $token    = $stranger->createToken('m')->plainTextToken;

        $this->withToken($token)->getJson("/api/leagues/{$league->id}/leaderboard")->assertStatus(403);
        $this->withToken($token)->getJson("/api/leagues/{$league->id}/current-round")->assertStatus(403);
        $this->withToken($token)->postJson("/api/rounds/{$round->id}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5])->assertStatus(403);
        $this->withToken($token)->getJson("/api/rounds/{$round->id}/result")->assertStatus(404);
        $this->assertSame(0, Guess::count());
    }

    public function test_guess_coordinates_outside_the_image_are_rejected(): void
    {
        $owner = $this->player();
        [, $round] = $this->activeLeague($owner);
        $token = $owner->createToken('m')->plainTextToken;

        foreach ([[-0.1, 0.5], [1.5, 0.5], ['NaN', 0.5], [0.5, '1e999'], [null, 0.5]] as [$x, $y]) {
            $this->actingWithToken($token)->postJson("/api/rounds/{$round->id}/guess", ['guess_x_ratio' => $x, 'guess_y_ratio' => $y])->assertStatus(422);
        }
        $this->assertSame(0, Guess::count());
    }

    // ------------------------------------------------------------------
    // Authentication
    // ------------------------------------------------------------------

    public function test_admin_login_logs_safe_reason_categories_and_never_the_credentials(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now(), 'email' => 'boss@example.com', 'password' => bcrypt('correct-horse-9')]);
        $user  = User::factory()->create(['is_admin' => false, 'email_verified_at' => now(), 'email' => 'player@example.com', 'password' => bcrypt('correct-horse-9')]);

        $this->post('/admin/login', ['email' => 'boss@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/admin/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/admin/login', ['email' => 'player@example.com', 'password' => 'correct-horse-9'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/admin/login', ['email' => 'BOSS@example.com', 'password' => 'correct-horse-9'])->assertRedirect('/admin/challenges');
        $this->assertAuthenticatedAs($admin);

        $failed = $this->logged('admin.login_failed');
        $this->assertSame(['wrong_password', 'unknown_account', 'not_admin'], array_map(fn ($r) => $r->context['reason'], $failed));
        $this->assertSame($user->id, $failed[2]->context['user_id']);
        $this->assertNotEmpty($this->logged('admin.login'));

        $all = json_encode(array_map(fn ($r) => [$r->message, $r->context], $this->records->getRecords()));
        $this->assertStringNotContainsString('correct-horse-9', $all);
        $this->assertStringNotContainsString('example.com', $all);
    }

    public function test_admin_login_does_not_reveal_whether_an_account_is_an_admin(): void
    {
        User::factory()->create(['is_admin' => false, 'email_verified_at' => now(), 'email' => 'player@example.com', 'password' => bcrypt('correct-horse-9')]);

        $nonAdmin = $this->post('/admin/login', ['email' => 'player@example.com', 'password' => 'correct-horse-9']);
        $wrong    = $this->post('/admin/login', ['email' => 'player@example.com', 'password' => 'nope']);

        $this->assertSame(
            session()->get('errors')?->first('email'),
            $wrong->getSession()->get('errors')->first('email')
        );
        $this->assertSame('Invalid credentials.', $nonAdmin->getSession()->get('errors')->first('email'));
    }

    public function test_email_is_case_insensitive_on_register_login_and_password_reset(): void
    {
        config(['ballspot.auth.require_email_verification' => false]);

        $this->postJson('/api/register', [
            'name' => 'Cap', 'username' => 'capuser', 'email' => 'Cap.User@Example.COM', 'password' => 'password123',
            'terms_accepted' => true, 'age_confirmed' => true,
        ])->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'cap.user@example.com']);

        // Same mailbox, different case → rejected as taken, not a second account.
        $this->postJson('/api/register', [
            'name' => 'Cap', 'username' => 'capuser2', 'email' => 'CAP.USER@example.com', 'password' => 'password123',
            'terms_accepted' => true, 'age_confirmed' => true,
        ])->assertStatus(422)->assertJsonPath('code', 'email_taken');

        // Legacy mixed-case row (written before normalisation) still logs in.
        $legacy = User::factory()->create(['email' => 'Legacy@Example.com', 'email_verified_at' => now(), 'password' => bcrypt('password123')]);
        $this->postJson('/api/login', ['email' => 'legacy@example.com', 'password' => 'password123'])
            ->assertOk()->assertJsonPath('user.id', $legacy->id);

        // Forgot-password resolves the same row and issues a token for it.
        \Illuminate\Support\Facades\Notification::fake();
        $this->postJson('/api/forgot-password', ['email' => 'LEGACY@EXAMPLE.COM'])->assertOk();
        \Illuminate\Support\Facades\Notification::assertSentTo($legacy, \App\Notifications\ResetPasswordNotification::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'Legacy@Example.com']);
    }

    public function test_email_and_password_lengths_are_bounded_with_a_422(): void
    {
        $long = str_repeat('a', 250) . '@example.com'; // valid RFC, wider than the column
        $this->postJson('/api/register', [
            'name' => 'Long', 'username' => 'longuser', 'email' => $long, 'password' => 'password123',
            'terms_accepted' => true, 'age_confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/register', [
            'name' => 'Long', 'username' => 'longuser', 'email' => 'ok@example.com', 'password' => str_repeat('p', 300),
            'terms_accepted' => true, 'age_confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/login', ['email' => $long, 'password' => 'x'])->assertStatus(422);
        $this->postJson('/api/forgot-password', ['email' => $long])->assertStatus(422);
    }

    public function test_api_tokens_expire_by_default(): void
    {
        $minutes = (int) config('sanctum.expiration');
        $this->assertGreaterThan(0, $minutes, 'Sanctum tokens must have a lifetime even when the env var is unset');
        $this->assertLessThanOrEqual(180 * 24 * 60, $minutes);

        $user  = $this->player();
        $token = $user->createToken('m')->plainTextToken;
        $this->withToken($token)->getJson('/api/me')->assertOk();

        $this->travel($minutes + 1)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertStatus(401)->assertJsonPath('code', 'session_invalid');
    }

    // ------------------------------------------------------------------
    // Rate limiting
    // ------------------------------------------------------------------

    public function test_account_deletion_tournament_writes_and_pack_start_carry_dedicated_throttles(): void
    {
        $this->assertContains('throttle:account-delete', $this->routeMiddleware('api/account', 'DELETE'));
        $this->assertContains('throttle:tournaments', $this->routeMiddleware('api/leagues', 'POST'));
        $this->assertContains('throttle:tournaments', $this->routeMiddleware('api/leagues/join', 'POST'));
        $this->assertContains('throttle:tournaments', $this->routeMiddleware('api/leagues/{league}/start', 'POST'));
        $this->assertContains('throttle:tournaments', $this->routeMiddleware('api/leagues/{league}', 'DELETE'));
        $this->assertContains('throttle:gameplay', $this->routeMiddleware('api/packs/{slug}/start', 'POST'));
    }

    public function test_account_deletion_is_throttled_after_three_attempts_per_hour(): void
    {
        $user  = $this->player();
        $token = $user->createToken('m')->plainTextToken;
        // Simulate three failed attempts by making the service refuse.
        $service = \Mockery::mock(\App\Services\AccountDeletionService::class);
        $service->shouldReceive('delete')->times(3)->andThrow(new \RuntimeException('busy'));
        $this->app->instance(\App\Services\AccountDeletionService::class, $service);

        for ($i = 0; $i < 3; $i++) {
            $this->withToken($token)->deleteJson('/api/account')->assertStatus(500);
        }
        $this->withToken($token)->deleteJson('/api/account')->assertStatus(429)->assertJsonStructure(['message', 'retry_after']);
    }

    // ------------------------------------------------------------------
    // Uploads
    // ------------------------------------------------------------------

    public function test_avatar_upload_rejects_oversized_pixel_dimensions(): void
    {
        Storage::fake('public');
        $user  = $this->player();
        $token = $user->createToken('m')->plainTextToken;
        $max   = (int) config('ballspot.avatar.max_px');

        $bomb = UploadedFile::fake()->image('wide.png', $max + 1, 8);
        $this->withToken($token)->post('/api/me/avatar', ['avatar' => $bomb], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('avatar');

        $ok = UploadedFile::fake()->image('ok.png', 64, 64);
        $this->actingWithToken($token)->post('/api/me/avatar', ['avatar' => $ok], ['Accept' => 'application/json'])->assertOk();
    }

    // ------------------------------------------------------------------
    // Readiness / logging / robots
    // ------------------------------------------------------------------

    public function test_store_readiness_check_warns_about_unsafe_production_settings(): void
    {
        config([
            'app.env' => 'production',
            'cors.allowed_origins' => ['*'],
            'sanctum.expiration' => 0,
            'session.secure' => false,
            'logging.channels.stack.channels' => ['single'],
            'mail.default' => 'log',
        ]);

        $this->artisan('ballspot:store-readiness-check')
            ->expectsOutputToContain('CORS_ALLOWED_ORIGINS is "*"')
            ->expectsOutputToContain('SANCTUM_TOKEN_EXPIRATION_MINUTES is empty')
            ->expectsOutputToContain('SESSION_SECURE_COOKIE is not true')
            ->expectsOutputToContain('TRUSTED_PROXIES is empty')
            ->expectsOutputToContain('LOG_STACK=single')
            ->expectsOutputToContain('MAIL_MAILER=log')
            ->assertExitCode(0);
    }

    public function test_store_readiness_check_passes_the_same_settings_when_safe(): void
    {
        config([
            'app.env' => 'production',
            'cors.allowed_origins' => ['https://ballpicker.vanmalderstudio.be'],
            'sanctum.expiration' => 129600,
            'session.secure' => true,
            'logging.channels.stack.channels' => ['daily', 'events_file'],
            'mail.default' => 'smtp',
        ]);

        $this->artisan('ballspot:store-readiness-check')
            ->expectsOutputToContain('CORS_ALLOWED_ORIGINS is restricted')
            ->expectsOutputToContain('SANCTUM_TOKEN_EXPIRATION_MINUTES=129600')
            ->expectsOutputToContain('SESSION_SECURE_COOKIE=true')
            ->expectsOutputToContain('MAIL_MAILER=smtp')
            ->assertExitCode(0);
    }

    public function test_applog_drops_additional_secret_shaped_keys(): void
    {
        $clean = AppLog::sanitize([
            'user_id' => 7, 'pin' => '1234', 'otp' => '999999', 'api_key' => 'k', 'access_token' => 'a',
            'refresh_token' => 'r', 'bearer' => 'b', 'verification_code' => 'v', 'private_key' => 'p', 'reason' => 'ok',
        ]);
        $this->assertSame(['user_id' => 7, 'reason' => 'ok'], $clean);
    }

    public function test_robots_txt_keeps_crawlers_out_of_admin_and_api(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));
        $this->assertStringContainsString('Disallow: /admin', $robots);
        $this->assertStringContainsString('Disallow: /api', $robots);
    }

    // ------------------------------------------------------------------
    // Gameplay integrity
    // ------------------------------------------------------------------

    public function test_deleted_accounts_are_excluded_from_live_standings_and_tournament_leaderboards(): void
    {
        $sport = $this->sport();
        $daily = DailyChallenge::create(['challenge_id' => $this->challenge($sport)->id, 'challenge_date' => now()->toDateString(), 'status' => 'active']);
        $alive = $this->player();
        $ghost = $this->player();
        foreach ([[$alive, 60], [$ghost, 100]] as [$u, $score]) {
            DailyChallengeGuess::create(['daily_challenge_id' => $daily->id, 'user_id' => $u->id, 'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.1, 'score' => $score, 'submitted_at' => now()]);
        }
        $ghost->forceFill(['anonymized_at' => now(), 'username' => "deleted-{$ghost->id}", 'name' => 'Deleted User'])->save();

        $standings = app(CompetitionStandingsService::class)->forWindow(now()->toDateString(), now()->toDateString());
        $this->assertSame([$alive->id], $standings->pluck('user_id')->all());
        $this->assertSame(1, $standings->first()['placement']);

        $token = $alive->createToken('m')->plainTextToken;
        $this->withToken($token)->getJson('/api/daily/leaderboard/weekly')->assertOk()
            ->assertJsonMissing(['username' => "deleted-{$ghost->id}"]);

        // Tournament leaderboard: same rule.
        [$league, $round] = $this->activeLeague($alive, [$ghost]);
        foreach ([[$alive, 50], [$ghost, 90]] as [$u, $score]) {
            Guess::create(['league_round_id' => $round->id, 'user_id' => $u->id, 'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.1, 'score' => $score, 'submitted_at' => now()]);
        }
        $this->actingWithToken($token)->getJson("/api/leagues/{$league->id}/leaderboard")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $alive->id)
            ->assertJsonPath('data.0.rank', 1);
    }

    public function test_daily_guess_is_refused_once_the_photo_is_archived(): void
    {
        $sport     = $this->sport();
        $challenge = $this->challenge($sport);
        $daily     = DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => now()->toDateString(), 'status' => 'active']);
        $user      = $this->player();
        $token     = $user->createToken('m')->plainTextToken;

        $challenge->update(['status' => 'archived']);

        $this->withToken($token)->postJson("/api/daily/{$daily->id}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5])
            ->assertStatus(422)->assertJsonPath('message', __('messages.daily.not_active'));
        $this->assertSame(0, DailyChallengeGuess::count());
    }

    public function test_pack_start_still_resumes_one_attempt_inside_the_new_transaction(): void
    {
        $sport = $this->sport();
        $pack  = \App\Models\ChallengePack::create(['name' => 'Starter', 'slug' => 'starter', 'status' => 'active', 'visibility' => 'public', 'sport_id' => $sport->id]);
        $pack->challenges()->attach($this->challenge($sport, 'One')->id, ['sort_order' => 1]);
        $user  = $this->player();
        $token = $user->createToken('m')->plainTextToken;

        $first  = $this->withToken($token)->postJson('/api/packs/starter/start')->assertOk()->json('attempt.id');
        $second = $this->actingWithToken($token)->postJson('/api/packs/starter/start')->assertOk()->json('attempt.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, \App\Models\PackAttempt::where('user_id', $user->id)->count());
    }
}
