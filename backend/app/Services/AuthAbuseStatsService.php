<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

/**
 * Read-only last-24h counters for the registration / auth-email abuse
 * protection (v1.9.13), shared by /admin/diagnostics and /admin/competition.
 *
 * Everything comes from the rotated events log (App\Support\AppLog) and one
 * COUNT on users: event NAMES and LEVELS only — the JSON context is never
 * parsed into the page, so no address, code, token or password can surface
 * here even if a caller ever logged one by mistake.
 */
class AuthAbuseStatsService
{
    /** Abuse refusals, in display order. */
    public const ABUSE_EVENTS = [
        'register.rate_limited'            => 'Registration rate limits',
        'verification.resend_rate_limited' => 'Verification email rate limits',
        'password_reset.rate_limited'      => 'Password reset rate limits',
        'login_2fa.rate_limited'           => 'Login code (2FA) rate limits',
        'honeypot.triggered'               => 'Honeypot triggers',
        'register.captcha_failed'          => 'CAPTCHA failures (incl. missing token)',
    ];

    /** Mail-transport failures (category only). */
    private const MAIL_FAILURE_EVENTS = ['email_verification.send_failed', 'login.2fa_send_failed'];

    /** How much of each day's events file to scan (bytes). */
    private const LOG_TAIL_BYTES = 512 * 1024;

    public function last24h(?Carbon $now = null): array
    {
        $now   = $now ?? now();
        $since = $now->copy()->subDay();
        $scan  = $this->scan($now, $since);

        $byName  = $scan['by_name'];
        $count   = fn (string $name) => (int) ($byName[$name] ?? 0);
        $abuse   = [];
        foreach (self::ABUSE_EVENTS as $name => $label) {
            $abuse[] = ['event' => $name, 'label' => $label, 'count_24h' => $count($name)];
        }

        $mailFailures = array_sum(array_map($count, self::MAIL_FAILURE_EVENTS))
            // A reset email that could not be handed to the transport is logged
            // as password_reset.requested at ERROR level (outcome send_failed).
            + (int) ($scan['by_name_level']['password_reset.requested|error'] ?? 0);

        return [
            'window'                       => 'last 24h',
            'events_log_found'             => $scan['files_found'] > 0,
            'rows'                         => $abuse,
            'total_24h'                    => array_sum(array_column($abuse, 'count_24h')),
            'registrations_24h'            => User::where('created_at', '>=', $since)->count(),
            'verification_emails_sent_24h' => $count('email_verification.sent'),
            'password_reset_requests_24h'  => $count('password_reset.requested'),
            'mail_failures_24h'            => $mailFailures,
            'captcha_enabled'              => (bool) config('ballspot.captcha.enabled', false),
            'limits'                       => [
                'register_per_ip'        => sprintf('%d accounts/hour, %d/day per IP', (int) config('ballspot.abuse.register.per_ip_hour', 5), (int) config('ballspot.abuse.register.per_ip_day', 10)),
                'verification_per_email' => sprintf('%d emails/hour, %d/day per address', (int) config('ballspot.abuse.verification_email.per_email_hour', 5), (int) config('ballspot.abuse.verification_email.per_email_day', 10)),
                'login_2fa_per_email'    => sprintf('%d emails/hour, %d/day per address', (int) config('ballspot.abuse.login_2fa.per_email_hour', 3), (int) config('ballspot.abuse.login_2fa.per_email_day', 10)),
                'password_reset'         => sprintf('%d/hour per address, %d/hour per IP', (int) config('ballspot.abuse.password_reset.per_email_hour', 3), (int) config('ballspot.abuse.password_reset.per_ip_hour', 10)),
            ],
        ];
    }

    /**
     * One pass over today's and yesterday's events files: counts per event
     * name and per (name, level) for records newer than $since.
     *
     * @return array{by_name: array<string,int>, by_name_level: array<string,int>, files_found: int}
     */
    private function scan(Carbon $now, Carbon $since): array
    {
        $byName = [];
        $byNameLevel = [];
        $files = 0;

        foreach ([$now->copy()->subDay(), $now] as $day) {
            $path = storage_path('logs/ballpicker-events-' . $day->toDateString() . '.log');
            if (!is_file($path)) {
                continue;
            }
            $files++;
            foreach (preg_split('/\r?\n/', $this->tail($path)) as $line) {
                // "[2026-10-14 10:00:00] production.WARNING: register.rate_limited {...}"
                if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(\w+): ([a-z0-9_]+\.[a-z0-9_]+)\b/', $line, $m)) {
                    continue;
                }
                if (Carbon::parse($m[1])->lt($since)) {
                    continue;
                }
                $byName[$m[3]] = ($byName[$m[3]] ?? 0) + 1;
                $key = $m[3] . '|' . strtolower($m[2]);
                $byNameLevel[$key] = ($byNameLevel[$key] ?? 0) + 1;
            }
        }

        return ['by_name' => $byName, 'by_name_level' => $byNameLevel, 'files_found' => $files];
    }

    private function tail(string $path): string
    {
        $size = (int) filesize($path);
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return '';
        }
        if ($size > self::LOG_TAIL_BYTES) {
            fseek($fh, -self::LOG_TAIL_BYTES, SEEK_END);
        }
        $data = stream_get_contents($fh) ?: '';
        fclose($fh);

        return $data;
    }
}
