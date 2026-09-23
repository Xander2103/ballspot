<?php

namespace Tests\Feature;

use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Production incident (2026-09-23): with `config:cache` Laravel never loads
 * .env, so env('APP_ENV') is null at runtime while config('app.env') is
 * "production". The readiness command read env() and reported
 * `APP_ENV is "" — set to "production"` on a correctly configured server.
 * The same trap hit TRUSTED_PROXIES in bootstrap/app.php.
 *
 * These tests run with the env still saying "testing" and drive the values
 * through config only — exactly the cached-config situation.
 */
class ReadinessCheckConfigSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_command_reads_app_env_debug_and_url_from_config_not_env(): void
    {
        // Simulate a cached-config production box: env() knows nothing, config does.
        $this->assertNotSame('production', env('APP_ENV'));
        config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://ballpicker.vanmalderstudio.be']);

        $this->artisan('ballspot:store-readiness-check')
            ->expectsOutputToContain('PASS  APP_ENV=production')
            ->expectsOutputToContain('PASS  APP_DEBUG=false')
            ->expectsOutputToContain('PASS  APP_URL=https://ballpicker.vanmalderstudio.be')
            ->doesntExpectOutputToContain('APP_ENV is ""')
            ->assertExitCode(0);
    }

    public function test_readiness_command_still_warns_on_unsafe_config_values(): void
    {
        config(['app.env' => 'local', 'app.debug' => true, 'app.url' => 'http://127.0.0.1:8000']);

        $this->artisan('ballspot:store-readiness-check')
            ->expectsOutputToContain('APP_ENV is "local"')
            ->expectsOutputToContain('APP_DEBUG is true')
            ->expectsOutputToContain('APP_URL is "http://127.0.0.1:8000"')
            ->assertExitCode(0);
    }

    public function test_trusted_proxies_readiness_line_reads_config(): void
    {
        config(['app.env' => 'production', 'ballspot.trusted_proxies' => '']);
        $this->artisan('ballspot:store-readiness-check')->expectsOutputToContain('TRUSTED_PROXIES is empty');

        config(['ballspot.trusted_proxies' => '10.0.0.1, 10.0.0.2']);
        $this->artisan('ballspot:store-readiness-check')->expectsOutputToContain('TRUSTED_PROXIES is set');
    }

    public function test_readiness_command_and_bootstrap_never_call_env(): void
    {
        foreach (['app/Console/Commands/StoreReadinessCheck.php', 'bootstrap/app.php', 'app/Support/TrustedProxies.php'] as $rel) {
            $source = file_get_contents(base_path($rel));
            $this->assertDoesNotMatchRegularExpression('/\benv\s*\(/', $source, "$rel must read config(), not env()");
        }
    }

    public function test_trusted_proxies_from_config_are_honoured_by_the_middleware(): void
    {
        config(['ballspot.trusted_proxies' => '10.0.0.1, 192.168.0.0/16']);
        TrustedProxies::apply();
        $this->assertSame(['10.0.0.1', '192.168.0.0/16'], TrustedProxies::fromConfig());

        $viaProxy = Request::create('/api/health', 'GET', [], [], [], [
            'REMOTE_ADDR'          => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]);
        $seen = null;
        app(TrustProxies::class)->handle($viaProxy, function (Request $r) use (&$seen) { $seen = $r->ip(); return response('ok'); });
        $this->assertSame('203.0.113.9', $seen, 'a trusted proxy must yield the real client IP');

        // A spoofed header from an untrusted address is ignored.
        config(['ballspot.trusted_proxies' => '']);
        TrustedProxies::apply();
        $direct = Request::create('/api/health', 'GET', [], [], [], [
            'REMOTE_ADDR'          => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]);
        app(TrustProxies::class)->handle($direct, function (Request $r) use (&$seen) { $seen = $r->ip(); return response('ok'); });
        $this->assertSame('198.51.100.7', $seen, 'an untrusted address must not be able to spoof its IP');
    }
}
