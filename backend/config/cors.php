<?php

$origins  = array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))));
$wildcard = in_array('*', $origins, true);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Locked-down version of the framework defaults: allowed origins come from
    | the CORS_ALLOWED_ORIGINS env var (comma-separated). The '*' default keeps
    | local development frictionless — production MUST set the real web/app
    | origins (see docs/security-hardening.md).
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // fruitcake/php-cors stamps a single literal allowed origin on EVERY
    // response, even when the request's Origin is disallowed or absent
    // (CorsService::isSingleOriginAllowed short-circuit). Mapping non-wildcard
    // origins to exact-match patterns keeps the library on its dynamic branch,
    // which only emits Access-Control-Allow-Origin on a real match and adds
    // Vary: Origin.
    'allowed_origins' => $wildcard ? ['*'] : [],

    'allowed_origins_patterns' => $wildcard ? [] : array_map(
        static fn (string $origin) => '#^' . preg_quote($origin, '#') . '$#i',
        $origins
    ),

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 0,

    'supports_credentials' => false,

];
