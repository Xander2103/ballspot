<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Anti-bot / email-abuse ceilings for registration and the auth email flows
 * (v1.9.13). Complements the per-request `throttle:` middleware limiters in
 * AppServiceProvider:
 *
 *  - the middleware counts REQUESTS per IP / email (bursts, scripts);
 *  - this class counts OUTCOMES: successful registrations per IP and emails
 *    actually handed to the mailer per address, so a user who mistypes a
 *    field three times is not punished, while a bot that creates account
 *    after account — or makes us send email after email to one mailbox and
 *    burn the Brevo quota — is.
 *
 * Every refusal answers the shared AuthError shape with a stable `code`, a
 * `retry_after` and a Retry-After header, and logs a category-only event
 * (never the email, never a code or token). Limits come from
 * config('ballspot.abuse') so they can be tuned per environment.
 */
final class AuthAbuseGuard
{
    public const SCOPE_VERIFICATION = 'verification_email';
    public const SCOPE_LOGIN_2FA    = 'login_2fa';

    public const HOUR = 3600;
    public const DAY  = 86400;

    // ------------------------------------------------------------------
    // Emails sent per address (verification codes, login codes)
    // ------------------------------------------------------------------

    /**
     * Seconds until another email may be sent to this address for the scope,
     * or null when sending is allowed right now.
     */
    public static function emailSendBlocked(string $scope, string $email): ?int
    {
        $limits = self::emailLimits($scope);
        $key    = self::emailKey($scope, $email);

        if (RateLimiter::tooManyAttempts($key . '|h', $limits['per_email_hour'])) {
            return max(1, RateLimiter::availableIn($key . '|h'));
        }
        if (RateLimiter::tooManyAttempts($key . '|d', $limits['per_email_day'])) {
            return max(1, RateLimiter::availableIn($key . '|d'));
        }

        return null;
    }

    /** Count one email that was actually handed to the mail transport. */
    public static function recordEmailSend(string $scope, string $email): void
    {
        $key = self::emailKey($scope, $email);
        RateLimiter::hit($key . '|h', self::HOUR);
        RateLimiter::hit($key . '|d', self::DAY);
    }

    /** Emails counted for this address in the current hour (tests / diagnostics). */
    public static function emailSendsThisHour(string $scope, string $email): int
    {
        return (int) RateLimiter::attempts(self::emailKey($scope, $email) . '|h');
    }

    // ------------------------------------------------------------------
    // Successful registrations per IP + honeypot block
    // ------------------------------------------------------------------

    /** Seconds until this IP may create another account, or null when allowed. */
    public static function registrationBlocked(?string $ip): ?int
    {
        $ip     = self::ipKey($ip);
        $limits = (array) config('ballspot.abuse.register', []);

        if (RateLimiter::tooManyAttempts('abuse|honeypot|' . $ip, 1)) {
            return max(1, RateLimiter::availableIn('abuse|honeypot|' . $ip));
        }
        if (RateLimiter::tooManyAttempts('abuse|register|ip-h|' . $ip, (int) ($limits['per_ip_hour'] ?? 5))) {
            return max(1, RateLimiter::availableIn('abuse|register|ip-h|' . $ip));
        }
        if (RateLimiter::tooManyAttempts('abuse|register|ip-d|' . $ip, (int) ($limits['per_ip_day'] ?? 10))) {
            return max(1, RateLimiter::availableIn('abuse|register|ip-d|' . $ip));
        }

        return null;
    }

    /** Refuse with 429 `registration_rate_limited` when the IP's account budget is spent. */
    public static function assertRegistrationAllowed(Request $request): void
    {
        $retry = self::registrationBlocked($request->ip());
        if ($retry === null) {
            return;
        }

        self::throwRateLimited(AuthError::REGISTRATION_RATE_LIMITED, $retry, 'register.rate_limited', ['scope' => 'ip_accounts']);
    }

    /** Count one account actually created from this IP. */
    public static function recordRegistration(?string $ip): void
    {
        $ip = self::ipKey($ip);
        RateLimiter::hit('abuse|register|ip-h|' . $ip, self::HOUR);
        RateLimiter::hit('abuse|register|ip-d|' . $ip, self::DAY);
    }

    /**
     * A bot filled the hidden honeypot field: block further registrations
     * from that IP for an hour. The request itself is answered as a plain
     * validation failure (RegisterRequest) so the bot learns nothing.
     */
    public static function recordHoneypot(?string $ip): void
    {
        RateLimiter::hit('abuse|honeypot|' . self::ipKey($ip), self::HOUR);
        AppLog::warn('honeypot.triggered', ['field' => 'website', 'channel' => 'register']);
    }

    // ------------------------------------------------------------------
    // 429 helpers
    // ------------------------------------------------------------------

    /** Log a category-only abuse event and answer 429 with a stable code. */
    public static function throwRateLimited(string $code, int $retryAfter, string $event, array $context = []): never
    {
        AppLog::warn($event, $context + ['code' => $code, 'retry_after' => $retryAfter]);

        throw new HttpResponseException(
            AuthError::response($code, 429, null, ['retry_after' => $retryAfter])
                ->withHeaders(['Retry-After' => $retryAfter, 'X-RateLimit-Remaining' => 0]),
        );
    }

    /**
     * Stable code + abuse event for a middleware-throttled request, by route.
     * Used by the ThrottleRequestsException renderer so every auth 429 carries
     * the code the app maps to friendly copy. Unlisted routes get the generic
     * `rate_limited` code and no event (gameplay throttles are not abuse).
     *
     * @return array{code: string, event: ?string}
     */
    public static function throttleContext(Request $request): array
    {
        return match (true) {
            $request->is('api/register')                        => ['code' => AuthError::REGISTRATION_RATE_LIMITED,        'event' => 'register.rate_limited'],
            $request->is('api/email/verification-notification') => ['code' => AuthError::VERIFICATION_RESEND_RATE_LIMITED, 'event' => 'verification.resend_rate_limited'],
            $request->is('api/forgot-password'), $request->is('forgot-password')
                                                                => ['code' => AuthError::PASSWORD_RESET_RATE_LIMITED,      'event' => 'password_reset.rate_limited'],
            $request->is('api/login/resend-code')               => ['code' => AuthError::LOGIN_2FA_RATE_LIMITED,           'event' => 'login_2fa.rate_limited'],
            default                                             => ['code' => AuthError::RATE_LIMITED,                     'event' => null],
        };
    }

    // ------------------------------------------------------------------
    // Keys / config
    // ------------------------------------------------------------------

    private static function emailKey(string $scope, string $email): string
    {
        // Hashed: the address itself must never be a cache key an admin can read.
        return 'abuse|' . $scope . '|' . sha1(strtolower(trim($email)));
    }

    private static function ipKey(?string $ip): string
    {
        return $ip !== null && $ip !== '' ? $ip : 'unknown';
    }

    /** @return array{per_email_hour: int, per_email_day: int} */
    private static function emailLimits(string $scope): array
    {
        $cfg = (array) config('ballspot.abuse.' . $scope, []);

        return [
            'per_email_hour' => max(1, (int) ($cfg['per_email_hour'] ?? 3)),
            'per_email_day'  => max(1, (int) ($cfg['per_email_day'] ?? 6)),
        ];
    }
}
