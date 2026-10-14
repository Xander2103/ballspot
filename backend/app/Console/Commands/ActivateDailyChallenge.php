<?php

namespace App\Console\Commands;

use App\Models\DailyChallenge;
use App\Support\AppLog;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Flips TODAY's daily challenge from `scheduled` to `active`.
 *
 * The 00:05 scheduler (ballspot:schedule-daily-challenges) creates rows as
 * `scheduled`; the API only serves `active`. Before this command an admin had
 * to open Admin → Daily every single day or players saw "No daily challenge".
 *
 * Gated by BALLPICKER_AUTO_ACTIVATE_DAILIES (config ballspot.daily.auto_activate,
 * default false) so the manual review workflow stays the default. With the
 * flag on, routes/console.php runs it at 00:10 — after the scheduler — and it
 * is idempotent, so any number of re-runs is harmless.
 *
 * It never touches future or past dates, never resurrects an archived row,
 * never activates a photo that is not ready, and never overwrites a status an
 * admin set by hand in the meantime (atomic `scheduled → active` claim).
 * Manual activation in Admin → Daily keeps working either way.
 */
class ActivateDailyChallenge extends Command
{
    protected $signature = 'ballspot:activate-daily
                            {--force : Run even when BALLPICKER_AUTO_ACTIVATE_DAILIES is false (manual use)}';

    protected $description = "Activate today's scheduled daily challenge (idempotent; gated by BALLPICKER_AUTO_ACTIVATE_DAILIES)";

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if (!$force && !config('ballspot.daily.auto_activate', false)) {
            $this->line('Automatic daily activation is OFF (BALLPICKER_AUTO_ACTIVATE_DAILIES=false) — nothing done. Use --force to run it by hand.');

            return self::SUCCESS;
        }

        // App timezone (UTC): the same "today" the API uses in /daily/today.
        $today = Carbon::today()->toDateString();
        $daily = DailyChallenge::whereDate('challenge_date', $today)->with('challenge')->first();

        if (!$daily) {
            $this->warn("No daily challenge row exists for today ({$today}) — nothing to activate. Run ballspot:schedule-daily-challenges or schedule one in Admin → Daily.");
            AppLog::warn('daily.activate_skipped', ['date' => $today, 'reason' => 'no_row']);

            return self::SUCCESS;
        }

        if ($daily->status === 'active') {
            $this->info("Today's daily (#{$daily->id}, {$today}) is already active — nothing to do.");

            return self::SUCCESS;
        }

        if ($daily->status !== 'scheduled') {
            // An archived row was pulled on purpose; never bring it back.
            $this->warn("Today's daily (#{$daily->id}) is '{$daily->status}', not 'scheduled' — left unchanged.");
            AppLog::warn('daily.activate_skipped', [
                'date'               => $today,
                'daily_challenge_id' => $daily->id,
                'reason'             => 'status_' . $daily->status,
            ]);

            return self::SUCCESS;
        }

        if (!$daily->challenge || !$daily->challenge->isReadyForDaily()) {
            $this->error("Today's daily (#{$daily->id}) points at a challenge that is not ready (not active, or missing image / ball position) — NOT activated. Fix it in Admin → Daily.");
            AppLog::error('daily.activate_failed', [
                'date'               => $today,
                'daily_challenge_id' => $daily->id,
                'challenge_id'       => $daily->challenge_id,
                'reason'             => 'challenge_not_ready',
            ]);

            return self::FAILURE;
        }

        // Atomic claim: only a row that is STILL `scheduled` flips, so a
        // parallel run or an admin changing the status at the same moment can
        // never be overwritten.
        $flipped = DailyChallenge::whereKey($daily->id)
            ->where('status', 'scheduled')
            ->update(['status' => 'active', 'updated_at' => now()]);

        if ($flipped === 0) {
            $this->info("Today's daily (#{$daily->id}) was changed by someone else meanwhile — nothing done.");

            return self::SUCCESS;
        }

        AppLog::event('daily.activated', [
            'date'               => $today,
            'daily_challenge_id' => $daily->id,
            'challenge_id'       => $daily->challenge_id,
            'trigger'            => $force ? 'manual' : 'scheduler',
        ]);
        $this->info("Activated today's daily (#{$daily->id}, challenge #{$daily->challenge_id}, {$today}).");

        return self::SUCCESS;
    }
}
