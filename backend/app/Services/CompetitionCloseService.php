<?php

namespace App\Services;

use App\Models\CompetitionFinish;
use App\Models\User;
use App\Models\XpEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Closes a COMPLETED competition period: computes final standings via the same
 * CompetitionStandingsService the live leaderboard uses, stores top-3 finishes,
 * and awards XP + badges exactly once. All rewards are virtual (XP ledger +
 * badges) — no prizes, money or payments.
 *
 * Idempotency: finishes are updateOrCreate'd against the table's unique period
 * key, XP is deduped by the ledger on (user, competition_finish, finish id),
 * and badges are once-per-user. Re-running a close changes nothing.
 *
 * Tie handling lives in CompetitionStandingsService (shared with the live
 * leaderboard): total_score DESC, earliest last-qualifying guess ASC, user_id
 * ASC as the final deterministic fallback.
 */
class CompetitionCloseService
{
    public const STATUS_CLOSED         = 'closed';
    public const STATUS_DRY_RUN        = 'dry_run';
    public const STATUS_ALREADY_CLOSED = 'already_closed';
    public const STATUS_NO_PLAYERS     = 'no_players';
    public const STATUS_REFUSED_OPEN   = 'refused_open_period';

    public function __construct(
        private CompetitionPeriodService $periodService,
        private CompetitionStandingsService $standingsService,
        private BadgeService $badgeService,
        private XpService $xpService,
    ) {}

    /**
     * Close the period described by $period (see CompetitionPeriodService::describe).
     *
     * @param  bool $dryRun  compute + preview only, write nothing
     * @param  bool $force   allow closing a period that has not ended yet
     * @return array{
     *   status:string, period:array, total_players:int,
     *   finishes:array<int,array>, message:string
     * }
     */
    public function close(array $period, bool $dryRun = false, bool $force = false): array
    {
        // Never close the current/future (still-open) period by default — its
        // standings are not final. --force exists for deliberate ops use only.
        $today = Carbon::now($this->periodService->timezone())->toDateString();
        if ($period['period_end'] >= $today && !$force) {
            return $this->summary(self::STATUS_REFUSED_OPEN, $period, 0, [],
                "Period {$period['period_label']} has not ended yet ({$period['period_start']} → {$period['period_end']}); refusing to close an open period without --force.");
        }

        $standings    = $this->standingsService->forWindow($period['period_start'], $period['period_end']);
        $totalPlayers = $standings->count();

        if ($totalPlayers === 0) {
            return $this->summary(self::STATUS_NO_PLAYERS, $period, 0, [], 'No eligible players in this period — nothing to close.');
        }

        $alreadyClosed = CompetitionFinish::where('period_type', $period['period_type'])
            ->whereDate('period_start', $period['period_start'])
            ->whereDate('period_end', $period['period_end'])
            ->exists();

        $preview = $this->buildPreview($standings, $totalPlayers, $period);

        if ($dryRun) {
            return $this->summary(self::STATUS_DRY_RUN, $period, $totalPlayers, $preview,
                'Dry run — nothing was written.' . ($alreadyClosed ? ' NOTE: this period already has stored finishes.' : ''));
        }

        if ($alreadyClosed) {
            return $this->summary(self::STATUS_ALREADY_CLOSED, $period, $totalPlayers, $preview,
                'This period is already closed — existing finishes kept, nothing written.');
        }

        $written = DB::transaction(fn () => $this->award($standings, $totalPlayers, $period));

        return $this->summary(self::STATUS_CLOSED, $period, $totalPlayers, $written,
            'Period closed: ' . count($written) . ' finish(es) stored, XP and badges awarded.');
    }

