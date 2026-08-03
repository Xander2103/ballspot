<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rate limiter hits live in the (array) cache, which persists across
        // tests within one PHPUnit process. Flush so per-IP limits (register,
        // forgot-password, ...) never leak between tests — each test starts
        // with a clean limiter window.
        Cache::flush();
    }

    /**
     * Act as the owner of `$token` for the next request.
     *
     * A test shares one application instance across all its requests, so
     * Sanctum's RequestGuard keeps the user it resolved for the *first* request
     * and reuses it for every later one — a plain second `withToken()` in the
     * same test is silently ignored and acts as the first user. Production
     * never does this (each HTTP request gets a fresh container).
     *
     * Use this instead of `withToken()` in any test that authenticates as more
     * than one user, or the assertions pass for the wrong reason. Deliberately
     * a helper rather than a `call()` override: forgetting guards globally would
     * also wipe the session/`actingAs()` user the admin and league tests rely on.
     */
    protected function actingWithToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
