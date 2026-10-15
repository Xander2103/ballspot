<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\Sport;
use App\Models\User;
use App\Services\HealthCheckService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Everything the production switch to CACHE_STORE=database relies on, run
 * against the real DatabaseStore on the test database: the `cache` /
 * `cache_locks` tables from 0001_01_01_000001_create_cache_table, limiter
 * counters, scheduler withoutOverlapping() locks, the health probe, and the
 * expired-row prune. No player table is involved.
 */
class DatabaseCacheStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'database', 'cache.limiter' => 'database']);
        Cache::forgetDriver('database');

        $limiter = app(RateLimiter::class);
        (new \ReflectionProperty($limiter, 'cache'))->setValue($limiter, Cache::store('database'));
    }

    private function pack(): void
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $pack  = ChallengePack::create([
            'name' => 'P', 'slug' => 'p', 'status' => ChallengePack::STATUS_ACTIVE,
            'visibility' => ChallengePack::VISIBILITY_PUBLIC, 'sport_id' => $sport->id,
        ]);
        $c = Challenge::create([
            'sport_id' => $sport->id, 'title' => 'c', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active', 'hidden_image_path' => 'challenges/hidden/c.jpg',
        ]);
        $pack->challenges()->attach($c->id, ['sort_order' => 1]);
    }

    public function test_the_cache_migration_creates_both_tables_and_nothing_else_is_touched(): void
    {
        foreach (['cache', 'cache_locks'] as $table) {
            $this->assertTrue(\Schema::hasTable($table), "{$table} missing");
            $this->assertSame(['key', 'value', 'expiration'] === \Schema::getColumnListing($table) || $table === 'cache_locks', true);
        }
        $this->assertSame(['key', 'owner', 'expiration'], \Schema::getColumnListing('cache_locks'));

        $migration = file_get_contents(database_path('migrations/0001_01_01_000001_create_cache_table.php'));
        $this->assertStringNotContainsString('users', $migration);
        $this->assertSame(2, substr_count($migration, 'Schema::create('));
    }

    public function test_rate_limits_are_enforced_and_counted_in_the_cache_table(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->pack();

        $last = null;
        for ($i = 0; $i < 31; $i++) {
            $last = $this->withToken($token)->postJson('/api/packs/p/start');
        }

        $last->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->assertGreaterThan(0, DB::table('cache')->count(), 'counters must live in the cache table');
    }

    public function test_hits_on_the_same_key_never_lose_increments(): void
    {
        $limiter = app(RateLimiter::class);
        for ($i = 0; $i < 25; $i++) {
            $limiter->hit('db-atomic', 60);
        }

        $this->assertSame(25, $limiter->attempts('db-atomic'));
        // DatabaseStore::increment runs in a transaction with lockForUpdate
        // (vendor DatabaseStore::incrementOrDecrement) — a row lock on MySQL.
        $src = file_get_contents(base_path('vendor/laravel/framework/src/Illuminate/Cache/DatabaseStore.php'));
        $this->assertStringContainsString('lockForUpdate()', $src);
        $this->assertStringContainsString('->transaction(function', $src);
    }

    public function test_scheduler_without_overlapping_locks_work_on_the_database_store(): void
    {
        // The Schedule resolves CacheEventMutex on the default cache store
        // unless something binds EventMutex; nothing in this app does.
        $this->assertFalse($this->app->bound(EventMutex::class));
        $mutex = app(CacheEventMutex::class);

        $event = new Event($mutex, 'php artisan ballspot:cleanup-login-codes');
        $event->withoutOverlapping(10);

        $this->assertTrue($mutex->create($event), 'first run takes the lock');
        $this->assertTrue($mutex->exists($event));
        $this->assertFalse($mutex->create($event), 'an overlapping run is refused');
        $this->assertSame(1, DB::table('cache_locks')->count());

        $mutex->forget($event);
        $this->assertFalse($mutex->exists($event));
        $this->assertTrue($mutex->create($event), 'lock is reusable after release');
    }

    public function test_health_check_probes_the_database_store_and_skips_the_file_scan(): void
    {
        $c = app(HealthCheckService::class)->cache(100);

        $this->assertSame('database', $c['limiter_driver']);
        $this->assertTrue($c['probe_ok']);
        $this->assertTrue($c['ok']);
        $this->assertNull($c['file_path']);
        $this->assertSame(0, DB::table('cache')->where('key', 'like', '%health:probe%')->count(), 'probe key is deleted again');

        $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.cache', true);
    }

    public function test_cleanup_prunes_only_expired_cache_rows(): void
    {
        $store = Cache::store('database');
        $store->put('live-counter', 1, 600);
        DB::table('cache')->insert(['key' => 'laravel_cache_dead', 'value' => 'i:1;', 'expiration' => now()->subMinute()->timestamp]);
        DB::table('cache_locks')->insert(['key' => 'dead-lock', 'owner' => 'x', 'expiration' => now()->subMinute()->timestamp]);
        DB::table('cache_locks')->insert(['key' => 'live-lock', 'owner' => 'x', 'expiration' => now()->addMinutes(5)->timestamp]);

        $this->artisan('ballspot:cleanup-login-codes')
            ->expectsOutputToContain('2 expired cache row(s)')
            ->assertExitCode(0);

        $this->assertSame(1, (int) $store->get('live-counter'));
        $this->assertSame(0, DB::table('cache')->where('key', 'laravel_cache_dead')->count());
        $this->assertSame(['live-lock'], DB::table('cache_locks')->pluck('key')->all());
    }
}