    /**
     * Store top-3 finishes and award XP/badges. Only real placements are
     * created — 1 player means a single 1st place, never fake 2nd/3rd. Users
     * outside the top 3 but inside the top 10% (fields of 10+) get only the
     * monthly_top_10 badge, no finish row.
     *
     * @return array<int,array>
     */
    private function award($standings, int $totalPlayers, array $period): array
    {
        $rows = [];

        foreach ($standings as $row) {
            $placement = $row['placement'];
            $inTop10   = $totalPlayers >= 10 && $placement <= (int) ceil($totalPlayers * 0.1);

            if ($placement > 3 && !$inTop10) {
                break; // standings are ordered — nothing left to award
            }

            $user = User::find($row['user_id']);
            if (!$user) {
                continue; // hard-deleted account — skip, do not invent a placement
            }

            $badgeContext = [
                'period_type'  => $period['period_type'],
                'period_label' => $period['period_label'],
                'period_start' => $period['period_start'],
            ];
            $badges = $this->badgeService->evaluateCompetitionFinish(
                $user, $period['period_type'], $placement, $totalPlayers, $badgeContext,
            );

            if ($placement > 3) {
                continue; // top-10% badge only — no finish record beyond the podium
            }

            $finish = CompetitionFinish::updateOrCreate(
                [
                    'period_type'  => $period['period_type'],
                    'period_start' => $period['period_start'],
                    'period_end'   => $period['period_end'],
                    'user_id'      => $row['user_id'],
                ],
                [
                    'period_label'  => $period['period_label'],
                    'placement'     => $placement,
                    'total_score'   => $row['total_score'],
                    'total_players' => $totalPlayers,
                    'metadata'      => [
                        'challenges_played' => $row['challenges_played'],
                        'avg_score'         => $row['avg_score'],
                    ],
                ],
            );

            $xpAwarded = $this->awardXp($user, $finish, $period);

            $rows[] = [
                'user_id'     => $user->id,
                'username'    => $user->username,
                'placement'   => $placement,
                'total_score' => $row['total_score'],
                'xp'          => $xpAwarded,
                'badges'      => array_map(fn ($b) => $b->code, $badges),
            ];
        }

        return $rows;
    }

    /** Placement XP through the ledger (deduped per finish). Returns the freshly-awarded amount. */
    private function awardXp(User $user, CompetitionFinish $finish, array $period): int
    {
        $amounts = (array) config('ballspot.xp.competition_finish');
        $amount  = (int) ($amounts[$finish->placement] ?? 0);
        if ($amount === 0) {
            return (int) $finish->xp_awarded;
        }

        $word   = $period['period_type'] === CompetitionPeriodService::WEEKLY ? 'Weekly' : 'Monthly';
        $reason = match ($finish->placement) {
            1       => "{$word} competition winner",
            2       => "{$word} competition runner-up",
            default => "{$word} competition top 3",
        };

        $event = $this->xpService->awardXp(
            $user,
            XpEvent::SOURCE_COMPETITION_FINISH,
            $finish->id, // dedup key: one award per finish
            $amount,
            $reason,
            [
                'period_type'  => $period['period_type'],
                'period_label' => $period['period_label'],
                'placement'    => $finish->placement,
            ],
        );

        if ($event) {
            $finish->update(['xp_awarded' => $amount, 'awarded_at' => now()]);
            return $amount;
        }

        return (int) $finish->xp_awarded; // idempotent replay
    }

    /**
     * Read-only top-3 preview (used for dry runs and already-closed reruns).
     *
     * @return array<int,array>
     */
    private function buildPreview($standings, int $totalPlayers, array $period): array
    {
        $amounts = (array) config('ballspot.xp.competition_finish');
        $top3    = $standings->take(3);
        $users   = User::whereIn('id', $top3->pluck('user_id'))->get(['id', 'username'])->keyBy('id');

        return $top3->map(function ($row) use ($users, $amounts, $totalPlayers, $period) {
            $badges = [];
            if ($period['period_type'] === CompetitionPeriodService::MONTHLY) {
                if ($row['placement'] === 1) {
                    $badges[] = 'monthly_winner';
                }
                $badges[] = 'monthly_podium';
                if ($totalPlayers >= 10 && $row['placement'] <= (int) ceil($totalPlayers * 0.1)) {
                    $badges[] = 'monthly_top_10';
                }
            }

            return [
                'user_id'     => $row['user_id'],
                'username'    => $users->get($row['user_id'])?->username,
                'placement'   => $row['placement'],
                'total_score' => $row['total_score'],
                'xp'          => (int) ($amounts[$row['placement']] ?? 0),
                'badges'      => $badges,
            ];
        })->values()->all();
    }

    private function summary(string $status, array $period, int $totalPlayers, array $finishes, string $message): array
    {
        return [
            'status'        => $status,
            'period'        => $period,
            'total_players' => $totalPlayers,
            'finishes'      => $finishes,
            'message'       => $message,
        ];
    }
}
