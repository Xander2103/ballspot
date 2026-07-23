<?php

namespace App\Services;

use App\Models\Guess;
use App\Models\League;
use App\Models\TournamentFinish;
use App\Models\User;
use App\Models\XpEvent;
use Illuminate\Support\Facades\DB;

/**
 * Decides when a tournament is finished, computes final standings, and awards
 * winner / top-3 recognition exactly once. All rewards are virtual (badges + XP
 * ledger) — no prizes, money or payments.
 *
 * Completion rule: a tournament is complete when it is `active` and every member
 * has submitted a guess for every round. Each member plays each round at most
 * once, so a member's guess count reaches the round total exactly when they
 * finish. A member who never plays keeps the tournament open — the owner can
 * still cancel it (see docs / known limitations; a time-based sweep is future).
 */
class TournamentCompletionService
{
    public function __construct(
        private BadgeService $badgeService,
        private XpService $xpService,
    ) {}

    public function isComplete(League $league): bool
    {
        if ($league->status !== 'active') {
            return false;
        }

        $roundIds = $league->rounds()->pluck('id');
        $totalRounds = $roundIds->count();
        if ($totalRounds === 0) {
            return false;
        }

        $memberIds = $league->members()->pluck('users.id');
        if ($memberIds->isEmpty()) {
            return false;
        }

        $guessCounts = Guess::whereIn('league_round_id', $roundIds)
            ->whereIn('user_id', $memberIds)
            ->selectRaw('user_id, COUNT(DISTINCT league_round_id) as c')
            ->groupBy('user_id')
            ->pluck('c', 'user_id');

        foreach ($memberIds as $id) {
            if ((int) ($guessCounts[$id] ?? 0) < $totalRounds) {
                return false;
            }
        }

        return true;
    }

    /**
     * Complete the tournament if finished — exactly once. Returns
     * `['total_players' => int, 'per_user' => [userId => ['placement','total_players','xp_awarded','new_badges']]]`
     * on FRESH completion, or null if not finished / already completed / cancelled.
     */
    public function completeIfFinished(League $league): ?array
    {
        if (!$this->isComplete($league)) {
            return null;
        }

        return DB::transaction(function () use ($league) {
            // Atomically claim completion so concurrent or replayed calls award once.
            $claimed = League::where('id', $league->id)
                ->where('status', 'active')
                ->update(['status' => 'completed']);

            if ($claimed === 0) {
                return null; // another request already completed it
            }

            $standings    = $this->calculateStandings($league);
            $totalPlayers = count($standings);
            $perUser      = [];

            foreach ($standings as $row) {
                $user = User::find($row['user_id']);
                if (!$user) {
                    continue;
                }

                $badges = $this->badgeService->evaluateTournamentFinish($user, $league, $row['placement']);
                $xp     = $this->awardXp($user, $league, $row['placement']);

                TournamentFinish::updateOrCreate(
                    ['league_id' => $league->id, 'user_id' => $row['user_id']],
                    [
                        'placement'     => $row['placement'],
                        'total_score'   => $row['total_score'],
                        'rounds_played' => $row['rounds_played'],
                        'metadata'      => ['total_players' => $totalPlayers],
                    ],
                );

                $perUser[$row['user_id']] = [
                    'placement'     => $row['placement'],
                    'total_players' => $totalPlayers,
                    'xp_awarded'    => $xp,
                    'new_badges'    => $badges,
                ];
            }

            return ['total_players' => $totalPlayers, 'per_user' => $perUser];
        });
    }

    /**
     * Deterministic final standings. Sort: total score DESC, then earliest
     * completion (last-guess time) ASC — whoever reached their score first wins
     * the tie — then user id ASC as a final stable tiebreak.
     *
     * @return array<int, array{user_id:int, total_score:int, rounds_played:int, placement:int}>
     */
    public function calculateStandings(League $league): array
    {
        $roundIds = $league->rounds()->pluck('id');

        $rows = Guess::whereIn('league_round_id', $roundIds)
            ->selectRaw('user_id, SUM(score) as total_score, COUNT(*) as rounds_played, MAX(submitted_at) as completed_at')
            ->groupBy('user_id')
            ->get()
            ->map(fn ($r) => [
                'user_id'       => (int) $r->user_id,
                'total_score'   => (int) $r->total_score,
                'rounds_played' => (int) $r->rounds_played,
                'completed_at'  => (string) $r->completed_at,
            ])
            ->sort(function ($a, $b) {
                if ($a['total_score'] !== $b['total_score']) {
                    return $b['total_score'] <=> $a['total_score']; // higher score first
                }
                if ($a['completed_at'] !== $b['completed_at']) {
                    return strcmp($a['completed_at'], $b['completed_at']); // finished first wins tie
                }
                return $a['user_id'] <=> $b['user_id']; // stable final tiebreak
            })
            ->values();

        return $rows->map(function ($row, $i) {
            unset($row['completed_at']);
            return array_merge($row, ['placement' => $i + 1]);
        })->all();
    }

    /** Award placement XP via the ledger (idempotent per user per league). Returns freshly-awarded amount. */
    private function awardXp(User $user, League $league, int $placement): int
    {
        $amounts = (array) config('ballspot.xp.tournament_win');
        $amount  = (int) ($amounts[$placement] ?? 0);
        if ($amount === 0) {
            return 0;
        }

        $reason = match ($placement) {
            1       => 'Tournament winner',
            2       => 'Tournament runner-up',
            default => 'Tournament top 3 finish',
        };

        $event = $this->xpService->awardXp(
            $user,
            XpEvent::SOURCE_TOURNAMENT_WIN,
            $league->id, // dedup key: one tournament-win award per user per league
            $amount,
            $reason,
            ['league_id' => $league->id, 'placement' => $placement],
        );

        return $event ? $amount : 0; // 0 on idempotent replay
    }
}
