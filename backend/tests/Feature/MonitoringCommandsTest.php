<?php

namespace Tests\Feature;

use App\Mail\OpsAlertMail;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ballspot:heartbeat, ballspot:health and ballspot:send-alert — the pieces
 * scripts/monitor-health.sh and scripts/deploy.sh rely on.
 */
class MonitoringCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function skipIfRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Permission bits do not restrict root.');
        }
    }

    // ---- heartbeat ------------------------------------------------------

    public function test_heartbeat_writes_when_and_as_whom_the_scheduler_ran(): void
    {
        $path = (string) config('ballspot.monitoring.heartbeat_file');
        @unlink($path);

        $this->artisan('ballspot:heartbeat')->assertExitCode(0);

        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data);
        $this->assertEqualsWithDelta(time(), $data['unix'], 5);
        if (function_exists('posix_geteuid')) {
            $this->assertSame(posix_geteuid(), $data['uid']);
            $this->assertSame(posix_getpwuid(posix_geteuid())['name'], $data['user']);
        }
    }

    /** A heartbeat left behind by root must be replaceable once the cron runs as the web user. */
    public function test_heartbeat_replaces_a_file_it_cannot_write_into(): void
    {
        $this->skipIfRoot();
        $path = $this->writeSchedulerHeartbeat(time() - 7200, 0, 'root');
        chmod($path, 0444);

        $this->artisan('ballspot:heartbeat')->assertExitCode(0);

        $data = json_decode((string) file_get_contents($path), true);
        $this->assertEqualsWithDelta(time(), $data['unix'], 5);
        $this->assertNotSame('root', $data['user']);
    }

    public function test_heartbeat_fails_cleanly_when_the_directory_is_not_writable(): void
    {
        $this->skipIfRoot();
        $dir = sys_get_temp_dir() . '/bp-hb-ro-' . uniqid();
        mkdir($dir, 0555);
        config(['ballspot.monitoring.heartbeat_file' => $dir . '/heartbeat.json']);

        try {
            $this->artisan('ballspot:heartbeat')
                ->expectsOutputToContain('Heartbeat not written')
                ->assertExitCode(1);
        } finally {
            chmod($dir, 0755);
            rmdir($dir);
        }
    }

    public function test_heartbeat_is_scheduled_every_five_minutes_without_a_cache_lock(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $e) => str_contains($e->command ?? '', 'ballspot:heartbeat'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('*/5 * * * *', $event->expression);
        // withoutOverlapping() would take a cache lock — the very store the heartbeat must outlive.
        $this->assertFalse($event->withoutOverlapping, 'heartbeat must not depend on the cache store');
    }

    // ---- ballspot:health ------------------------------------------------

    public function test_health_command_exits_zero_when_healthy(): void
    {
        $this->artisan('ballspot:health')
            ->expectsOutputToContain('Status: ok')
            ->assertExitCode(0);
    }

    public function test_health_command_exits_one_with_the_reason_when_degraded(): void
    {
        $this->writeSchedulerHeartbeat(time() - 3600);

        $this->artisan('ballspot:health')
            ->expectsOutputToContain('cron not running')
            ->expectsOutputToContain('Status: DEGRADED')
            ->assertExitCode(1);
    }

    public function test_health_command_json_output(): void
    {
        $this->artisan('ballspot:health', ['--json' => true])
            ->expectsOutputToContain('"status": "ok"')
            ->assertExitCode(0);
    }

    // ---- ballspot:send-alert --------------------------------------------

    public function test_send_alert_mails_the_configured_ops_address(): void
    {
        Mail::fake();
        config(['ballspot.monitoring.alert_email' => 'ops@example.test']);

        $this->artisan('ballspot:send-alert', ['--subject' => '[BallPicker] DOWN', '--body' => "health failing\nhttp=503"])
            ->expectsOutputToContain('Alert sent.')
            ->assertExitCode(0);

        Mail::assertSent(OpsAlertMail::class, function (OpsAlertMail $mail) {
            return $mail->hasTo('ops@example.test')
                && $mail->subjectLine === '[BallPicker] DOWN'
                && str_contains($mail->body, 'http=503');
        });
    }

    public function test_send_alert_recipient_can_be_overridden(): void
    {
        Mail::fake();

        $this->artisan('ballspot:send-alert', ['--subject' => 's', '--body' => 'b', '--to' => 'other@example.test'])
            ->assertExitCode(0);

        Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $m) => $m->hasTo('other@example.test'));
    }

    public function test_send_alert_defaults_to_the_studio_inbox(): void
    {
        Mail::fake();

        $this->artisan('ballspot:send-alert', ['--subject' => 's', '--body' => 'b'])->assertExitCode(0);

        Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $m) => $m->hasTo('info@vanmalderstudio.be'));
    }

    public function test_send_alert_rejects_missing_subject_body_or_recipient(): void
    {
        Mail::fake();

        $this->artisan('ballspot:send-alert', ['--body' => 'b'])->assertExitCode(2);
        $this->artisan('ballspot:send-alert', ['--subject' => 's'])->assertExitCode(2);
        $this->artisan('ballspot:send-alert', ['--subject' => 's', '--body' => 'b', '--to' => 'not-an-email'])->assertExitCode(2);

        Mail::assertNothingSent();
    }

    public function test_alert_mail_escapes_its_body(): void
    {
        $rendered = (new OpsAlertMail('s', '<script>alert(1)</script>'))->render();

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }
}
