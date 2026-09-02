<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\DailyChallenge;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\PushToken;
use App\Models\Sport;
use App\Models\User;
use App\Services\ExpoPushService;
use App\Support\AppLog;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * Structured operational logging (App\Support\AppLog → `events` channel).
 *
 * Asserts that the critical flows emit their event, that the context carries
 * IDs/reason codes, and — most importantly — that no secret (password, token,
 * beta code, login code, email, Expo token) ever reaches a log record.
 */
class ObservabilityLoggingTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $records;

    protected function setUp(): void
    {
        parent::setUp();

        // Capture the events channel in memory instead of writing files.
        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array<int, array{message: string, level: string, context: array}> */
    private function logged(?string $message = null): array
    {
        $all = array_map(fn ($r) => [
            'message' => $r->message,
            'level'   => strtolower($r->level->getName()),
            'context' => $r->context,
        ], $this->records->getRecords());

        return $message === null ? $all : array_values(array_filter($all, fn ($r) => $r['message'] === $message));
    }

    private function assertLogged(string $message, array $contextSubset = [], ?string $level = null): array
    {
        $matches = $this->logged($message);
        $this->assertNotEmpty($matches, "Expected log event '{$message}' — got: " . implode(', ', array_column($this->logged(), 'message')));

        $hit = collect($matches)->first(function ($r) use ($contextSubset) {
            foreach ($contextSubset as $k => $v) {
                if (!array_key_exists($k, $r['context']) || $r['context'][$k] !== $v) {
                    return false;
                }
            }
            return true;
        });
        $this->assertNotNull($hit, "Log event '{$message}' found but no record matched context " . json_encode($contextSubset) . ' — got ' . json_encode(array_column($matches, 'context')));

        if ($level !== null) {
            $this->assertSame($level, $hit['level']);
        }

        return $hit;
    }

    private function assertNotInLogs(string ...$needles): void
    {
        $dump = json_encode($this->logged());
        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $dump, "Secret leaked into logs: {$needle}");
        }
    }

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football', 'status' => Sport::STATUS_ACTIVE]);
    }

    private function challenge(string $title, array $overrides = []): Challenge
    {
        return Challenge::create(array_merge([
            'sport_id'          => $this->sport()->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'usage_pool'        => Challenge::POOL_GENERAL,
            'hidden_image_path' => "challenges/hidden/{$title}.jpg",
        ], $overrides));
    }

    private function verifiedUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['email_verified_at' => now()], $attrs));
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken];
    }

    // ------------------------------------------------------------------
    // AppLog helper
    // ------------------------------------------------------------------

    public function test_sanitize_drops_secret_keys_and_truncates_long_values(): void
    {
        $clean = AppLog::sanitize([
            'user_id'  => 7,
            'password' => 'hunter2',
            'token'    => 'abc',
            'code'     => '123456',
            'email'    => 'a@b.c',
            'nested'   => ['beta_code' => 'X', 'ok' => 1],
            'blob'     => str_repeat('x', 500),
        ]);

        $this->assertSame(7, $clean['user_id']);
        $this->assertArrayNotHasKey('password', $clean);
        $this->assertArrayNotHasKey('token', $clean);
        $this->assertArrayNotHasKey('code', $clean);
        $this->assertArrayNotHasKey('email', $clean);
        $this->assertSame(['ok' => 1], $clean['nested']);
        $this->assertLessThan(210, strlen($clean['blob']));
    }

    // ------------------------------------------------------------------
    // Auth / security
    // ------------------------------------------------------------------

    public function test_registration_logs_user_id_but_never_password_or_email(): void
    {
        config(['ballspot.beta_code' => null]);
        $password = 'SuperSecretPass123!';
        $email    = 'newplayer@example.com';

        $this->postJson('/api/register', [
            'name' => 'New', 'username' => 'newplayer', 'email' => $email, 'password' => $password,
            'terms_accepted' => true, 'age_confirmed' => true,
        ])->assertStatus(201);

        $userId = User::where('username', 'newplayer')->value('id');
        $this->assertLogged('auth.registered', ['user_id' => $userId]);
        $this->assertNotInLogs($password, $email);
    }

    public function test_failed_login_logs_reason_category_only(): void
    {
        $user = $this->verifiedUser(['password' => bcrypt('right-password-1')]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password-9'])->assertStatus(422);
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password-9'])->assertStatus(422);

        $this->assertLogged('auth.login_failed', ['reason' => 'wrong_password', 'user_id' => $user->id], 'warning');
        $this->assertLogged('auth.login_failed', ['reason' => 'unknown_account'], 'warning');
        $this->assertNotInLogs('wrong-password-9', 'right-password-1', $user->email, 'nobody@example.com');
    }

    public function test_successful_login_does_not_log_the_token(): void
    {
        $user = $this->verifiedUser(['password' => bcrypt('right-password-1')]);

        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'right-password-1'])
            ->assertOk()->json('token');

        $this->assertNotEmpty($token);
        $this->assertNotInLogs($token, explode('|', $token)[1] ?? $token, 'right-password-1');
    }

    public function test_beta_code_rejection_logs_category_but_never_the_codes(): void
    {
        config(['ballspot.beta_code' => 'REALBETA2026']);
        $payload = [
            'name' => 'B', 'username' => 'betauser', 'email' => 'beta@example.com', 'password' => 'password123',
            'terms_accepted' => true, 'age_confirmed' => true,
        ];

        $this->postJson('/api/register', $payload)->assertStatus(422);
        $this->postJson('/api/register', $payload + ['beta_code' => 'WRONGGUESS'])->assertStatus(422);

        $this->assertLogged('auth.beta_code_rejected', ['reason' => 'missing_code'], 'warning');
        $this->assertLogged('auth.beta_code_rejected', ['reason' => 'invalid_code'], 'warning');
        $this->assertNotInLogs('REALBETA2026', 'WRONGGUESS', 'realbeta2026', 'beta@example.com', 'password123');
    }

    public function test_account_deletion_logs_user_id(): void
    {
        $user = $this->verifiedUser();

        $this->deleteJson('/api/account', [], $this->headers($user))->assertOk();

        $this->assertLogged('account.anonymized', ['user_id' => $user->id]);
        $this->assertNotInLogs($user->email);
    }

    // ------------------------------------------------------------------
    // Daily
    // ------------------------------------------------------------------

    public function test_daily_schedule_command_logs_each_scheduled_date_and_a_run_summary(): void
    {
        $a = $this->challenge('A');
        $b = $this->challenge('B');

        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 2, '--start' => '2030-01-01'])->assertSuccessful();

        $this->assertLogged('daily.scheduled', ['date' => '2030-01-01', 'sport_id' => $a->sport_id, 'status' => 'scheduled']);
        $this->assertLogged('daily.scheduled', ['date' => '2030-01-02']);
        $run = $this->assertLogged('daily.schedule_run', ['created' => 2, 'replaced' => 0, 'skipped' => 0]);
        $this->assertSame(2, $run['context']['eligible_count']);
        $this->assertContains(DailyChallenge::where('challenge_date', '2030-01-01')->value('challenge_id'), [$a->id, $b->id]);
    }

    public function test_daily_schedule_command_logs_exhaustion_and_failure(): void
    {
        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 1])->assertFailed();
        $this->assertLogged('daily.schedule_failed', ['reason' => 'no_eligible_challenges', 'eligible_count' => 0], 'error');

        $this->challenge('Only one');
        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 3, '--start' => '2030-02-01'])->assertSuccessful();
        $this->assertLogged('daily.pool_exhausted', ['reason' => 'pool_exhausted', 'eligible_count' => 1, 'requested_days' => 3], 'warning');
    }

    public function test_admin_batch_scheduling_logs_created_and_skipped(): void
    {
        $ok   = $this->challenge('OK');
        $used = $this->challenge('Used');
        DailyChallenge::create(['challenge_id' => $used->id, 'challenge_date' => '2020-01-01', 'status' => 'archived']);

        app(\App\Services\DailyChallengeScheduler::class)->schedule([$ok->id, $used->id], '2030-03-01');

        $this->assertLogged('daily.scheduled', ['challenge_id' => $ok->id, 'date' => '2030-03-01', 'source' => 'admin']);
        $this->assertLogged('daily.schedule_skipped', ['source' => 'admin', 'skipped_count' => 1, 'created_count' => 1], 'warning');
    }

    public function test_reset_test_daily_history_logs_dry_run_and_confirmed_counts(): void
    {
        $c = $this->challenge('H');
        DailyChallenge::create(['challenge_id' => $c->id, 'challenge_date' => '2020-01-01', 'status' => 'archived']);

        $this->artisan('ballspot:reset-test-daily-history')->assertSuccessful();
        $this->assertLogged('daily.history_reset_dry_run', ['daily_challenges' => 1, 'affected_challenges' => 1]);

        $this->artisan('ballspot:reset-test-daily-history', ['--force' => true, '--confirm-prelaunch' => true])->assertSuccessful();
        $this->assertLogged('daily.history_reset', ['deleted_daily_challenges' => 1], 'warning');
    }

    // ------------------------------------------------------------------
    // Tournaments
    // ------------------------------------------------------------------

    public function test_tournament_create_join_and_cap_rejections_are_logged(): void
    {
        $this->seed(BadgeSeeder::class);
        $this->sport();
        $host   = $this->verifiedUser();
        $joiner = $this->verifiedUser();

        $leagueId = $this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => 7], $this->headers($host))
            ->assertCreated()->json('data.id');
        $this->assertLogged('tournament.created', ['league_id' => $leagueId, 'user_id' => $host->id, 'duration_days' => 7]);

        // Host cap: one hosted lobby/active tournament at a time.
        $this->postJson('/api/leagues', ['name' => 'Cup 2', 'duration_days' => 7], $this->headers($host))->assertStatus(422);
        $this->assertLogged('tournament.cap_rejected', ['user_id' => $host->id, 'reason' => 'host_limit']);

        $code = League::find($leagueId)->join_code;
        // Second user in the same test: forget the cached Sanctum guard first.
        $this->actingWithToken($joiner->createToken('t')->plainTextToken)
            ->postJson('/api/leagues/join', ['join_code' => $code])->assertOk();
        $this->assertLogged('tournament.joined', ['league_id' => $leagueId, 'user_id' => $joiner->id]);

        // Join code is a shareable secret-ish value: never logged.
        $this->assertNotInLogs($code);
    }

    public function test_tournament_start_failure_logs_pool_numbers_and_success_logs_selection(): void
    {
        $this->seed(BadgeSeeder::class);
        $sport = $this->sport();
        $host  = $this->verifiedUser();
        $h     = $this->headers($host);

        $leagueId = $this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => 7], $h)->assertCreated()->json('data.id');

        // Only 3 tournament-eligible photos for a 7-day tournament.
        for ($i = 0; $i < 3; $i++) {
            $this->challenge("t{$i}");
        }
        $this->postJson("/api/leagues/{$leagueId}/start", [], $h)->assertStatus(422);
        $this->assertLogged('tournament.start_failed', [
            'league_id' => $leagueId, 'reason' => 'not_enough_challenges', 'sport_id' => $sport->id,
            'requested_count' => 7, 'eligible_count' => 3,
        ], 'warning');

        for ($i = 3; $i < 7; $i++) {
            $this->challenge("t{$i}");
        }
        $this->postJson("/api/leagues/{$leagueId}/start", [], $h)->assertOk();
        $this->assertLogged('tournament.started', ['league_id' => $leagueId, 'selected_challenge_count' => 7, 'member_count' => 1]);
    }

    public function test_tournament_completion_logs_completion_and_trophies(): void
    {
        $this->seed(BadgeSeeder::class);
        $sport = $this->sport();
        $challenge = $this->challenge('R');
        $owner = User::factory()->create();
        $second = User::factory()->create();
        $third = User::factory()->create();

        $league = League::create([
            'name' => 'T', 'join_code' => strtoupper(Str::random(6)), 'owner_user_id' => $owner->id,
            'sport_id' => $sport->id, 'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'active',
            'starts_at' => now(), 'ends_at' => now()->addDay(),
        ]);
        $round = LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $challenge->id, 'round_number' => 1, 'status' => 'open']);
        foreach ([$owner, $second, $third] as $u) {
            LeagueMember::create(['league_id' => $league->id, 'user_id' => $u->id, 'joined_at' => now()]);
        }
        foreach ([[$owner, 100], [$second, 60], [$third, 20]] as [$u, $score]) {
            \App\Models\Guess::create([
                'league_round_id' => $round->id, 'user_id' => $u->id, 'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
                'distance' => 1 - $score / 100, 'score' => $score, 'submitted_at' => now(),
            ]);
        }

        $result = app(\App\Services\TournamentCompletionService::class)->completeIfFinished($league);
        $this->assertNotNull($result);

        $this->assertLogged('tournament.completed', ['league_id' => $league->id, 'participant_count' => 3, 'winner_user_id' => $owner->id]);
        $this->assertLogged('trophy.awarded', ['badge_code' => 'tournament_winner', 'user_id' => $owner->id, 'league_id' => $league->id]);
    }

    // ------------------------------------------------------------------
    // Packs
    // ------------------------------------------------------------------

    public function test_pack_start_completion_and_trophy_events_are_logged(): void
    {
        $this->seed(BadgeSeeder::class);
        $user = $this->verifiedUser();
        $h    = $this->headers($user);

        $badge = \App\Models\Badge::create(['code' => 'pack_1_completed', 'name' => 'P', 'description' => 'd', 'icon' => '🏆', 'category' => 'pack', 'rarity' => 'common']);
        $pack = ChallengePack::create([
            'name' => 'Pack', 'slug' => 'pack', 'status' => 'active', 'visibility' => 'public',
            'sport_id' => $this->sport()->id, 'completion_badge_id' => $badge->id,
        ]);
        $c = $this->challenge('P1', ['usage_pool' => Challenge::POOL_PACK]);
        $pack->challenges()->attach($c->id, ['sort_order' => 0]);

        $attemptId = $this->postJson('/api/packs/pack/start', [], $h)->assertOk()->json('attempt.id');
        $this->assertLogged('pack.started', ['pack_id' => $pack->id, 'user_id' => $user->id, 'attempt_id' => $attemptId, 'challenge_count' => 1]);

        $this->postJson("/api/pack-attempts/{$attemptId}/guess", ['challenge_id' => $c->id, 'guessed_x' => 0.5, 'guessed_y' => 0.5], $h)
            ->assertOk()->assertJsonPath('pack_completed', true);

        $this->assertLogged('pack.completed', ['pack_id' => $pack->id, 'user_id' => $user->id, 'replay' => false]);
        $this->assertLogged('pack.trophy_awarded', ['pack_id' => $pack->id, 'user_id' => $user->id, 'badge_id' => $badge->id]);
        $this->assertEmpty($this->logged('pack.trophy_skipped'));
    }

    public function test_pack_without_trophy_logs_skipped_and_empty_pack_logs_start_failure(): void
    {
        $this->seed(BadgeSeeder::class);
        $user = $this->verifiedUser();
        $h    = $this->headers($user);

        $empty = ChallengePack::create(['name' => 'Empty', 'slug' => 'empty', 'status' => 'active', 'visibility' => 'public']);
        $this->postJson('/api/packs/empty/start', [], $h)->assertStatus(422);
        $this->assertLogged('pack.start_failed', ['pack_id' => $empty->id, 'user_id' => $user->id, 'reason' => 'no_ready_challenges'], 'warning');

        $plain = ChallengePack::create(['name' => 'Plain', 'slug' => 'plain', 'status' => 'active', 'visibility' => 'public', 'sport_id' => $this->sport()->id]);
        $c = $this->challenge('Q1', ['usage_pool' => Challenge::POOL_PACK]);
        $plain->challenges()->attach($c->id, ['sort_order' => 0]);
        $attemptId = $this->postJson('/api/packs/plain/start', [], $h)->assertOk()->json('attempt.id');
        $this->postJson("/api/pack-attempts/{$attemptId}/guess", ['challenge_id' => $c->id, 'guessed_x' => 0.5, 'guessed_y' => 0.5], $h)->assertOk();

        $this->assertLogged('pack.trophy_skipped', ['pack_id' => $plain->id, 'user_id' => $user->id, 'reason' => 'no_trophy_configured']);
    }

    // ------------------------------------------------------------------
    // Uploads / admin content
    // ------------------------------------------------------------------

    public function test_admin_challenge_create_logs_ids_and_validation_failures_log_field_names_only(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);
        $sport = $this->sport();

        $this->actingAs($admin)->post('/admin/challenges', [
            'title' => 'Uploaded', 'difficulty' => 'easy', 'status' => 'active', 'usage_pool' => 'tournament',
            'sport_id' => $sport->id, 'ball_x_ratio' => 0.4, 'ball_y_ratio' => 0.6,
            'hidden_image' => UploadedFile::fake()->image('hidden.jpg'),
        ])->assertRedirect('/admin/challenges');

        $id = Challenge::where('title', 'Uploaded')->value('id');
        $this->assertLogged('admin.challenge_created', ['challenge_id' => $id, 'usage_pool' => 'tournament', 'sport_id' => $sport->id, 'status' => 'active']);

        // Bad upload: wrong type + missing ball position.
        $this->actingAs($admin)->post('/admin/challenges', [
            'title' => 'Bad', 'difficulty' => 'easy', 'status' => 'draft',
            'hidden_image' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors(['hidden_image', 'ball_x_ratio']);

        $hit = $this->assertLogged('admin.challenge_validation_failed', ['upload_related' => true], 'warning');
        $this->assertContains('hidden_image', $hit['context']['fields']);
        $this->assertNotInLogs('doc.pdf', 'Bad');
    }

    public function test_admin_sport_and_pack_changes_are_logged(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->sport();

        $form = ['name' => 'Padel', 'slug' => 'padel', 'emoji' => '🎾', 'object_name' => 'ball', 'primary_color' => '#112233', 'sort_order' => 5];

        $this->actingAs($admin)->post('/admin/sports', $form)->assertRedirect('/admin/sports');
        $sportId = Sport::where('slug', 'padel')->value('id');
        $this->assertLogged('admin.sport_created', ['sport_id' => $sportId, 'slug' => 'padel', 'status' => Sport::STATUS_COMING_SOON]);

        $this->actingAs($admin)->put("/admin/sports/{$sportId}", $form + ['status' => 'active'])->assertRedirect('/admin/sports');
        $this->assertLogged('admin.sport_updated', ['sport_id' => $sportId, 'status' => 'active']);

        $this->actingAs($admin)->post('/admin/packs', ['name' => 'Logged Pack', 'status' => 'draft', 'visibility' => 'hidden']);
        $packId = ChallengePack::where('name', 'Logged Pack')->value('id');
        $this->assertLogged('admin.pack_created', ['pack_id' => $packId, 'status' => 'draft', 'visibility' => 'hidden', 'challenge_count' => 0]);

        $this->actingAs($admin)->post("/admin/packs/{$packId}/status", ['status' => 'active'])->assertRedirect('/admin/packs');
        $this->assertLogged('admin.pack_updated', ['pack_id' => $packId, 'status' => 'active']);
    }

    // ------------------------------------------------------------------
    // Push / notifications
    // ------------------------------------------------------------------

    public function test_push_failures_log_reason_categories_but_never_tokens(): void
    {
        Http::fake([
            '*' => Http::response(['data' => [
                ['status' => 'ok'],
                ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
                ['status' => 'error', 'details' => ['error' => 'MessageRateExceeded']],
            ]], 200),
        ]);
        $u = User::factory()->create();
        $good = 'ExponentPushToken[good-token-aaa]';
        $dead = 'ExponentPushToken[dead-token-bbb]';
        $slow = 'ExponentPushToken[slow-token-ccc]';
        foreach ([$good, $dead, $slow] as $t) {
            PushToken::create(['user_id' => $u->id, 'token' => $t]);
        }

        $outcome = app(ExpoPushService::class)->sendMessages(array_map(fn ($t) => ['to' => $t, 'title' => 'x', 'body' => 'y', 'sound' => 'default'], [$good, $dead, $slow]));

        $this->assertSame(['sent' => 1, 'failed' => 2, 'invalid_tokens_removed' => 1], $outcome);
        $this->assertLogged('push.attempt', ['message_count' => 3]);
        $hit = $this->assertLogged('push.failures', ['sent' => 1, 'failed' => 2, 'invalid_tokens_removed' => 1], 'warning');
        $this->assertSame(['DeviceNotRegistered' => 1, 'MessageRateExceeded' => 1], $hit['context']['reasons']);
        $this->assertNotInLogs($good, $dead, $slow, 'good-token-aaa', 'dead-token-bbb');
    }

    public function test_push_http_failure_logs_status_category(): void
    {
        Http::fake(['*' => Http::response('nope', 502)]);

        app(ExpoPushService::class)->sendMessages([['to' => 'ExponentPushToken[zzz]', 'title' => 'x', 'body' => 'y', 'sound' => 'default']]);

        $this->assertLogged('push.batch_failed', ['reason' => 'http_status', 'status' => 502, 'count' => 1], 'warning');
        $this->assertNotInLogs('ExponentPushToken[zzz]');
    }

    public function test_push_success_logs_sent_count_only(): void
    {
        Http::fake(['*' => Http::response(['data' => [['status' => 'ok']]], 200)]);

        app(ExpoPushService::class)->sendMessages([['to' => 'ExponentPushToken[yyy]', 'title' => 'x', 'body' => 'y', 'sound' => 'default']]);

        $this->assertLogged('push.sent', ['sent' => 1, 'failed' => 0]);
        $this->assertEmpty($this->logged('push.failures'));
        $this->assertNotInLogs('ExponentPushToken[yyy]');
    }

    public function test_notification_settings_update_logs_user_id_and_field_names_only(): void
    {
        $user = $this->verifiedUser();

        $this->putJson('/api/me/notification-settings', ['daily_reminder_enabled' => false, 'timezone' => 'Europe/Brussels'], $this->headers($user))
            ->assertOk();

        $hit = $this->assertLogged('notifications.settings_updated', ['user_id' => $user->id]);
        $this->assertEqualsCanonicalizing(['daily_reminder_enabled', 'timezone'], $hit['context']['fields']);
        $this->assertNotInLogs('Europe/Brussels', $user->email);
    }

    // ------------------------------------------------------------------
    // Noise budget
    // ------------------------------------------------------------------

    public function test_ordinary_reads_do_not_log_events(): void
    {
        $user = $this->verifiedUser();
        $h = $this->headers($user);
        $this->sport();

        $this->getJson('/api/me', $h)->assertOk();
        $this->getJson('/api/daily/today', $h)->assertOk();
        $this->getJson('/api/leagues', $h)->assertOk();
        $this->getJson('/api/packs', $h)->assertOk();
        $this->getJson('/api/health')->assertOk();

        $this->assertSame([], $this->logged(), 'Page views must not produce operational log events.');
    }
}
