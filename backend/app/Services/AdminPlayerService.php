<?php

namespace App\Services;

use App\Models\CompetitionFinish;
use App\Models\DailyChallengeGuess;
use App\Models\TournamentFinish;
use App\Models\User;
use App\Models\XpEvent;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Data behind the admin Players pages. Shows only what is stored; never loads
 * password, friend code, push tokens or login codes into the view.
 */
class AdminPlayerService
{
    public const STATUSES = ['all', 'active', 'unverified', 'deleted'];

    public const PER_PAGE = 25;

    private const LIST_LIMIT = 50;

    public function __construct(
        private DailyStreakService $streaks,
        private PlayerRankService $ranks,
        private CompetitionPeriodService $period,
        private CompetitionStandingsService $standings,
    ) {}

    /**
     * Partial, case-insensitive match on username, name and email, plus an exact
     * match on user id. Each word must appear in the name, so "peeters jan"
     * finds "Jan Peeters" (users.name is one free-text field; first and last
     * name are not stored separately). Exact hits sort first.
     */
    public function search(string $q, string $status = 'all'): LengthAwarePaginator
    {
        $query = User::query()
            ->select(['id', 'username', 'name', 'email', 'email_verified_at', 'anonymized_at', 'is_admin', 'created_at']);

        match ($status) {
            'active'     => $query->whereNull('anonymized_at'),
            'unverified' => $query->whereNull('anonymized_at')->whereNull('email_verified_at'),
            'deleted'    => $query->whereNotNull('anonymized_at'),
            default      => null,
        };

        $term = mb_strtolower(trim($q));
        if ($term !== '') {
            $id   = preg_match('/^#?(\d{1,18})$/', $term, $m) ? (int) $m[1] : null;
            $text = ltrim($term, '#@') === '' ? $term : ltrim($term, '#@');

            $query->where(function ($w) use ($id, $text) {
                $w->whereRaw("LOWER(username) LIKE ? ESCAPE '!'", [$this->like($text)])
                  ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$this->like($text)])
                  ->orWhere(function ($name) use ($text) {
                      foreach (preg_split('/\s+/u', $text) as $word) {
                          $name->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$this->like($word)]);
                      }
                  });
                if ($id !== null) {
                    $w->orWhere('id', $id);
                }
            });

            $query->orderByRaw(
                'CASE WHEN id = ? THEN 0 WHEN LOWER(username) = ? THEN 1 WHEN LOWER(email) = ? THEN 2 ELSE 3 END',
                [$id ?? 0, $text, $text],
            );
        }

        return $query->orderByDesc('id')->paginate(self::PER_PAGE);
    }

    /** %term% for a LIKE ... ESCAPE '!', so a typed % or _ matches literally. */
    private function like(string $term): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
    }

    public function dossier(User $user): array
    {
        return [
            'contact_url' => $this->contactUrl($user),
            'daily'       => $this->daily($user),
            'competition' => $this->competition($user),
            'tournaments' => $this->tournaments($user),
            'xp'          => $this->xp($user),
            'badges'      => $user->badges()->orderByPivot('earned_at', 'desc')->get()->map(fn ($b) => [
                'name'      => $b->name,
                'rarity'    => $b->rarity,
                'earned_at' => $b->pivot->earned_at,
            ])->all(),
        ];
    }

    /** Only a verified address of a live account may be used to reach a player. */
    private function contactUrl(User $user): ?string
    {
        if ($user->anonymized_at !== null || !$user->hasVerifiedEmail()) {
            return null;
        }

        return 'mailto:' . str_replace('%40', '@', rawurlencode($user->email))
            . '?subject=' . rawurlencode('BallPicker');
    }

    private function daily(User $user): array
    {
        $agg = DailyChallengeGuess::where('user_id', $user->id)
            ->selectRaw('COUNT(*) as played, COALESCE(SUM(score), 0) as total_score, AVG(score) as avg_score, MAX(score) as best_score, MAX(submitted_at) as last_played_at')
            ->first();
        $streak = $this->streaks->getStreakForUser($user);

        return [
            'played'         => (int) $agg->played,
            'total_score'    => (int) $agg->total_score,
            'avg_score'      => $agg->avg_score === null ? null : round((float) $agg->avg_score, 1),
            'best_score'     => $agg->best_score === null ? null : (int) $agg->best_score,
            'last_played_at' => $agg->last_played_at,
            'current_streak' => $streak['current'],
            'best_streak'    => $streak['best'],
        ];
    }

    private function competition(User $user): array
    {
        $period = $this->period->toArray();
        $row    = $this->standings->forWindow($period['period_start'], $period['period_end'])
            ->firstWhere('user_id', $user->id);

        return [
            'current' => $row ? [
                'period_label' => $period['period_label'],
                'placement'    => $row['placement'],
                'total_score'  => $row['total_score'],
            ] : null,
            'finishes' => CompetitionFinish::where('user_id', $user->id)
                ->orderByDesc('period_end')
                ->limit(self::LIST_LIMIT)
                ->get(),
        ];
    }

    private function tournaments(User $user): array
    {
        $finishes = TournamentFinish::where('user_id', $user->id)->get()->keyBy('league_id');

        return [
            'total' => $user->leagues()->count(),
            'rows'  => $user->leagues()
                ->orderByDesc('league_members.joined_at')
                ->limit(self::LIST_LIMIT)
                ->get(['leagues.id', 'leagues.name', 'leagues.status'])
                ->map(fn ($league) => [
                    'id'        => $league->id,
                    'name'      => $league->name,
                    'status'    => $league->status,
                    'joined_at' => $league->pivot->joined_at,
                    'finish'    => $finishes->get($league->id),
                ])->all(),
        ];
    }

    private function xp(User $user): array
    {
        return [
            'rank'   => $this->ranks->forUser($user),
            'events' => XpEvent::where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get(['amount', 'reason', 'source_type', 'created_at']),
        ];
    }
}
