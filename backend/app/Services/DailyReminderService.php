<?php

namespace App\Services;

use App\Models\DailyChallenge;
use App\Models\NotificationSetting;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Sends the Daily Challenge push reminder. Scheduled every 15 minutes.
 *
 * A user is reminded when ALL hold: daily reminders enabled, at least one push
 * token, today's ACTIVE daily (UTC date) not yet played, account not
 * anonymized, not already reminded today, and their local clock (their IANA
 * timezone, invalid/missing → UTC) is within [reminder_time, +60min).
 *
 * At-most-once per user per daily: last_daily_reminder_date is written BEFORE
 * the Expo call, so a crash mid-send can only ever skip, never duplicate.
 */
class DailyReminderService
{
    /** A reminder fires within [reminder_time, reminder_time + WINDOW_MINUTES). */
    public const WINDOW_MINUTES = 60;

    public function __construct(private ExpoPushService $push)
    {
    }

    /**
     * @return array{daily_id: ?int, candidates: int, sent: int, failed: int,
     *               skipped_window: int, invalid_tokens_removed: int}
     */
    public function run(bool $dryRun = false): array
    {
        $today = Carbon::today()->toDateString(); // UTC (config/app.php timezone)
        $daily = DailyChallenge::active()->forDate($today)->first();

        $result = [
            'daily_id' => $daily?->id, 'candidates' => 0, 'sent' => 0,
            'failed' => 0, 'skipped_window' => 0, 'invalid_tokens_removed' => 0,
        ];

        if (! $daily) {
            return $result;
        }

        $now = CarbonImmutable::now('UTC');

        NotificationSetting::query()
            ->where('daily_reminder_enabled', true)
            ->where(fn ($q) => $q->whereNull('last_daily_reminder_date')
                ->orWhere('last_daily_reminder_date', '!=', $today))
            ->whereHas('user', fn ($q) => $q->whereNull('anonymized_at')->whereHas('pushTokens'))
            ->whereDoesntHave('user.dailyChallengeGuesses',
                fn ($q) => $q->where('daily_challenge_id', $daily->id))
            ->with('user.pushTokens')
            ->chunkById(200, function ($settings) use (&$result, $now, $today, $dryRun) {
                $due = [];
                foreach ($settings as $setting) {
                    $result['candidates']++;
                    if ($this->isInWindow($setting, $now)) {
                        $due[] = $setting;
                    } else {
                        $result['skipped_window']++;
                    }
                }

                if ($due === [] || $dryRun) {
                    return;
                }

                // Mark BEFORE sending (at-most-once semantics).
                NotificationSetting::whereIn('id', collect($due)->pluck('id'))
                    ->update(['last_daily_reminder_date' => $today]);

                $messages = [];
                foreach ($due as $setting) {
                    foreach ($setting->user->pushTokens as $pushToken) {
                        $messages[] = [
                            'to'    => $pushToken->token,
                            'title' => 'Daily Challenge',
                            'body'  => "Today's BallPicker daily is still waiting for you ⚽",
                            'sound' => 'default',
                        ];
                    }
                }

                $outcome = $this->push->sendMessages($messages);
                $result['sent']                   += $outcome['sent'];
                $result['failed']                 += $outcome['failed'];
                $result['invalid_tokens_removed'] += $outcome['invalid_tokens_removed'];
            });

        Log::info('Daily reminder run', $result);

        return $result;
    }

    private function isInWindow(NotificationSetting $setting, CarbonImmutable $nowUtc): bool
    {
        $time = $setting->reminder_time ?: '19:00';
        [$hour, $minute] = array_map('intval', explode(':', $time) + [0, 0]);

        try {
            $tz = new \DateTimeZone($setting->timezone ?: 'UTC');
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }

        $local  = $nowUtc->setTimezone($tz);
        $target = $local->setTime($hour, $minute, 0);

        return $local >= $target && $local < $target->addMinutes(self::WINDOW_MINUTES);
    }
}
