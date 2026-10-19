<?php

namespace App\Services\Clubs;

use App\Enums\ClubMemberStatus;
use App\Enums\CompetitionAttemptStatus;
use App\Enums\CompetitionState;
use App\Models\Club;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Club all-time leaderboard (D19, contracts §5 ClubLeaderboardEntry).
 *
 * Per ACTIVE member (D22: left/removed/banned members are hidden, their
 * attempts are kept) and per club pack: the best attempt total across both
 * tracks — normal pack attempts and non-voided competition attempts of
 * non-voided competitions (partial attempts count what they scored, D16).
 * total_score = Σ over the club's packs.
 *
 * Ranking (D17 adapted): total_score desc → score_reached_at asc (the
 * moment the player's current total was reached = the latest of the moments
 * each counted pack best was first reached) → attempts_used asc → shared
 * rank (standard competition ranking 1, 2, 2, 4). Display order inside a
 * shared rank: username asc (not a ranking factor).
 *
 * Players appear once they have scored at least one guess on a club pack.
 * Reads attempt rows (never guesses), aggregated per (player, pack); the
 * result is paginated in memory with an opaque offset cursor.
 */
class ClubLeaderboardService
{
    public const TOP_SIZE = 3;

    /**
     * Every ranked entry, best first, each with the internal `score_reached_at`.
     *
     * @return Collection<int, array>
     */
    public function standings(Club $club, ?User $viewer = null): Collection
    {
        $members = DB::table('club_members')
            ->join('users', 'users.id', '=', 'club_members.user_id')
            ->where('club_members.club_id', $club->id)
            ->where('club_members.status', ClubMemberStatus::Active->value)
            ->whereNull('users.anonymized_at')
            ->get(['users.id', 'users.username', 'users.avatar_path'])
            ->keyBy('id');

        if ($members->isEmpty()) {
            return collect();
        }

        $packIds = DB::table('challenge_packs')->where('club_id', $club->id)->pluck('id');
        if ($packIds->isEmpty()) {
            return collect();
        }

        $activeMembers = fn ($q) => $q->select('user_id')->from('club_members')
            ->where('club_id', $club->id)
            ->where('status', ClubMemberStatus::Active->value);

        $normal = DB::table('pack_attempts')
            ->whereIn('challenge_pack_id', $packIds)
            ->whereIn('user_id', $activeMembers)
            ->where('current_index', '>', 0)
            ->get(['user_id', 'challenge_pack_id', 'total_score', 'status', 'completed_at', 'updated_at']);

        $competition = DB::table('club_competition_attempts')
            ->join('club_competitions', 'club_competitions.id', '=', 'club_competition_attempts.club_competition_id')
            ->where('club_competitions.club_id', $club->id)
            ->where('club_competitions.state', '!=', CompetitionState::Voided->value)
            ->where('club_competition_attempts.status', '!=', CompetitionAttemptStatus::Voided->value)
            ->where('club_competition_attempts.guesses_count', '>', 0)
            ->whereIn('club_competition_attempts.user_id', $activeMembers)
            ->get([
                'club_competition_attempts.user_id',
                'club_competition_attempts.challenge_pack_id',
                'club_competition_attempts.total_score',
                'club_competition_attempts.status',
                'club_competition_attempts.last_scored_at',
                'club_competition_attempts.started_at',
            ]);

        // user => pack => [best, reached_at(ts)]; user => attempts; user => completed packs
        $best      = [];
        $attempts  = [];
        $completed = [];

        $fold = function (int $userId, int $packId, int $score, ?string $at, bool $isCompleted) use (&$best, &$attempts, &$completed) {
            $ts = $at !== null ? Carbon::parse($at)->getTimestamp() : PHP_INT_MAX;
            $attempts[$userId] = ($attempts[$userId] ?? 0) + 1;
            if ($isCompleted) {
                $completed[$userId][$packId] = true;
            }
            $current = $best[$userId][$packId] ?? null;
            if ($current === null || $score > $current[0] || ($score === $current[0] && $ts < $current[1])) {
                $best[$userId][$packId] = [$score, $ts];
            }
        };

        foreach ($normal as $row) {
            $fold((int) $row->user_id, (int) $row->challenge_pack_id, (int) $row->total_score,
                $row->completed_at ?? $row->updated_at, $row->status === 'completed');
        }
        foreach ($competition as $row) {
            $fold((int) $row->user_id, (int) $row->challenge_pack_id, (int) $row->total_score,
                $row->last_scored_at ?? $row->started_at, $row->status === CompetitionAttemptStatus::Completed->value);
        }

        $entries = [];
        foreach ($best as $userId => $packs) {
            $user = $members->get($userId);
            if ($user === null) {
                continue;
            }
            $entries[] = [
                'user_id'          => $userId,
                'username'         => $user->username,
                'avatar_url'       => $user->avatar_path ? asset('storage/' . $user->avatar_path) : null,
                'total_score'      => array_sum(array_column($packs, 0)),
                'packs_completed'  => count($completed[$userId] ?? []),
                'attempts_used'    => $attempts[$userId] ?? 0,
                'reached_ts'       => max(array_column($packs, 1)),
            ];
        }

        usort($entries, fn ($a, $b) => [$b['total_score'], $a['reached_ts'], $a['attempts_used'], mb_strtolower((string) $a['username']), $a['user_id']]
            <=> [$a['total_score'], $b['reached_ts'], $b['attempts_used'], mb_strtolower((string) $b['username']), $b['user_id']]);

        $rank = 0;
        $prev = null;
        foreach ($entries as $i => &$entry) {
            $key = [$entry['total_score'], $entry['reached_ts'], $entry['attempts_used']];
            if ($key !== $prev) {
                $rank = $i + 1;
                $prev = $key;
            }
            $entry['rank'] = $rank;
            $entry['is_current_user'] = $viewer !== null && $entry['user_id'] === $viewer->id;
        }
        unset($entry);

        return collect($entries);
    }

