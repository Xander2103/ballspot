<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonFragment(['status' => 'ok'])
            ->assertJsonStructure(['status', 'app', 'timestamp', 'environment', 'checks' => ['database', 'storage']])
            ->assertJsonPath('app', 'BallPicker')
            ->assertJsonPath('checks.database', true);
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
            'failed_at'  => now(),
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
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "Health endpoint leaked: {$needle}");
        }

        // No counts, no failed-job details, no versions — booleans only.
        $this->assertSame(['status', 'app', 'timestamp', 'environment', 'checks'], array_keys($json));
        $this->assertSame(['database', 'storage'], array_keys($json['checks']));
        $this->assertArrayNotHasKey('failed_jobs', $json);
        $this->assertArrayNotHasKey('version', $json);
    }

    public function test_health_endpoint_reports_degraded_when_database_is_unreachable(): void
    {
        DB::shouldReceive('connection')->andThrow(new \RuntimeException('down'));
        DB::shouldReceive('select')->andThrow(new \RuntimeException('down'));

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.database', false);
    }
}
