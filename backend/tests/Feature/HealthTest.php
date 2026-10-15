<?php
namespace Tests\Feature;

use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public const CHECKS = ['database', 'storage', 'cache', 'queue', 'scheduler'];

    public function test_health_endpoint_returns_ok(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonFragment(['status' => 'ok'])
            ->assertJsonStructure(['status', 'app', 'timestamp', 'environment', 'checks' => self::CHECKS])
            ->assertJsonPath('app', 'BallPicker')
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.cache', true)
            ->assertJsonPath('checks.scheduler', true);
    }

    public function test_health_endpoint_is_public_and_needs_no_token(): void
    {
        $this->withHeaders(['Authorization' => ''])->getJson('/api/health')->assertOk();
    }

    public function test_health_endpoint_does_not_expose_secrets_or_operational_detail(): void
    {
        $betaCode = 'HEALTH-SECRET-BETA-CODE';
        config(['ballspot.beta_code' => $betaCode, 'mail.mailers.smtp.password' => 'health-mail-pw-77']);

        DB::table('failed_jobs')->insert([
            'uuid'       => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue'      => 'default',
            'payload'    => json_encode(['data' => 'HEALTH-JOB-PAYLOAD']),
            'exception'  => 'RuntimeException: HEALTH-JOB-EXCEPTION',
            'failed_at'  => now()->subDays(3), // old: reported on /admin/diagnostics, not a health failure
        ]);

        $response = $this->getJson('/api/health')->assertOk();
        $body = $response->getContent();
        $json = $response->json();

        foreach ([
            ltrim((string) config('app.key'), 'base64:'),
            $betaCode,
            'health-mail-pw-77',
            'HEALTH-JOB-PAYLOAD',
            'HEALTH-JOB-EXCEPTION',
            'APP_KEY', 'DB_PASSWORD', 'MAIL_PASSWORD', 'BALLPICKER_BETA_CODE',
            base_path(),
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "Health endpoint leaked: {$needle}");
        }

        // No counts, no reasons, no failed-job details, no versions — booleans only.
        $this->assertSame(['status', 'app', 'timestamp', 'environment', 'checks'], array_keys($json));
        $this->assertSame(self::CHECKS, array_keys($json['checks']));
        foreach ($json['checks'] as $value) {
            $this->assertIsBool($value);
        }
        $this->assertArrayNotHasKey('failed_jobs', $json);
        $this->assertArrayNotHasKey('version', $json);
    }

    public function test_health_endpoint_reports_degraded_when_database_is_unreachable(): void
    {
        DB::shouldReceive('connection')->andThrow(new \RuntimeException('down'));
        DB::shouldReceive('select')->andThrow(new \RuntimeException('down'));
        DB::shouldReceive('table')->andThrow(new \RuntimeException('down'));

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.database', false);
    }

    public function test_health_reports_degraded_when_the_cache_store_cannot_be_written(): void
    {
        $this->skipIfRoot();
        $dir = $this->useFileCache();
        chmod($dir, 0555);

        try {
            $this->getJson('/api/health')
                ->assertStatus(503)
                ->assertJsonPath('status', 'degraded')
                ->assertJsonPath('checks.cache', false)
                ->assertJsonPath('checks.database', true);
        } finally {
            chmod($dir, 0755);
        }
    }

    /**
     * The incident shape: ONE hashed cache directory the web user cannot
     * write. A single probe key would miss it; the bounded scan catches it.
     */
    public function test_health_reports_degraded_when_one_file_cache_directory_is_unwritable(): void
    {
        $this->skipIfRoot();
        $dir = $this->useFileCache();
        mkdir($dir . '/14/e0', 0777, true);
        chmod($dir . '/14/e0', 0555);

        try {
            $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.cache', false);
        } finally {
            chmod($dir . '/14/e0', 0755);
        }
    }

    /**
     * 2026-10-15: the throttle middleware in front of /api/health itself used
     * to turn a broken limiter store into a 500 — the monitor would then see
     * a crash instead of a diagnosis. Fail-open keeps the JSON answer.
     */
    public function test_health_endpoint_still_answers_json_when_the_rate_limiter_store_is_broken(): void
    {
        $this->skipIfRoot();
        $dir = $this->useFileCache();
        $limiter = app(RateLimiter::class);
        (new \ReflectionProperty($limiter, 'cache'))->setValue($limiter, Cache::store('file'));
        chmod($dir, 0555);

        try {
            $this->getJson('/api/health')
                ->assertStatus(503)
                ->assertJsonPath('status', 'degraded')
                ->assertJsonPath('checks.cache', false);
        } finally {
            chmod($dir, 0755);
        }
    }

    public function test_health_reports_degraded_when_the_scheduler_heartbeat_is_stale(): void
    {
        $this->writeSchedulerHeartbeat(time() - 3600);

        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.scheduler', false);
    }

    public function test_health_reports_degraded_when_the_scheduler_heartbeat_is_missing(): void
    {
        @unlink((string) config('ballspot.monitoring.heartbeat_file'));

        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.scheduler', false);
    }

    /** The root-cron misconfiguration behind the incident is itself a health failure. */
    public function test_health_reports_degraded_when_the_scheduler_ran_as_another_user(): void
    {
        if (!function_exists('posix_geteuid')) {
            $this->markTestSkipped('posix extension missing');
        }
        $this->writeSchedulerHeartbeat(time(), posix_geteuid() + 1, 'root');

        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.scheduler', false);
    }

    public function test_health_scheduler_user_check_honours_the_configured_web_user(): void
    {
        if (!function_exists('posix_getpwuid')) {
            $this->markTestSkipped('posix extension missing');
        }
        $me = posix_getpwuid(posix_geteuid())['name'];
        config(['ballspot.monitoring.web_user' => $me]);
        $this->writeSchedulerHeartbeat(time(), posix_geteuid(), $me);

        $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.scheduler', true);
    }

    public function test_health_reports_degraded_on_a_recent_failed_job(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
        ]);

        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.queue', false);
    }

    // ------------------------------------------------------------------

    private function useFileCache(): string
    {
        $dir = sys_get_temp_dir() . '/bp-health-cache-' . uniqid();
        mkdir($dir, 0755, true);
        config(['cache.default' => 'file', 'cache.stores.file.path' => $dir, 'cache.stores.file.lock_path' => $dir]);
        Cache::forgetDriver('file');
        $this->beforeApplicationDestroyed(function () use ($dir) {
            exec('chmod -R u+rwx ' . escapeshellarg($dir) . ' && rm -rf ' . escapeshellarg($dir));
        });

        return $dir;
    }

    private function skipIfRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Permission bits do not restrict root.');
        }
    }
}
