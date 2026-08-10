<?php

namespace App\Console\Commands;

use App\Services\DailyReminderService;
use Illuminate\Console\Command;

class SendDailyReminders extends Command
{
    protected $signature = 'ballspot:send-daily-reminders {--dry-run : Report who would be reminded without sending}';

    protected $description = 'Push the Daily Challenge reminder to opted-in users who have not played today';

    public function handle(DailyReminderService $service): int
    {
        if (! config('ballspot.notifications.push_enabled')
            || ! config('ballspot.notifications.daily_reminder_push_enabled')) {
            $this->info('Daily reminder push is disabled (BALLPICKER_DAILY_REMINDER_PUSH_ENABLED).');

            return self::SUCCESS;
        }

        $result = $service->run((bool) $this->option('dry-run'));

        $this->info(sprintf(
            'Daily %s — candidates: %d, sent: %d, failed: %d, outside window: %d, dead tokens removed: %d',
            $result['daily_id'] ? "#{$result['daily_id']}" : '(none active)',
            $result['candidates'],
            $result['sent'],
            $result['failed'],
            $result['skipped_window'],
            $result['invalid_tokens_removed'],
        ));

        return self::SUCCESS;
    }
}
