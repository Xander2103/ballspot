<?php

namespace App\Console\Commands;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use Carbon\Carbon;
use Illuminate\Console\Command;

class StoreReadinessCheck extends Command
{
    protected $signature = 'ballspot:store-readiness-check';

    protected $description = 'Print a store-readiness report. Read-only — never modifies data.';

    private int $passCount  = 0;
    private int $warnCount  = 0;
    private int $failCount  = 0;

    public function handle(): int
    {
        $this->info('BallSpot Store Readiness Check');
        $this->line(str_repeat('─', 50));
        $this->newLine();

        // Everything below reads CONFIG, never env(): after `config:cache`
        // Laravel does not load .env at all, so env('APP_ENV') is null on
        // production and the old checks reported APP_ENV as "" (false WARN).
        $appEnv = (string) config('app.env', '');
        $appEnv !== 'production'
            ? $this->warn_("APP_ENV is \"{$appEnv}\" — set to \"production\" before store release")
            : $this->pass('APP_ENV=production');

        (bool) config('app.debug', false)
            ? $this->warn_('APP_DEBUG is true — disable in production (APP_DEBUG=false)')
            : $this->pass('APP_DEBUG=false');

        $appUrl = (string) config('app.url', '');
        str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1') || $appUrl === ''
            ? $this->warn_("APP_URL is \"{$appUrl}\" — set to your production domain")
            : $this->pass("APP_URL={$appUrl}");

        $supportEmail = config('ballspot.support_email', '');
        if (!$supportEmail || $supportEmail === 'support@ballspot.app') {
            $this->warn_('BALLSPOT_SUPPORT_EMAIL not customised — set a real support email');
        } else {
            $this->pass("BALLSPOT_SUPPORT_EMAIL={$supportEmail}");
        }

        $webUrl = config('ballspot.web_url', '');
        if (str_contains($webUrl, 'localhost') || str_contains($webUrl, '127.0.0.1')) {
            $this->warn_("BALLSPOT_WEB_URL is \"{$webUrl}\" — set to your production web URL for legal page links in the app");
        } else {
            $this->pass("BALLSPOT_WEB_URL={$webUrl}");
        }

        // Security-relevant settings that .env.example deliberately leaves at
        // a local-friendly default. Only WARN when APP_ENV is production so the
        // check stays quiet on a dev box; on the server they are launch gates.
        $this->productionSetting(
            !in_array('*', (array) config('cors.allowed_origins', []), true),
            'CORS_ALLOWED_ORIGINS is restricted',
            'CORS_ALLOWED_ORIGINS is "*" — list the real web origins in production'
        );
        $this->productionSetting(
            (int) config('sanctum.expiration') > 0,
            'SANCTUM_TOKEN_EXPIRATION_MINUTES=' . (int) config('sanctum.expiration'),
            'SANCTUM_TOKEN_EXPIRATION_MINUTES is empty — API tokens would never expire'
        );
        $this->productionSetting(
            (bool) config('session.secure'),
            'SESSION_SECURE_COOKIE=true',
            'SESSION_SECURE_COOKIE is not true — the admin session cookie can travel over plain HTTP'
        );
        $this->productionSetting(
            trim((string) config('ballspot.trusted_proxies', '')) !== '',
            'TRUSTED_PROXIES is set',
            'TRUSTED_PROXIES is empty — behind a proxy every IP-keyed rate limit collapses into one bucket'
        );
        $this->productionSetting(
            !in_array('single', (array) config('logging.channels.stack.channels', []), true),
            'LOG_STACK rotates (' . implode(',', (array) config('logging.channels.stack.channels', [])) . ')',
            'LOG_STACK=single — laravel.log grows without bound; use LOG_STACK=daily'
        );
        $this->productionSetting(
            config('mail.default') !== 'log',
            'MAIL_MAILER=' . config('mail.default'),
            'MAIL_MAILER=log — verification codes and reset links would be written to laravel.log instead of sent'
        );

        // Active ready challenges
        $readyCount = Challenge::where('status', 'active')->get()->filter->isReadyForDaily()->count();
        if ($readyCount === 0) {
            $this->warn_('No active ready challenges — add at least one before release');
        } elseif ($readyCount < 7) {
            $this->warn_("{$readyCount} active ready challenge(s) — add more for a varied daily schedule");
        } else {
            $this->pass("{$readyCount} active ready challenges available");
        }

        // Demo content
        $demoCount = Challenge::where('status', 'active')->get()->filter->isDemoContent()->count();
        if ($demoCount > 0) {
            $this->warn_("{$demoCount} demo/placeholder challenge(s) active — replace with real content before public release");
        } else {
            $this->pass('No demo placeholder challenges in active pool');
        }

        // Daily challenge for today
        $today = Carbon::today()->toDateString();
        $hasTodayDaily = DailyChallenge::whereDate('challenge_date', $today)->exists();
        if (!$hasTodayDaily) {
            $this->warn_("No daily challenge scheduled for today ({$today}) — run ballspot:schedule-daily-challenges");
        } else {
            $this->pass("Daily challenge exists for today ({$today})");
        }

        // Upcoming daily challenges
        $upcoming = DailyChallenge::whereDate('challenge_date', '>', $today)
            ->whereDate('challenge_date', '<=', Carbon::today()->addDays(7)->toDateString())
            ->count();
        if ($upcoming < 3) {
            $this->warn_("Only {$upcoming} daily challenge(s) scheduled in the next 7 days — run schedule-daily-challenges");
        } else {
            $this->pass("{$upcoming} daily challenges scheduled in the next 7 days");
        }

        // Storage symlink
        $symlinkExists = file_exists(public_path('storage')) || is_link(public_path('storage'));
        if (!$symlinkExists) {
            $this->fail_('Storage symlink missing — run php artisan storage:link');
        } else {
            $this->pass('Storage symlink exists');
        }

        // Backups in .gitignore
        $gitignore = base_path('.gitignore');
        $ignored = file_exists($gitignore) && str_contains(file_get_contents($gitignore), 'backups/');
        if (!$ignored) {
            $this->warn_('backups/ may not be in .gitignore — add it to avoid committing content backups');
        } else {
            $this->pass('backups/ is in .gitignore');
        }

        // Public routes exist
        $routes = app('router')->getRoutes();
        foreach (['privacy', 'terms', 'support'] as $routeName) {
            try {
                $routes->getByName($routeName);
                $this->pass("/{$routeName} route registered");
            } catch (\Throwable) {
                $this->fail_("/{$routeName} route missing — add public legal pages");
            }
        }

        // Summary
        $this->newLine();
        $this->line(str_repeat('─', 50));
        $total = $this->passCount + $this->warnCount + $this->failCount;
        $this->line("  <fg=green>PASS</>  {$this->passCount}/{$total}    <fg=yellow>WARN</>  {$this->warnCount}    <fg=red>FAIL</>  {$this->failCount}");
        $this->newLine();

        if ($this->failCount > 0) {
            $this->error('Fix FAIL items before submitting to the store.');
            return self::FAILURE;
        }

        if ($this->warnCount > 0) {
            $this->warn('Review WARN items before public release. OK for internal testing.');
            return self::SUCCESS;
        }

        $this->info('All checks passed. Ready for store submission.');
        return self::SUCCESS;
    }

    /** PASS when ok; otherwise WARN in production and an informational PASS elsewhere. */
    private function productionSetting(bool $ok, string $passMsg, string $warnMsg): void
    {
        if ($ok) {
            $this->pass($passMsg);
        } elseif (config('app.env') === 'production') {
            $this->warn_($warnMsg);
        } else {
            $this->pass($passMsg . ' (not required outside production; would WARN in production)');
        }
    }

    private function pass(string $msg): void
    {
        $this->line("  <fg=green>PASS</>  {$msg}");
        $this->passCount++;
    }

    private function warn_(string $msg): void
    {
        $this->line("  <fg=yellow>WARN</>  {$msg}");
        $this->warnCount++;
    }

    private function fail_(string $msg): void
    {
        $this->line("  <fg=red>FAIL</>  {$msg}");
        $this->failCount++;
    }
}
