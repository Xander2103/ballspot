<?php

namespace App\Support;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Applies TRUSTED_PROXIES from config once the configuration is loaded
 * (AppServiceProvider::boot). See config/ballspot.php 'trusted_proxies' for
 * why this cannot be an env() read in bootstrap/app.php.
 */
final class TrustedProxies
{
    /** @return list<string> */
    public static function fromConfig(): array
    {
        $raw = (string) config('ballspot.trusted_proxies', '');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public static function apply(): void
    {
        $proxies = self::fromConfig();

        // An empty list must trust NOTHING (not reset to a framework default).
        TrustProxies::at($proxies === [] ? [] : $proxies);
    }
}
