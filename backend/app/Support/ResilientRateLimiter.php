<?php

namespace App\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The application's rate limiter, surviving a broken cache store without
 * either 500ing every throttled route or switching protection off.
 *
 * Production incident (2026-10-15): CACHE_STORE=file and the scheduler cron
 * ran as root. Every withoutOverlapping() task takes a cache lock, so root
 * created hashed directories under storage/framework/cache/data that php-fpm
 * (www-data) could not write into. The throttle middleware runs before every
 * controller, so a player whose limiter key hashed into one of those
 * directories got a 500 on Start Pack and on every guess — "fopen(...cache/
 * data/14/e0/...): No such file or directory" — while the pack list, on a
 * different key, still loaded.
 *
 * Two stages when the store throws, both logged as `rate_limiter.store_failed`
 * (error level, once per stage per request, counted on /admin/diagnostics):
 *
 *  1. FALLBACK STORE — switch this request to ballspot.rate_limiter.fallback_store
 *     (default `database`: the `cache` table, same migration as `cache_locks`)
 *     and retry. Every limit stays enforced; an attacker gains at most one
 *     fresh window. Covers the realistic outage (file permissions, full disk)
 *     while the database — which every request needs anyway — is up.
 *
 *  2. POLICY, only when the fallback is unavailable or fails too:
 *     - limiters in ballspot.rate_limiter.fail_closed (registration,
 *       verification/2FA/reset mail, code and password checks, admin login,
 *       account deletion, export) answer 503 "temporarily unavailable". Email
 *       abuse never becomes unlimited; these flows need the database anyway.
 *     - everything else (gameplay, reads, social, uploads) FAILS OPEN: the
 *       request goes through unthrottled. The database-backed gates still
 *       hold (per-attempt XP dedupe, tournament/pack caps, per-code attempt
 *       locks). BALLPICKER_RATE_LIMIT_FAIL_OPEN=false restores failing closed
 *       for these too (the original 500).
 *
 * Which policy applies is known from the named limiter the throttle
 * middleware resolved for the route (`limiter()` below) — so AuthAbuseGuard
 * and FailedLoginLimiter, which run inside those requests on this same
 * singleton, inherit the route's policy. Bound in AppServiceProvider::register().
 */
class ResilientRateLimiter extends RateLimiter
{
    /** Named limiter of the current request (set by the throttle middleware via limiter()). */
    private ?string $currentLimiter = null;

    private bool $usingFallback = false;
    private bool $fallbackFailed = false;

    /** Stages already logged in this request/process, so a broken store cannot flood the log. */
    private static array $reported = [];

    /** Test hook. */
    public static function resetReporting(): void
    {
        self::$reported = [];
    }

    /** The limiter the current request runs under, if any (exposed for tests/diagnostics). */
    public function currentLimiter(): ?string
    {
        return $this->currentLimiter;
    }

    public function usingFallback(): bool
    {
        return $this->usingFallback;
    }

    public function limiter($name)
    {
        $this->currentLimiter = is_string($name) ? $name : null;

        return parent::limiter($name);
    }

    public function tooManyAttempts($key, $maxAttempts)
    {
        return $this->guard(fn () => parent::tooManyAttempts($key, $maxAttempts), false, __FUNCTION__);
    }

    public function hit($key, $decaySeconds = 60)
    {
        return $this->guard(fn () => parent::hit($key, $decaySeconds), 1, __FUNCTION__);
    }

    public function increment($key, $decaySeconds = 60, $amount = 1)
    {
        return $this->guard(fn () => parent::increment($key, $decaySeconds, $amount), $amount, __FUNCTION__);
    }

    public function decrement($key, $decaySeconds = 60, $amount = 1)
    {
        return $this->guard(fn () => parent::decrement($key, $decaySeconds, $amount), 0, __FUNCTION__);
    }

    public function attempts($key)
    {
        return $this->guard(fn () => parent::attempts($key), 0, __FUNCTION__);
    }

    public function resetAttempts($key)
    {
        return $this->guard(fn () => parent::resetAttempts($key), false, __FUNCTION__);
    }

    public function remaining($key, $maxAttempts)
    {
        return $this->guard(fn () => parent::remaining($key, $maxAttempts), $maxAttempts, __FUNCTION__);
    }

    public function clear($key)
    {
        $this->guard(function () use ($key) {
            parent::clear($key);

            return null;
        }, null, __FUNCTION__);
    }

    public function availableIn($key)
    {
        return $this->guard(fn () => parent::availableIn($key), 0, __FUNCTION__);
    }

    // ------------------------------------------------------------------

    private function guard(\Closure $call, mixed $openValue, string $method): mixed
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            if ($this->usingFallback) {
                $this->fallbackFailed = true;
            } elseif ($this->switchToFallback($e, $method)) {
                try {
                    return $call();
                } catch (\Throwable $e2) {
                    $this->fallbackFailed = true;
                    $e = $e2;
                }
            }

            if ($this->failsClosed()) {
                $this->report($e, $method, 'closed');
                throw new ServiceUnavailableHttpException(60, 'Temporarily unavailable. Please try again in a moment.', $e);
            }

            if (!config('ballspot.rate_limiter.fail_open', true)) {
                throw $e;
            }

            $this->report($e, $method, 'open');

            return $openValue;
        }
    }

    /** Point this request at the fallback store. False when none is configured, it is the primary, or it cannot be resolved. */
    private function switchToFallback(\Throwable $e, string $method): bool
    {
        if ($this->fallbackFailed) {
            return false;
        }

        $name    = config('ballspot.rate_limiter.fallback_store');
        $primary = (string) (config('cache.limiter') ?: config('cache.default'));
        if (!is_string($name) || $name === '' || $name === $primary) {
            return false;
        }

        try {
            $this->cache        = Cache::store($name);
            $this->usingFallback = true;
        } catch (\Throwable) {
            $this->fallbackFailed = true;

            return false;
        }

        $this->report($e, $method, 'fallback:' . $name);

        return true;
    }

    private function failsClosed(): bool
    {
        return $this->currentLimiter !== null
            && in_array($this->currentLimiter, (array) config('ballspot.rate_limiter.fail_closed', []), true);
    }

    private function report(\Throwable $e, string $method, string $policy): void
    {
        if (isset(self::$reported[$policy])) {
            return;
        }
        self::$reported[$policy] = true;

        AppLog::error('rate_limiter.store_failed', [
            'store'     => $this->usingFallback && !str_starts_with($policy, 'fallback')
                ? (string) config('ballspot.rate_limiter.fallback_store')
                : (string) (config('cache.limiter') ?: config('cache.default')),
            'limiter'   => $this->currentLimiter,
            'policy'    => $policy,
            'method'    => $method,
            'exception' => class_basename($e),
            // Paths are made relative so the log never carries the install layout.
            'error'     => substr(str_replace(rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, '', $e->getMessage()), 0, 300),
        ]);
    }
}
