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
     * Descriptor for the most recently COMPLETED period of $type — the one the
     * close command targets by default (in July, closing monthly closes June).
     *
     * @return array{period_type:string,period_label:string,period_start:string,period_end:string}
     */
    public function previousPeriod(?string $type = null, ?Carbon $now = null): array
    {
        $type = $type ?: $this->type();
        $now  = $now ? $now->copy()->setTimezone($this->timezone()) : Carbon::now($this->timezone());

        $anchor = $type === self::WEEKLY
            ? $now->copy()->subWeek()
            : $now->copy()->subMonthNoOverflow();

        return $this->describe($type, $anchor);
    }

    /**
     * Descriptor for the period of $type containing $anchor, labelled with the
     * SPECIFIC period ("June 2026" / "Week 25, 2026") rather than the generic
     * live-leaderboard label — closed finishes must name their exact window.
     *
     * @return array{period_type:string,period_label:string,period_start:string,period_end:string}
     */
    public function describe(string $type, Carbon $anchor): array
    {
        [$start, $end] = $this->boundariesFor($type, $anchor);

        return [
            'period_type'  => $type,
            'period_label' => $this->labelFor($type, Carbon::parse($start)),
            'period_start' => $start,
            'period_end'   => $end,
        ];
    }

    /** Human label for a specific period window. */
    public function labelFor(string $type, Carbon $start): string
    {
        if ($type === self::WEEKLY) {
            return 'Week ' . $start->isoWeek . ', ' . $start->isoWeekYear;
        }

        return $start->format('F Y');
    }

    /**
     * @return array{0:string,1:string} [startDate, endDate] as Y-m-d strings.
     */
    private function boundaries(?Carbon $now = null): array
    {
        $now = $now ? $now->copy()->setTimezone($this->timezone()) : Carbon::now($this->timezone());

        return $this->boundariesFor($this->type(), $now);
    }

    /**
     * @return array{0:string,1:string} [startDate, endDate] as Y-m-d strings.
     */
    public function boundariesFor(string $type, Carbon $anchor): array
    {
        $anchor = $anchor->copy()->setTimezone($this->timezone());

        if ($type === self::WEEKLY) {
            return [
                $anchor->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                $anchor->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            ];
        }

        return [
            $anchor->copy()->startOfMonth()->toDateString(),
            $anchor->copy()->endOfMonth()->toDateString(),
        ];
    }
}
