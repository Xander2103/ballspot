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

            // Placement rewards (XP + podium badges) require a real field. A solo
            // (or below-threshold) tournament can be created, started and
            // "won" by one account in a tight loop — without this guard that
            // farms ~1000 XP per cycle and free winner/podium badges. The finish
            // row is still recorded so history/standings stay intact.
            $minPlayers     = (int) config('ballspot.tournaments.min_players_for_rewards', 2);
            $rewardsEnabled = $totalPlayers >= $minPlayers;

            foreach ($standings as $row) {
                $user = User::find($row['user_id']);
                if (!$user) {
                    continue;
                }

                $badges = $rewardsEnabled
                    ? $this->badgeService->evaluateTournamentFinish($user, $league, $row['placement'], $totalPlayers)
                    : [];
                $xp     = $rewardsEnabled
                    ? $this->awardXp($user, $league, $row['placement'])
                    : 0;

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

            if ($rewardsEnabled) {
                $this->awardSkillTrophies($league, $standings, $perUser);
            }

            return ['total_players' => $totalPlayers, 'per_user' => $perUser];
        });
    }

    /**
     * Tournament-wide skill trophies: Sharpshooter (closest single guess by
     * distance; highest single score when any distance is missing) and Most
     * Consistent (best average over enough rounds). Awards are skipped, never
     * guessed, when the data cannot support a fair call. Idempotent via
     * BadgeService::award() + the completion claim.
     */
    private function awardSkillTrophies(League $league, array $standings, array &$perUser): void
    {
        $totalRounds = $league->rounds()->count();

        $winners = [
            'sharpshooter'    => $this->sharpshooterUserId($league),
            'most_consistent' => $this->mostConsistentUserId($standings, $totalRounds),
        ];

        foreach ($winners as $code => $userId) {
            if ($userId === null) {
                continue;
            }
            $user = User::find($userId);
            if (!$user) {
                continue;
            }
            $badge = $this->badgeService->award($user, $code, ['league_id' => $league->id]);
            if ($badge && isset($perUser[$userId])) {
                $perUser[$userId]['new_badges'][] = $badge;
            }
        }
    }

    /** Closest single guess. Distance only counts when every guess has one. */
    private function sharpshooterUserId(League $league): ?int
    {
        $guesses = Guess::whereIn('league_round_id', $league->rounds()->pluck('id'))
            ->get(['user_id', 'distance', 'score', 'submitted_at']);
        if ($guesses->isEmpty()) {
            return null;
        }

        $useDistance = $guesses->every(fn ($g) => $g->distance !== null);

        $best = $guesses->sort(function ($a, $b) use ($useDistance) {
            if ($useDistance && (float) $a->distance !== (float) $b->distance) {
                return (float) $a->distance <=> (float) $b->distance; // closer first
            }
            if (!$useDistance && (int) $a->score !== (int) $b->score) {
                return (int) $b->score <=> (int) $a->score; // higher first
            }
            if ((string) $a->submitted_at !== (string) $b->submitted_at) {
                return strcmp((string) $a->submitted_at, (string) $b->submitted_at); // earlier first
            }
            return $a->user_id <=> $b->user_id; // stable final tiebreak
        })->first();

        return $best?->user_id;
    }

    /** Best average over enough rounds; null when fairness can't be established. */
    private function mostConsistentUserId(array $standings, int $totalRounds): ?int
    {
        if ($totalRounds < 2) {
            return null; // a single round is not an average
        }
        $minRounds = max(2, (int) ceil($totalRounds / 2));
        $eligible = array_values(array_filter(
            $standings,
            fn ($row) => $row['rounds_played'] >= $minRounds,
        ));
        if (count($eligible) < 2) {
            return null; // no field to be more consistent than
        }
        usort($eligible, function ($a, $b) {
            $avgA = $a['total_score'] / $a['rounds_played'];
            $avgB = $b['total_score'] / $b['rounds_played'];
            if ($avgA !== $avgB) {
                return $avgB <=> $avgA; // higher average first
            }
            return $a['placement'] <=> $b['placement']; // standings tiebreak
        });

        return $eligible[0]['user_id'];
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
