<?php

namespace Database\Seeders\Staging;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Pre-flight checks of ClubsStagingSeeder. Every condition must hold, or the
 * seeder refuses before it reads or writes anything else:
 *
 *  1. APP_ENV is `staging`;
 *  2. env BALLPICKER_STAGING_SEED_CONFIRM=yes;
 *  3. the default connection's database name (sqlite: the file path)
 *     contains `staging`;
 *  4. APP_URL is not the production host — a ballpicker.vanmalderstudio.be
 *     URL is accepted only as exactly staging.ballpicker.vanmalderstudio.be;
 *  5. the users table exists and every account in it uses the reserved QA
 *     domain (no copy of real data);
 *  6. env BALLPICKER_STAGING_SEED_PASSWORD is set and ≥ 12 characters.
 *
 * Messages never contain the password or other env values.
 */
final class StagingSeedGuard
{
    public const QA_DOMAIN       = 'staging.ballpicker.test';
    public const PRODUCTION_HOST = 'ballpicker.vanmalderstudio.be';
    public const STAGING_HOST    = 'staging.ballpicker.vanmalderstudio.be';
    public const MIN_PASSWORD    = 12;

    public const ENV_CONFIRM  = 'BALLPICKER_STAGING_SEED_CONFIRM';
    public const ENV_PASSWORD = 'BALLPICKER_STAGING_SEED_PASSWORD';
    public const ENV_DRY_RUN  = 'BALLPICKER_STAGING_SEED_DRY_RUN';

    /**
     * @param string $databaseName the default connection's database name / sqlite path
     * @return list<string> human-readable reasons; empty = all checks passed
     */
    public function failures(string $databaseName): array
    {
        $failures = [];

        if (!app()->environment('staging')) {
            $failures[] = 'APP_ENV must be "staging" (current: "' . app()->environment() . '").';
        }

        if (self::env(self::ENV_CONFIRM) !== 'yes') {
            $failures[] = self::ENV_CONFIRM . '=yes is required.';
        }

        if (!str_contains(strtolower($databaseName), 'staging')) {
            $failures[] = 'The default database name must contain "staging" (sqlite: the file path).';
        }

        if (!self::appUrlAllowed((string) config('app.url'))) {
            $failures[] = 'APP_URL points at the production host; only https://' . self::STAGING_HOST . ' is allowed for that domain.';
        }

        $password = self::env(self::ENV_PASSWORD);
        if ($password === null || mb_strlen($password) < self::MIN_PASSWORD) {
            $failures[] = self::ENV_PASSWORD . ' must be set to at least ' . self::MIN_PASSWORD . ' characters.';
        }

        // Last: the only check that queries the database.
        if (!Schema::hasTable('users')) {
            $failures[] = 'The users table does not exist — run the migrations on the staging database first.';
        } elseif (($foreign = $this->foreignAccountCount()) > 0) {
            $failures[] = "The users table holds {$foreign} account(s) outside @" . self::QA_DOMAIN . ' — this does not look like a staging database.';
        }

        return $failures;
    }

    public static function appUrlAllowed(string $url): bool
    {
        $url = strtolower(trim($url));
        if (!str_contains($url, self::PRODUCTION_HOST)) {
            return true;
        }

        $host = parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST);

        return $host === self::STAGING_HOST;
    }

    public static function isQaEmail(?string $email): bool
    {
        return $email !== null && str_ends_with(strtolower(trim($email)), '@' . self::QA_DOMAIN);
    }

    public static function dryRun(): bool
    {
        return self::env(self::ENV_DRY_RUN) === 'yes';
    }

    /** The QA password (only call after the guard passed). */
    public static function password(): string
    {
        return (string) self::env(self::ENV_PASSWORD);
    }

    private function foreignAccountCount(): int
    {
        return DB::table('users')
            ->where(fn ($q) => $q->whereNull('email')
                ->orWhereRaw('LOWER(email) NOT LIKE ?', ['%@' . self::QA_DOMAIN]))
            ->count();
    }

    private static function env(string $key): ?string
    {
        $value = env($key);

        return $value === null || $value === false ? null : (string) $value;
    }
}
