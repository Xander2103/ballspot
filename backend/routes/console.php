<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| Requires ONE cron entry on the server:
|   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
|
| The cleanup task is not optional housekeeping: the privacy policy promises
| that login/verification codes (which hold IP + user agent) are purged
| quickly and that stale push tokens age out. Without it those promises are
| false. withoutOverlapping so a slow run cannot stack.
*/
Schedule::command('ballspot:cleanup-login-codes')->hourly()->withoutOverlapping();

// Publishes the day's daily challenge.
Schedule::command('ballspot:schedule-daily-challenges')->dailyAt('00:05')->withoutOverlapping();

// Closes the monthly competition and awards placements.
Schedule::command('ballspot:close-competition')->monthlyOn(1, '00:15')->withoutOverlapping();

// Drops expired API tokens once SANCTUM_TOKEN_EXPIRATION_MINUTES is set.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Daily Challenge reminder pushes. No-op unless
// BALLPICKER_DAILY_REMINDER_PUSH_ENABLED=true (see config/ballspot.php for the
// cutover rule). 15-minute cadence + a 60-minute send window per user.
Schedule::command('ballspot:send-daily-reminders')->everyFifteenMinutes()->withoutOverlapping();
