<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Single source of truth for the recurring competition window (daily-challenge
 * leaderboard + top-10% badge). Config `ballspot.competition.period` selects
 * weekly (Mon–Sun) or monthly (calendar month). Timezone-aware so period
 * boundaries land correctly; challenge_date comparisons use the local dates.
 */
class CompetitionPeriodService
{
    public const WEEKLY  = 'weekly';
    public const MONTHLY = 'monthly';

    public function type(): string
    {
        $type = config('ballspot.competition.period', self::MONTHLY);

        return in_array($type, [self::WEEKLY, self::MONTHLY], true) ? $type : self::MONTHLY;
    }

    public function label(): string
    {
        $override = config('ballspot.competition.label');

        return $override ?: ucfirst($this->type());
    }

    public function timezone(): string
    {
        return config('ballspot.competition.timezone') ?: config('app.timezone', 'UTC');
    }

    /** Start date (Y-m-d) of the current period. */
    public function start(?Carbon $now = null): string
    {
        return $this->boundaries($now)[0];
    }

    /** End date (Y-m-d) of the current period. */
    public function end(?Carbon $now = null): string
    {
        return $this->boundaries($now)[1];
    }

    /**
     * Full period descriptor for API responses.
     *
     * @return array{period_type:string,period_label:string,period_start:string,period_end:string}
     */
    public function toArray(?Carbon $now = null): array
    {
        [$start, $end] = $this->boundaries($now);

        return [
            'period_type'  => $this->type(),
            'period_label' => $this->label(),
            'period_start' => $start,
            'period_end'   => $end,
        ];
    }

    /**
     * @return array{0:string,1:string} [startDate, endDate] as Y-m-d strings.
     */
    private function boundaries(?Carbon $now = null): array
    {
        $now = $now ? $now->copy()->setTimezone($this->timezone()) : Carbon::now($this->timezone());

        if ($this->type() === self::WEEKLY) {
            return [
                $now->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                $now->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            ];
        }

        return [
            $now->copy()->startOfMonth()->toDateString(),
            $now->copy()->endOfMonth()->toDateString(),
        ];
    }
}
