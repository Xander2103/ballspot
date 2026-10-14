<?php

namespace App\Services;

use App\Models\CompetitionFinish;
use App\Models\DailyChallengeGuess;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only numbers for /admin/competition. Nothing here changes scoring,
 * standings, the close flow, XP or trophies — it only reads what those
 * already produced, through the same CompetitionStandingsService the live
 * leaderboard and the close command use, so the preview matches the app.
 *
 * Privacy: usernames only (never emails); anonymized accounts are excluded
 * from standings exactly as the leaderboard excludes them; growth/abuse
 * numbers are counts. Cheap enough to compute per page load — two standings
 * windows, a handful of aggregates, no cache to go stale.
 */
class CompetitionAdminStatsService
{
    public const LEADERBOARD_PREVIEW_ROWS = 10;

    /** Badges the close flow awards for a monthly period (BadgeService::evaluateCompetitionFinish). */
    public const COMPETITION_BADGES = ['monthly_winner', 'monthly_podium', 'monthly_top_10'];

    public function __construct(
        private CompetitionPeriodService $period,
        private CompetitionStandingsService $standings,
        private GrowthEventService $growth,
        private AuthAbuseStatsService $abuse,
    ) {}

    public function dashboard(?Carbon $now = null): array
    {
        $now      = $now ? $now->copy()->setTimezone($this->period->timezone()) : Carbon::now($this->period->timezone());
        $current  = $this->period->toArray($now);
        $previous = $this->period->previousPeriod(null, $now);

        $currentStandings  = $this->standings->forWindow($current['period_start'], $current['period_end']);
        $previousStandings = $this->standings->forWindow($previous['period_start'], $previous['period_end']);

        return [
            'generated_at' => $now,
            'timezone'     => $this->period->timezone(),
            'current'      => $this->currentPeriod($current, $currentStandings, $now),
            'previous'     => $this->previousPeriod($previous, $previousStandings),
            'leaderboard'  => $this->leaderboardPreview($currentStandings),
            'accounts'     => $this->accounts($now),
            'config'       => $this->config(),
            'commands'     => $this->commands($previous),
            'growth'       => $this->growth(),
            'abuse'        => $this->abuse->last24h($now),
        ];
    }

    // ------------------------------------------------------------------
    // Periods
    // ------------------------------------------------------------------

    private function currentPeriod(array $period, Collection $standings, Carbon $now): array
    {
        $players = $standings->count();
        $guesses = (int) $standings->sum('challenges_played');
        $end     = Carbon::parse($period['period_end'], $this->period->timezone())->endOfDay();
        $today   = $now->copy()->startOfDay();

        return $period + [
            'days_remaining'   => max(0, (int) $today->diffInDays($end->copy()->startOfDay(), false)),
            'ends_today'       => $today->isSameDay($end),
            'players'          => $players,
            'guesses'          => $guesses,
            // Weighted over every guess in the window (sum of scores / guesses).
            'average_score'    => $guesses > 0 ? round($standings->sum('total_score') / $guesses, 1) : null,
            'highest_guess'    => $this->highestGuess($period['period_start'], $period['period_end']),
            'leader_total'     => $players > 0 ? (int) $standings->first()['total_score'] : null,
            // Same rule as the close flow / live badge: top 10% of a field of 10+.
            'top_finisher_eligible' => $players >= 10 ? (int) ceil($players * 0.1) : 0,
            'top_finisher_min_field' => 10,
            // The leaderboard is computed live on every request — there is no
            // stored recalculation moment to show.
            'last_recalculated_at' => null,
        ];
    }

    private function previousPeriod(array $period, Collection $standings): array
    {
        $finishes = CompetitionFinish::where('period_type', $period['period_type'])
            ->whereDate('period_start', $period['period_start'])
            ->whereDate('period_end', $period['period_end'])
            ->get();

        $closedAt = $finishes->map(fn (CompetitionFinish $f) => $f->awarded_at ?? $f->created_at)->filter()->max();
        $guesses  = (int) $standings->sum('challenges_played');

        $badges = DB::table('user_badges')
            ->join('badges', 'badges.id', '=', 'user_badges.badge_id')
            ->whereIn('badges.code', self::COMPETITION_BADGES)
            ->where('user_badges.context->period_start', $period['period_start'])
            ->count();

        $lastClosed = CompetitionFinish::orderByDesc('period_start')->orderBy('placement')->first();

        return $period + [
            'closed'          => $finishes->isNotEmpty(),
            'closed_at'       => $closedAt,
            'players'         => $standings->count(),
            'guesses'         => $guesses,
            'average_score'   => $guesses > 0 ? round($standings->sum('total_score') / $guesses, 1) : null,
            'podium_finishes' => $finishes->count(),
            'trophies_awarded'=> $badges,
            'xp_awarded'      => (int) $finishes->sum('xp_awarded'),
            'awards_badges'   => $period['period_type'] === CompetitionPeriodService::MONTHLY,
            'last_closed'     => $lastClosed ? [
                'label'         => $lastClosed->period_label,
                'period_type'   => $lastClosed->period_type,
                'total_players' => (int) $lastClosed->total_players,
                'closed_at'     => $lastClosed->awarded_at ?? $lastClosed->created_at,
            ] : null,
        ];
    }

