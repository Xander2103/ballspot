<?php

namespace App\Console\Commands;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ONE-TIME PRE-LAUNCH RESET of Daily Challenge history.
 *
 * During testing many challenges were scheduled as Daily. Any row in
 * daily_challenges marks a challenge as permanently "Used as Daily" and
 * blocks it from tournaments. Before the first public launch we want to wipe
 * that test history so the photos become reusable.
 *
 * Deletes ONLY:
 *   - daily_challenge_guesses (all rows — they belong to the daily rows below)
 *   - daily_challenges (all rows)
 *
 * Never touches challenges, images, usage_pool, tournament rounds/guesses,
 * users, badges or packs.
 *
 * Dry-run by default. A real delete requires BOTH --force and
 * --confirm-prelaunch. NEVER run casually after public launch: it erases real
 * players' daily scores and streak history.
 *
 *   php artisan ballspot:reset-test-daily-history
 *   php artisan ballspot:reset-test-daily-history --force --confirm-prelaunch
 */
class ResetTestDailyHistory extends Command
{
    protected $signature = 'ballspot:reset-test-daily-history
        {--force : Actually delete (default is dry-run)}
        {--confirm-prelaunch : Explicit acknowledgement that this is a pre-launch test reset}';

    protected $description = 'PRE-LAUNCH ONLY: wipe test Daily Challenge history (daily_challenges + guesses) so challenges are no longer "Used as Daily"';

    public function handle(): int
    {
        $force   = (bool) $this->option('force');
        $confirm = (bool) $this->option('confirm-prelaunch');

        $dailyCount = DailyChallenge::count();
        $guessCount = DailyChallengeGuess::count();
        $affected   = Challenge::dailyUsed()->orderBy('id')->get(['id', 'title', 'usage_pool']);

        $this->warn('ballspot:reset-test-daily-history — PRE-LAUNCH TEST RESET ONLY');
        $this->line("daily_challenges rows: {$dailyCount}");
        $this->line("daily_challenge_guesses rows: {$guessCount}");
        $this->line('Affected challenges: ' . $affected->count());

        if ($affected->isNotEmpty()) {
            $this->table(
                ['ID', 'Title', 'usage_pool (unchanged)'],
                $affected->map(fn ($c) => [$c->id, $c->title, $c->usage_pool])->all(),
            );
        }
        $this->line('Untouched: challenges, images, usage_pool, tournament rounds/guesses, users, badges, packs.');
        $this->newLine();

        if ($dailyCount === 0 && $guessCount === 0) {
            $this->info('Nothing to delete.');
            return self::SUCCESS;
        }

        if (!$force && !$confirm) {
            $this->warn('DRY RUN — nothing deleted. Re-run with --force --confirm-prelaunch to delete.');
            \App\Support\AppLog::event('daily.history_reset_dry_run', [
                'daily_challenges'        => $dailyCount,
                'daily_challenge_guesses' => $guessCount,
                'affected_challenges'     => $affected->count(),
            ]);
            return self::SUCCESS;
        }

        if (!$confirm) {
            $this->error('Refusing: --force also requires --confirm-prelaunch (this must only run before public launch).');
            return self::FAILURE;
        }

        if (!$force) {
            $this->error('Refusing: --confirm-prelaunch also requires --force to actually delete. (Nothing deleted.)');
            return self::FAILURE;
        }

        [$deletedGuesses, $deletedDailies] = DB::transaction(fn () => [
            DailyChallengeGuess::query()->delete(),
            DailyChallenge::query()->delete(),
        ]);

        $this->info("Deleted {$deletedGuesses} daily_challenge_guesses rows.");
        $this->info("Deleted {$deletedDailies} daily_challenges rows.");
        \App\Support\AppLog::warn('daily.history_reset', [
            'deleted_daily_challenges'        => $deletedDailies,
            'deleted_daily_challenge_guesses' => $deletedGuesses,
            'affected_challenges'             => $affected->count(),
        ]);
        $this->info('Daily history reset. Challenges are no longer "Used as Daily"; usage_pool values unchanged.');

        return self::SUCCESS;
    }
}
