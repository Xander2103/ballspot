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

        $this->checkEnv('APP_ENV', fn($v) => $v !== 'production'
            ? $this->warn_("APP_ENV is \"{$v}\" — set to \"production\" before store release")
            : $this->pass("APP_ENV=production"));

        $this->checkEnv('APP_DEBUG', fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN)
            ? $this->warn_('APP_DEBUG is true — disable in production (APP_DEBUG=false)')
            : $this->pass('APP_DEBUG=false'));

        $this->checkEnv('APP_URL', fn($v) => str_contains($v, 'localhost') || str_contains($v, '127.0.0.1')
            ? $this->warn_("APP_URL is \"{$v}\" — set to your production domain")
            : $this->pass("APP_URL={$v}"));

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

    private function checkEnv(string $key, callable $fn): void
    {
        $fn(env($key, ''));
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
