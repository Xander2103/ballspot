<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureIsAdmin::class,
            // Overrides the framework alias so the gate follows
            // ballspot.auth.require_email_verification (see the class).
            'verified' => \App\Http\Middleware\EnsureEmailIsVerifiedIfRequired::class,
        ]);

        // Trust the reverse proxy / load balancer so $request->ip() reflects the
        // real client (not the proxy). Without this, every IP-keyed rate limiter
        // collapses into a single global bucket and $request->secure() misreads
        // the scheme behind TLS termination. Set TRUSTED_PROXIES to the proxy
        // IP(s)/CIDR(s); empty = trust none (safe local default).
        $middleware->trustProxies(
            at: array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Global fallback throttle for every API route (named limiter 'api'
        // in AppServiceProvider). Stricter route-level limiters stack on top.
        $middleware->throttleApi();

        // Baseline security headers on every response.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Clean, consistent 429 JSON for the app (never an HTML error page).
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

                return response()->json([
                    'message'     => "Too many requests. Please try again in {$retryAfter} seconds.",
                    'retry_after' => $retryAfter,
                ], 429, $e->getHeaders());
            }
        });
    })->create();
