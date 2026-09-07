<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Structured operational logging for BallPicker.
 *
 * Every important gameplay / content / auth / push event goes through here so
 * the format is uniform and greppable: a short fixed message plus a context
 * array of IDs, counts, statuses and reason codes. Writes go to the `events`
 * channel (config/logging.php): a dedicated rotated file that keeps info-level
 * events even when production runs LOG_LEVEL=warning, stacked onto the normal
 * channels so warnings also land in laravel.log.
 *
 * Rules (enforced by tests in ObservabilityLoggingTest):
 *  - never log secrets: passwords, tokens, login/reset codes, beta codes,
 *    friend codes, raw Expo push tokens, APP_KEY, request payloads
 *  - prefer IDs / counts / reason codes over free text or emails
 *  - important events only — never once per page view
 */
final class AppLog
{
    public const CHANNEL = 'events';

    /** Keys that must never appear in a context array. Defense in depth. */
    private const FORBIDDEN_KEYS = [
        'password', 'password_confirmation', 'token', 'plain_text_token', 'code',
        'beta_code', 'login_code', 'reset_token', 'friend_code', 'push_token',
        'expo_token', 'app_key', 'secret', 'authorization', 'cookie', 'email',
    ];

    /** Something happened that is worth knowing about (normal operation). */
    public static function event(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /** Something went wrong or is degraded but the app carried on. */
    public static function warn(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /** A failure that needs a human. */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * Logging must never break the request that is being logged.
     *
     * Production incident (2026-09-07): the day's rotated events file was not
     * writable by the web user, Monolog threw from inside the logging call, and
     * every flow that logs an event (register — after the verification mail had
     * already gone out —, unverified login, failed login) answered 500 while the
     * one flow that logs nothing (verified login) kept working. The events
     * channel is a stack with ignore_exceptions=false so the failure also never
     * reached laravel.log.
     *
     * So: catch everything, then report the logging failure itself (exception
     * class + message, the event name and its sanitized context) on the default
     * channel so the event is not silently lost and the diagnostics page can
     * count it. If that channel is broken too, give up quietly.
     */
    private static function write(string $level, string $message, array $context): void
    {
        $clean = self::sanitize($context);

        try {
            Log::channel(self::CHANNEL)->log($level, $message, $clean);
        } catch (\Throwable $e) {
            try {
                Log::channel((string) config('logging.default'))->error('applog.write_failed', [
                    'event'     => $message,
                    'level'     => $level,
                    'exception' => class_basename($e),
                    'error'     => substr($e->getMessage(), 0, 300),
                    'context'   => $clean,
                ]);
            } catch (\Throwable) {
                // Nothing left to write to. The user's request still succeeds.
            }
        }
    }

    /**
     * Drop forbidden keys and truncate long strings so a careless caller can
     * never turn an event log into a secret dump or a payload dump.
     */
    public static function sanitize(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                continue;
            }
            if (is_array($value)) {
                $value = self::sanitize($value);
            } elseif (is_string($value) && strlen($value) > 200) {
                $value = substr($value, 0, 200) . '…';
            }
            $clean[$key] = $value;
        }

        return $clean;
    }
}