    /** Highest single daily score in the window (same eligibility as standings). */
    private function highestGuess(string $start, string $end): ?int
    {
        $max = DailyChallengeGuess::whereHas('dailyChallenge', fn ($q) => $q->whereBetween('challenge_date', [$start, $end]))
            ->whereIn('user_id', User::query()->whereNull('anonymized_at')->select('id'))
            ->max('score');

        return $max === null ? null : (int) $max;
    }

    // ------------------------------------------------------------------
    // Leaderboard preview
    // ------------------------------------------------------------------

    /**
     * Top rows of the live standings with usernames only. Standings already
     * exclude anonymized accounts; a user missing entirely is skipped. `tied`
     * marks a total shared with a neighbour (the deterministic tie-break —
     * earliest last guess, then user id — decided the order).
     *
     * @return array<int,array>
     */
    private function leaderboardPreview(Collection $standings): array
    {
        $top   = $standings->take(self::LEADERBOARD_PREVIEW_ROWS)->values();
        $users = User::whereIn('id', $top->pluck('user_id'))->whereNull('anonymized_at')->get(['id', 'username'])->keyBy('id');
        $totals = $top->pluck('total_score')->all();

        $rows = [];
        foreach ($top as $i => $row) {
            $user = $users->get($row['user_id']);
            if (!$user) {
                continue;
            }
            $tied = ($i > 0 && $totals[$i - 1] === $row['total_score'])
                || (isset($totals[$i + 1]) && $totals[$i + 1] === $row['total_score']);

            $rows[] = [
                'placement'         => $row['placement'],
                'username'          => $user->username,
                'total_score'       => $row['total_score'],
                'challenges_played' => $row['challenges_played'],
                'avg_score'         => $row['avg_score'],
                'tied'              => $tied,
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Accounts / config / commands / growth
    // ------------------------------------------------------------------

    private function accounts(Carbon $now): array
    {
        return [
            'active'    => User::whereNull('anonymized_at')->count(),
            'deleted'   => User::whereNotNull('anonymized_at')->count(),
            'new_7d'    => User::where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'new_24h'   => User::where('created_at', '>=', $now->copy()->subDay())->count(),
        ];
    }

    private function config(): array
    {
        $rawPeriod = config('ballspot.competition.period');

        return [
            'period_env'      => 'BALLPICKER_COMPETITION_PERIOD',
            'period_value'    => is_string($rawPeriod) ? $rawPeriod : null,
            'period_effective'=> $this->period->type(),
            'period_valid'    => in_array($rawPeriod, [CompetitionPeriodService::WEEKLY, CompetitionPeriodService::MONTHLY], true),
            'label_env'       => 'BALLPICKER_COMPETITION_LABEL',
            'label_value'     => config('ballspot.competition.label') ?: null,
            'label_effective' => $this->period->label(),
            'timezone_env'    => 'BALLPICKER_COMPETITION_TIMEZONE',
            'timezone'        => $this->period->timezone(),
            'config_cached'   => app()->configurationIsCached(),
        ];
    }

    private function commands(array $previous): array
    {
        return [
            'dry_run' => 'php artisan ballspot:close-competition --dry-run',
            'close'   => 'php artisan ballspot:close-competition',
            'target'  => $previous['period_label'],
            'scheduled' => 'monthly on the 1st at 00:15 (routes/console.php)',
        ];
    }

    /** Growth counters when the app_events table exists; null hides the card. */
    private function growth(): ?array
    {
        if (!Schema::hasTable('app_events')) {
            return null;
        }

        $stats = $this->growth->stats();
        $stats['has_events'] = array_sum(array_column($stats['events'], 'total')) > 0;

        return $stats;
    }
}