    /**
     * GET /api/clubs/{club}/leaderboard.
     *
     * @return array{data: list<array>, meta: array}
     */
    public function page(Club $club, User $viewer, ?string $cursor, mixed $limit): array
    {
        $entries = $this->standings($club, $viewer);
        $perPage = ClubCursor::perPage($limit);
        $offset  = ClubCursor::decodeOffset($cursor);

        $slice = $entries->slice($offset, $perPage)->values();
        $next  = $offset + $perPage < $entries->count() ? ClubCursor::encodeOffset($offset + $perPage) : null;

        return [
            'data' => $slice->map(fn ($e) => self::present($e))->all(),
            'meta' => $this->meta($entries, $viewer) + ['next_cursor' => $next, 'per_page' => $perPage],
        ];
    }

    /** leaderboard_preview of the club detail: top 3 + the viewer's own row. */
    public function preview(Club $club, User $viewer): array
    {
        $entries = $this->standings($club, $viewer);
        $me      = $entries->firstWhere('user_id', $viewer->id);

        return [
            'top' => $entries->take(self::TOP_SIZE)->map(fn ($e) => self::present($e))->values()->all(),
            'me'  => $me ? self::present($me) : null,
        ];
    }

    /** LeaderboardMeta (same rules as the daily/weekly boards). */
    public function meta(Collection $entries, User $viewer): array
    {
        $total = $entries->count();
        $top   = $entries->take(self::TOP_SIZE)->map(fn ($e) => self::present($e))->values()->all();
        $index = $entries->search(fn ($e) => $e['user_id'] === $viewer->id);

        if ($index === false) {
            return [
                'total_players'          => $total,
                'current_user_rank'      => null,
                'current_user_score'     => null,
                'better_than_percentage' => null,
                'top_users'              => $top,
                'nearby_users'           => [],
            ];
        }

        $me   = $entries[$index];
        $rank = $me['rank'];

        return [
            'total_players'          => $total,
            'current_user_rank'      => $rank,
            'current_user_score'     => $me['total_score'],
            'better_than_percentage' => $total > 1 ? (int) round(($total - $rank) / $total * 100) : 0,
            'top_users'              => $top,
            // One above, the viewer, one below.
            'nearby_users'           => $entries->slice(max(0, $index - 1), $index === 0 ? 2 : 3)
                ->map(fn ($e) => self::present($e))->values()->all(),
        ];
    }

    /** Public ClubLeaderboardEntry shape (usernames + avatars only, D12). */
    public static function present(array $entry): array
    {
        return [
            'rank'            => $entry['rank'],
            'user_id'         => $entry['user_id'],
            'username'        => $entry['username'],
            'avatar_url'      => $entry['avatar_url'],
            'total_score'     => $entry['total_score'],
            'packs_completed' => $entry['packs_completed'],
            'attempts_used'   => $entry['attempts_used'],
            'is_current_user' => $entry['is_current_user'],
        ];
    }
}
