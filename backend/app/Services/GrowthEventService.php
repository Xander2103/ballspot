<?php

namespace App\Services;

use App\Models\AppEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records growth events (share taps, review attempts) and aggregates them for
 * the admin Growth page.
 *
 * Privacy: only the event name, the user id and an allow-listed metadata
 * subset are stored. The shared message, review text and the target platform
 * are never accepted (see METADATA_KEYS / TRIGGERS).
 */
class GrowthEventService
{
    /** Metadata keys a caller may attach; everything else is dropped. */
    private const METADATA_KEYS = ['score', 'daily_challenge_id', 'trigger', 'rewards', 'xp'];

    /** Allowed values for metadata.trigger (what caused a review prompt). */
    public const TRIGGERS = ['daily_score', 'challenge_milestone', 'pack_completed', 'button'];

    public const WINDOW_DAYS = 7;

    /**
     * Human labels for the admin page. Review wording is deliberately
     * "attempts" / "taps" / "requests" — never "reviews placed": the native
     * review APIs do not tell us whether a review was submitted.
     */
    public const LABELS = [
        AppEvent::SHARE_APP_TAPPED                     => 'App shares (Share BallPicker taps)',
        AppEvent::DAILY_RESULT_SHARE_TAPPED            => 'Daily result shares',
        AppEvent::DAILY_RESULT_HIGH_SCORE_SHARE_TAPPED => 'High-score daily result shares (80+)',
        AppEvent::SHARE_REWARD_CLAIMED                 => 'Share rewards claimed',
        AppEvent::RATE_APP_TAPPED                      => 'Rate button taps',
        AppEvent::REVIEW_PROMPT_ATTEMPTED              => 'Automatic review prompt attempts',
        AppEvent::NATIVE_REVIEW_REQUESTED              => 'Native review requests',
        AppEvent::REVIEW_PROMPT_SKIPPED_DUE_TO_COMPLETED => 'Review prompt skipped (already completed on device)',
        AppEvent::REVIEW_PROMPT_SKIPPED_DUE_TO_COOLDOWN  => 'Review prompt skipped (cooldown)',
    ];

    public function record(?User $user, string $eventName, array $metadata = []): AppEvent
    {
        if (!in_array($eventName, AppEvent::ALL, true)) {
            throw new \InvalidArgumentException("Unknown growth event: {$eventName}");
        }

        $clean = $this->sanitizeMetadata($metadata);

        return AppEvent::create([
            'user_id'    => $user?->id,
            'event_name' => $eventName,
            'metadata'   => $clean === [] ? null : $clean,
            'created_at' => now(),
        ]);
    }

    /** Keep only the allow-listed keys with sane scalar values. */
    public function sanitizeMetadata(array $metadata): array
    {
        $clean = [];
        foreach (self::METADATA_KEYS as $key) {
            if (!array_key_exists($key, $metadata)) {
                continue;
            }
            $value = $metadata[$key];
            if ($key === 'trigger') {
                if (is_string($value) && in_array($value, self::TRIGGERS, true)) {
                    $clean[$key] = $value;
                }
            } elseif ($key === 'rewards') {
                if (is_array($value)) {
                    $clean[$key] = array_values(array_filter($value, 'is_string'));
                }
            } elseif (is_int($value) || (is_numeric($value) && (string) (int) $value === (string) $value)) {
                $clean[$key] = (int) $value;
            }
        }

        return $clean;
    }

    /**
     * Totals + last-7-day counts per event, plus the headline numbers the
     * admin page shows. Counts only — never rows, never metadata.
     */
    public function stats(?Carbon $now = null): array
    {
        $now   = $now ?? now();
        $since = $now->copy()->subDays(self::WINDOW_DAYS);

        $totals = AppEvent::query()
            ->select('event_name', DB::raw('COUNT(*) as c'))
            ->groupBy('event_name')
            ->pluck('c', 'event_name');

        $recent = AppEvent::query()
            ->where('created_at', '>=', $since)
            ->select('event_name', DB::raw('COUNT(*) as c'))
            ->groupBy('event_name')
            ->pluck('c', 'event_name');

        $total  = fn (string $name) => (int) ($totals[$name] ?? 0);
        $last7  = fn (string $name) => (int) ($recent[$name] ?? 0);

        $events = [];
        foreach (AppEvent::ALL as $name) {
            $events[] = [
                'name'        => $name,
                'label'       => self::LABELS[$name],
                'total'       => $total($name),
                'last_7_days' => $last7($name),
            ];
        }

        return [
            'generated_at' => $now,
            'window_days'  => self::WINDOW_DAYS,
            'totals' => [
                'app_shares'               => $total(AppEvent::SHARE_APP_TAPPED),
                'daily_result_shares'      => $total(AppEvent::DAILY_RESULT_SHARE_TAPPED),
                'high_score_result_shares' => $total(AppEvent::DAILY_RESULT_HIGH_SCORE_SHARE_TAPPED),
                'share_rewards_claimed'    => $total(AppEvent::SHARE_REWARD_CLAIMED),
                'rate_button_taps'         => $total(AppEvent::RATE_APP_TAPPED),
                'review_prompt_attempts'   => $total(AppEvent::REVIEW_PROMPT_ATTEMPTED),
                'native_review_requests'   => $total(AppEvent::NATIVE_REVIEW_REQUESTED),
                // All shares = app shares + daily result shares (high-score
                // shares are a subset of daily result shares).
                'shares'                   => $total(AppEvent::SHARE_APP_TAPPED) + $total(AppEvent::DAILY_RESULT_SHARE_TAPPED),
            ],
            'last_7_days' => [
                'shares'                 => $last7(AppEvent::SHARE_APP_TAPPED) + $last7(AppEvent::DAILY_RESULT_SHARE_TAPPED),
                // Review attempts = automatic prompt attempts + manual Rate
                // button taps. Attempts, not confirmed reviews.
                'review_attempts'        => $last7(AppEvent::REVIEW_PROMPT_ATTEMPTED) + $last7(AppEvent::RATE_APP_TAPPED),
                'review_prompt_attempts' => $last7(AppEvent::REVIEW_PROMPT_ATTEMPTED),
                'rate_button_taps'       => $last7(AppEvent::RATE_APP_TAPPED),
            ],
            'events' => $events,
        ];
    }
}
