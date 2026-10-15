<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Models\User;
use App\Services\DiagnosticsService;
use App\Support\AppLog;
use App\Support\ResilientRateLimiter;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * Pack Start incident (2026-10-15), confirmed from the production log:
 *   fopen(storage/framework/cache/data/14/e0/...): No such file or directory
 *   FileStore → RateLimiter → ThrottleRequests, repeatedly for one user.
 *
 * Pack list/detail loaded (global `api` limiter key) while Start Pack and
 * every guess (per-user `gameplay` key) answered 500: with the file driver
 * each key lives in its own hashed data/xx/yy/ directory, and the scheduler
 * cron running as root had created directories the web user could not write
 * into — makeDirectory() fails silently, fopen() then hits a path that does
 * not exist.
 *
 * These tests run the real rate limiter on a real file store in a temp dir:
 * the failure mode is pinned; the limiter first falls back to the database
 * store (limits still enforced), then — with no fallback — gameplay fails
 * OPEN while auth/mail limiters answer 503; `rate_limiter.store_failed` is
 * logged per stage; /api/health and /admin/diagnostics detect the state.
 */
class RateLimiterCacheTest extends TestCase
{
    use RefreshDatabase;

    private string $cacheDir;
    private TestHandler $records;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = sys_get_temp_dir() . '/bp-limiter-cache-' . uniqid();
        mkdir($this->cacheDir, 0777, true);

        config([
            'cache.default'               => 'file',
            'cache.limiter'               => 'file',
            'cache.stores.file.path'      => $this->cacheDir,
            'cache.stores.file.lock_path' => $this->cacheDir,
        ]);
        Cache::forgetDriver('file');

        // The RateLimiter singleton was built at boot on the test store; point
        // it at the file store exactly as production's CACHE_STORE=file would.
        $limiter = app(RateLimiter::class);
        $this->assertInstanceOf(ResilientRateLimiter::class, $limiter, 'AppServiceProvider must bind the resilient limiter');
        (new \ReflectionProperty($limiter, 'cache'))->setValue($limiter, Cache::store('file'));

