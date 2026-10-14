<?php

namespace App\Services;

use App\Support\AppLog;
use Illuminate\Support\Facades\Http;

/**
 * Feature-flagged CAPTCHA for registration (v1.9.13). DISABLED by default:
 * with BALLPICKER_CAPTCHA_ENABLED=false nothing here runs and /api/register
 * behaves exactly as before. Turn it on only if registration spam starts
 * (see docs/security-hardening.md → "If registration spam starts").
 *
 * Provider: Cloudflare Turnstile (server-side siteverify). The app sends the
 * widget token as `captcha_token`; we verify it here with the secret, never
 * trusting the client. Only the outcome category is logged — never the
 * token, never the secret.
 *
 * Fails CLOSED: when the verify call itself fails (timeout, 5xx) the
 * registration is refused with `registration_captcha_failed`. The flag only
 * gets switched on during an abuse wave, when letting bots through on a
 * provider hiccup is the worse failure. Disable the flag to recover.
 */
class CaptchaVerifier
{
    public const OK          = 'ok';
    public const MISSING     = 'missing';
    public const FAILED      = 'failed';
    public const UNAVAILABLE = 'unavailable';

    public const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function enabled(): bool
    {
        return (bool) config('ballspot.captcha.enabled', false);
    }

    public function provider(): string
    {
        return (string) config('ballspot.captcha.provider', 'turnstile');
    }

    /** Public widget key (safe to expose via /api/config); null when unset. */
    public function siteKey(): ?string
    {
        $key = trim((string) config('ballspot.captcha.site_key', ''));

        return $key === '' ? null : $key;
    }

    /** Verify a client token. Returns one of the outcome constants. */
    public function verify(?string $token, ?string $ip = null): string
    {
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            AppLog::warn('register.captcha_failed', ['reason' => self::MISSING, 'provider' => $this->provider()]);

            return self::MISSING;
        }

        $secret = (string) config('ballspot.captcha.secret', '');
        if ($secret === '') {
            // Enabled without a secret is a deployment mistake: refuse, loudly.
            AppLog::error('register.captcha_failed', ['reason' => 'misconfigured', 'provider' => $this->provider()]);

            return self::UNAVAILABLE;
        }

        try {
            $response = Http::timeout((int) config('ballspot.captcha.timeout_seconds', 5))
                ->asForm()
                ->post((string) config('ballspot.captcha.verify_url', self::TURNSTILE_VERIFY_URL), array_filter([
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (\Throwable $e) {
            AppLog::error('register.captcha_failed', ['reason' => self::UNAVAILABLE, 'exception' => class_basename($e)]);

            return self::UNAVAILABLE;
        }

        if (!$response->successful()) {
            AppLog::error('register.captcha_failed', ['reason' => self::UNAVAILABLE, 'status' => $response->status()]);

            return self::UNAVAILABLE;
        }

        if ($response->json('success') === true) {
            return self::OK;
        }

        AppLog::warn('register.captcha_failed', [
            'reason'      => self::FAILED,
            'provider'    => $this->provider(),
            // Turnstile error codes are generic category strings (e.g.
            // timeout-or-duplicate, invalid-input-response) — never the token.
            'error_codes' => array_slice(array_values(array_filter((array) $response->json('error-codes', []), 'is_string')), 0, 5),
        ]);

        return self::FAILED;
    }
}