        // Capture the events channel in memory instead of writing files.
        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+rwx ' . escapeshellarg($this->cacheDir) . ' && rm -rf ' . escapeshellarg($this->cacheDir));
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football', 'status' => Sport::STATUS_ACTIVE]);
    }

    private function challenge(string $title): Challenge
    {
        return Challenge::create([
            'sport_id' => $this->sport()->id, 'title' => $title, 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active', 'usage_pool' => Challenge::POOL_GENERAL,
            'hidden_image_path' => "challenges/hidden/{$title}.jpg",
        ]);
    }

    private function pack(): ChallengePack
    {
        $pack = ChallengePack::create([
            'name' => 'Beginners Football', 'slug' => 'beginners-football',
            'status' => ChallengePack::STATUS_ACTIVE, 'visibility' => ChallengePack::VISIBILITY_PUBLIC,
            'sport_id' => $this->sport()->id,
        ]);
        foreach ([1, 2] as $i) {
            $pack->challenges()->attach($this->challenge("c{$i}")->id, ['sort_order' => $i]);
        }

        return $pack;
    }

    /** Make the directories holding one named limiter's key unwritable (as a root-created 0755 dir is for www-data). */
    private function lockLimiterKeyDirs(string $limiterName, string $limitKey): void
    {
        $store = Cache::store('file')->getStore();
        $key   = md5($limiterName . $limitKey);
        foreach ([$key, $key . ':timer'] as $k) {
            $dir = dirname($store->path($k));
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            chmod($dir, 0555);
        }
    }

    /** The incident shape: the parent xx/ directory is root's, so xx/yy/ can never be created. */
    private function lockLimiterKeyParent(string $limiterName, string $limitKey): void
    {
        $store = Cache::store('file')->getStore();
        $key   = md5($limiterName . $limitKey);
        $parent = dirname(dirname($store->path($key)));
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        chmod($parent, 0555);
    }

    private function skipIfRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Permission bits do not restrict root.');
        }
    }

    /** Stage 2 only: no fallback store (as if the database cache were down as well). */
    private function withoutFallback(): void
    {
        config(['ballspot.rate_limiter.fallback_store' => null]);
    }

    private function storeFailures(): array
    {
        return array_values(array_filter(
            $this->records->getRecords(),
            fn ($r) => $r->message === 'rate_limiter.store_failed',
        ));
    }

    // ------------------------------------------------------------------
    // Healthy file store
    // ------------------------------------------------------------------

    public function test_pack_start_and_guess_work_with_the_file_cache_rate_limiter(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->pack();

        $start = $this->withToken($token)->postJson('/api/packs/beginners-football/start')
            ->assertOk()
            ->assertJsonPath('attempt.status', 'active');
        // Resume (the app calls start again from the guess screen).
        $this->withToken($token)->postJson('/api/packs/beginners-football/start')
            ->assertOk()
            ->assertJsonPath('attempt.id', $start->json('attempt.id'));

        $this->withToken($token)->postJson('/api/pack-attempts/' . $start->json('attempt.id') . '/guess', [
            'challenge_id' => $start->json('challenge.id'), 'guessed_x' => 0.5, 'guessed_y' => 0.5,
        ])->assertOk()->assertJsonPath('progress.current_index', 1);

        // The limiter really wrote to the file store, and nothing was reported.
        $this->assertNotEmpty(glob($this->cacheDir . '/*/*/*'));
        $this->assertCount(0, $this->storeFailures());
    }

    public function test_limits_are_still_enforced_on_a_healthy_store(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->pack();

        $last = null;
        for ($i = 0; $i < 31; $i++) {
            $last = $this->withToken($token)->postJson('/api/packs/beginners-football/start');
        }

        $last->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    // ------------------------------------------------------------------
    // Broken file store, stage 1: fallback to the database store
    // ------------------------------------------------------------------

    public function test_unwritable_gameplay_limiter_entry_no_longer_fails_pack_start(): void
    {
        $this->skipIfRoot();

        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->pack();
        $this->lockLimiterKeyDirs('gameplay', 'play|' . $user->id);

        $this->withToken($token)->getJson('/api/packs/beginners-football')->assertOk();
        $this->withToken($token)->postJson('/api/packs/beginners-football/start')
            ->assertOk()
            ->assertJsonPath('attempt.status', 'active');

        $failures = $this->storeFailures();
        $this->assertCount(1, $failures, 'the broken store is reported once');
        $this->assertSame('error', strtolower($failures[0]->level->getName()));
        $this->assertSame('file', $failures[0]->context['store']);
        $this->assertSame('gameplay', $failures[0]->context['limiter']);
        $this->assertSame('fallback:database', $failures[0]->context['policy']);
        $this->assertStringNotContainsString(base_path(), (string) $failures[0]->context['error']);
        // The counter moved to the database store.
        $this->assertGreaterThan(0, DB::table('cache')->count());
    }

    /** Exactly the production trace: the parent directory is unwritable, "No such file or directory". */
    public function test_missing_hashed_directory_that_cannot_be_created_falls_back(): void
    {
        $this->skipIfRoot();

        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->pack();
        $this->lockLimiterKeyParent('gameplay', 'play|' . $user->id);

        $this->withToken($token)->postJson('/api/packs/beginners-football/start')->assertOk();

        $failures = $this->storeFailures();
        $this->assertNotEmpty($failures);
        $this->assertStringContainsString('No such file or directory', (string) $failures[0]->context['error']);
    }

    public function test_limits_are_still_enforced_on_the_fallback_store(): void
    {
        $this->skipIfRoot();

        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->pack();
        $this->lockLimiterKeyDirs('gameplay', 'play|' . $user->id);

        $last = null;
        for ($i = 0; $i < 31; $i++) {
            $last = $this->withToken($token)->postJson('/api/packs/beginners-football/start');
        }

        $last->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    /** Email abuse ceilings survive the file-cache outage: the 4th reset request per minute is refused and sends nothing. */
    public function test_password_reset_mail_limit_is_enforced_on_the_fallback_store(): void
    {
        $this->skipIfRoot();
        Notification::fake();

        User::factory()->create(['email' => 'victim@example.test']);
        chmod($this->cacheDir, 0555);

        $statuses = [];
        for ($i = 0; $i < 4; $i++) {
            $statuses[] = $this->postJson('/api/forgot-password', ['email' => 'victim@example.test'])->status();
        }

        $this->assertSame(429, $statuses[3], 'fp-e limit (3/min) must hold on the fallback store: ' . json_encode($statuses));
        $this->assertLessThan(500, max($statuses));
        // (The password broker's own 60 s throttle already caps mails at one
        // per minute; the point here is that the 4th request is refused by the
        // limiter instead of running unprotected.)
    }

    // ------------------------------------------------------------------
    // Stage 2: no fallback — gameplay open, auth/mail closed
    // ------------------------------------------------------------------

    public function test_gameplay_fails_open_when_no_fallback_store_is_available(): void
    {
        $this->skipIfRoot();
        $this->withoutFallback();

        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->pack();
        $this->lockLimiterKeyDirs('gameplay', 'play|' . $user->id);

        $this->withToken($token)->postJson('/api/packs/beginners-football/start')->assertOk();

        $failures = $this->storeFailures();
        $this->assertCount(1, $failures);
        $this->assertSame('open', $failures[0]->context['policy']);
    }

    public function test_daily_guess_fails_open_when_no_fallback_store_is_available(): void
    {
        $this->skipIfRoot();
        $this->withoutFallback();

        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $daily = DailyChallenge::create([
            'challenge_id' => $this->challenge('daily')->id, 'challenge_date' => now()->toDateString(), 'status' => 'active',
        ]);
        chmod($this->cacheDir, 0555);

        $res = $this->withToken($token)->postJson("/api/daily/{$daily->id}/guess", ['guessed_x' => 0.5, 'guessed_y' => 0.5]);

        $this->assertLessThan(500, $res->status(), 'a broken limiter store must never 500 a guess: ' . substr($res->getContent(), 0, 200));
        $this->assertNotEmpty($this->storeFailures());
    }

    /** Mail-producing and credential routes answer 503 instead of running unprotected. */
    public function test_auth_and_mail_limiters_fail_closed_without_a_fallback(): void
    {
        $this->skipIfRoot();
        $this->withoutFallback();
        Notification::fake();

        User::factory()->create(['email' => 'victim@example.test']);
        chmod($this->cacheDir, 0555);

        $this->postJson('/api/forgot-password', ['email' => 'victim@example.test'])
            ->assertStatus(503)
            ->assertJsonStructure(['message']);
        $this->postJson('/api/register', ['email' => 'new@example.test', 'username' => 'newbie', 'password' => 'Secret-123!', 'password_confirmation' => 'Secret-123!'])
            ->assertStatus(503);
        $this->postJson('/api/login', ['email' => 'victim@example.test', 'password' => 'wrong-password'])
            ->assertStatus(503);

        Notification::assertNothingSent();
        $this->assertSame(0, User::where('email', 'new@example.test')->count());

        // The global `api` limiter fails open first (logged as 'open'); the
        // route's own limiter is the one that closes the request.
        $closed = array_values(array_filter($this->storeFailures(), fn ($r) => $r->context['policy'] === 'closed'));
        $this->assertCount(1, $closed);
        $this->assertContains($closed[0]->context['limiter'], ['forgot-password', 'register', 'login']);
    }

    public function test_fallback_that_fails_too_falls_through_to_the_policy(): void
    {
        $this->skipIfRoot();
        // A second file store on a directory nobody can write.
        $dead = sys_get_temp_dir() . '/bp-dead-cache-' . uniqid();
        mkdir($dead, 0555);
        config(['cache.stores.dead' => ['driver' => 'file', 'path' => $dead], 'ballspot.rate_limiter.fallback_store' => 'dead']);
        $this->beforeApplicationDestroyed(fn () => @rmdir($dead));

        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->pack();
        chmod($this->cacheDir, 0555);

        $this->withToken($token)->postJson('/api/packs/beginners-football/start')->assertOk();

        $policies = array_map(fn ($r) => $r->context['policy'], $this->storeFailures());
        $this->assertSame(['fallback:dead', 'open'], $policies);
    }

    public function test_fallback_is_skipped_when_it_is_the_primary_store(): void
    {
        $this->skipIfRoot();
        config(['ballspot.rate_limiter.fallback_store' => 'file']);

        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->pack();
        $this->lockLimiterKeyDirs('gameplay', 'play|' . $user->id);

        $this->withToken($token)->postJson('/api/packs/beginners-football/start')->assertOk();

        $policies = array_map(fn ($r) => $r->context['policy'], $this->storeFailures());
        $this->assertSame(['open'], $policies);
    }

    public function test_fail_open_can_be_switched_off(): void
    {
        $this->skipIfRoot();
        $this->withoutFallback();
        config(['ballspot.rate_limiter.fail_open' => false]);

        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->pack();
        $this->lockLimiterKeyDirs('gameplay', 'play|' . $user->id);

        $this->withToken($token)->getJson('/api/packs/beginners-football')->assertOk();
        $this->withToken($token)->postJson('/api/packs/beginners-football/start')->assertStatus(500);
        $this->assertCount(0, $this->storeFailures());

        // Another player's key hashes elsewhere and is unaffected.
        $this->actingWithToken(User::factory()->create()->createToken('t')->plainTextToken)
            ->postJson('/api/packs/beginners-football/start')->assertOk();
    }

    public function test_limiter_remembers_the_named_limiter_of_the_route(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->pack();

        $this->withToken($token)->postJson('/api/packs/beginners-football/start')->assertOk();

        /** @var ResilientRateLimiter $limiter */
        $limiter = app(RateLimiter::class);
        $this->assertSame('gameplay', $limiter->currentLimiter());
        $this->assertFalse($limiter->usingFallback());
    }

    // ------------------------------------------------------------------
    // Detection: /admin/diagnostics
    // ------------------------------------------------------------------

    public function test_diagnostics_reports_a_healthy_file_cache(): void
    {
        $d = app(DiagnosticsService::class)->snapshot();

        $this->assertSame('file', $d['cache']['limiter_driver']);
        $this->assertTrue($d['cache']['probe_ok']);
        $this->assertTrue($d['cache']['file_root_writable']);
        $this->assertSame(0, $d['cache']['unwritable_count']);
        $this->assertTrue($d['health']['checks']['cache']);
        $this->assertEmpty(array_filter($d['warnings'], fn ($w) => $w['section'] === 'cache'));
    }

    public function test_diagnostics_flags_file_cache_entries_the_web_user_cannot_write(): void
    {
        $this->skipIfRoot();

        $this->lockLimiterKeyDirs('gameplay', 'play|42');

        $d = app(DiagnosticsService::class)->snapshot();

        $this->assertGreaterThanOrEqual(1, $d['cache']['unwritable_count']);
        // Relative to the cache root — never the absolute server path.
        $this->assertMatchesRegularExpression('#^[0-9a-f]{2}/[0-9a-f]{2}$#', $d['cache']['unwritable_samples'][0]);
        $this->assertFalse($d['health']['checks']['cache']);
        $cacheWarnings = array_values(array_filter($d['warnings'], fn ($w) => $w['section'] === 'cache'));
        $this->assertCount(1, $cacheWarnings);
        $this->assertSame('danger', $cacheWarnings[0]['level']);
        $this->assertStringContainsString('chown -R www-data:www-data storage/framework/cache', $cacheWarnings[0]['message']);
    }

    public function test_diagnostics_flags_a_cache_store_that_cannot_be_written_at_all(): void
    {
        $this->skipIfRoot();

        chmod($this->cacheDir, 0555);

        $d = app(DiagnosticsService::class)->snapshot();

        $this->assertFalse($d['cache']['probe_ok']);
        $this->assertFalse($d['cache']['file_root_writable']);
        $this->assertNotNull($d['cache']['probe_error']);
        $this->assertStringNotContainsString(base_path(), (string) $d['cache']['probe_error']);
        $this->assertFalse($d['health']['checks']['cache']);
    }

    public function test_diagnostics_flags_an_unwritable_laravel_log(): void
    {
        $this->skipIfRoot();

        $storage = sys_get_temp_dir() . '/bp-storage-' . uniqid();
        mkdir($storage . '/logs', 0777, true);
        mkdir($storage . '/framework', 0777, true);
        file_put_contents($storage . '/logs/laravel.log', '');
        chmod($storage . '/logs/laravel.log', 0444);
        $original = storage_path();
        $this->app->useStoragePath($storage);

        try {
            $d = app(DiagnosticsService::class)->snapshot();
        } finally {
            $this->app->useStoragePath($original);
            exec('chmod -R u+rwx ' . escapeshellarg($storage) . ' && rm -rf ' . escapeshellarg($storage));
        }

        $this->assertFalse($d['log']['writable']);
        $this->assertNotEmpty(array_filter(
            $d['warnings'],
            fn ($w) => $w['section'] === 'log' && str_contains($w['message'], 'laravel.log is not writable'),
        ));
    }

    public function test_diagnostics_page_renders_the_cache_card(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/diagnostics')
            ->assertOk()
            ->assertSee('data-section="cache"', false)
            ->assertSee('Rate-limiter store')
            ->assertSee('laravel.log writable by web user');
    }
}
