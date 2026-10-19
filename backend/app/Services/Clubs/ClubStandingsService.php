<?php

namespace App\Services\Clubs;

use App\Enums\ClubMemberStatus;
use App\Enums\CompetitionAttemptStatus;
use App\Enums\ScoreRule;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionEntry;
use App\Models\ClubCompetitionPackResult;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Competition standings (plan §5.4–5.6, §5.10). Attempts and guesses are the
 * source of truth; attempt totals, pack results and entries are DERIVED and
 * always recomputed from them by the same code path — live (inside every
 * guess / void / expiry transaction, for one player) and in a rebuild (for
 * every player). That is what makes "incremental == rebuild" hold.
 *
 * Per attempt: total_score / last_scored_at = sum / latest of its guesses
 * with starts_at ≤ submitted_at < ends_at (in-window only; partial attempts
 * count what they scored, D16).
 *
 * Per (player, pack), over NON-voided attempts:
 *  - first: the lowest attempt_number counts;
 *  - best:  the highest score counts; equal scores → the attempt that reached
 *           it first (earlier last_scored_at; never scored = last), then the
 *           lower attempt_number — a later equal attempt never improves the
 *           tie-break;
 *  - score_reached_at = last_scored_at of the counted attempt;
 *  - attempts_used = non-voided attempts; completed = any completed attempt.
 *
 * Per player (entry, only once they have ≥ 1 in-window guess):
 * total_score = Σ counted_score, score_reached_at = latest counted
 * score_reached_at, packs_counted, packs_completed, attempts_used.
 *
 * Ranking (D17): total_score desc → score_reached_at asc (null = last) →
 * attempts_used asc; equal on all three = shared rank (1, 2, 2, 4). Display
 * order inside a shared rank: username (case-insensitive), then user id.
 * Only ACTIVE members of the club are ranked (D22): left / removed / banned
 * players are hidden, their rows are kept.
 */
class ClubStandingsService
{
    public const PREVIEW_SIZE = 5;
    public const TOP_SIZE     = 3;

    private const TS = 'Y-m-d H:i:s';

    // ------------------------------------------------------------------
    // Recompute (write)
    // ------------------------------------------------------------------

    /**
     * Recompute one player's attempt totals, pack results and entry from the
     * source rows and persist them; then refresh participants_count. Call it
     * inside the transaction of the change (guess, void, expiry).
     */
    public function recomputePlayer(ClubCompetition $competition, int $userId): ?ClubCompetitionEntry
    {
        $computed = $this->computePlayer($competition, $userId);
        $this->persistPlayer($competition, $userId, $computed);
        $this->refreshParticipants($competition);

        return ClubCompetitionEntry::where('club_competition_id', $competition->id)->where('user_id', $userId)->first();
    }

    /**
     * Rebuild every player of a competition from the source rows.
     * Returns the diff between the stored derived rows and the recomputed
     * ones; with $dryRun nothing is written.
     *
     * @return array{players: int, changes: list<array>, participants: array{from: int, to: int}}
     */
    public function rebuild(ClubCompetition $competition, bool $dryRun = false): array
    {
        $userIds = ClubCompetitionAttempt::where('club_competition_id', $competition->id)->distinct()->pluck('user_id')
            ->merge(ClubCompetitionEntry::where('club_competition_id', $competition->id)->pluck('user_id'))
            ->merge(ClubCompetitionPackResult::where('club_competition_id', $competition->id)->pluck('user_id'))
            ->map(fn ($id) => (int) $id)->unique()->sort()->values();

        $changes = [];
        $run = function () use ($competition, $userIds, $dryRun, &$changes) {
            foreach ($userIds as $userId) {
                $computed = $this->computePlayer($competition, $userId);
                $changes  = array_merge($changes, $this->diffPlayer($competition, $userId, $computed));
                if (!$dryRun) {
                    $this->persistPlayer($competition, $userId, $computed);
                }
            }
        };

        $dryRun ? $run() : DB::transaction($run);

        $before = (int) $competition->participants_count;
        $after  = ClubCompetitionEntry::where('club_competition_id', $competition->id)->count();
        if (!$dryRun) {
            $this->refreshParticipants($competition);
        }
        if ($before !== $after) {
            $changes[] = ['type' => 'competition', 'user_id' => null, 'pack_id' => null, 'field' => 'participants_count', 'from' => $before, 'to' => $after];
        }

        return ['players' => $userIds->count(), 'changes' => $changes, 'participants' => ['from' => $before, 'to' => $after]];
    }

    public function refreshParticipants(ClubCompetition $competition): void
    {
        $count = ClubCompetitionEntry::where('club_competition_id', $competition->id)->count();
        if ((int) $competition->participants_count !== $count) {
            DB::table('club_competitions')->where('id', $competition->id)->update(['participants_count' => $count]);
        }
        $competition->setAttribute('participants_count', $count);
        $competition->syncOriginalAttributes(['participants_count']);
    }

    /**
     * The derived values of one player, computed from attempts + in-window
     * guesses only (pure read).
     *
     * @return array{attempts: array<int, array{total_score: int, last_scored_at: ?string}>,
     *               packs: array<int, array>, entry: ?array}
     */
    public function computePlayer(ClubCompetition $competition, int $userId): array
    {
        $attempts = ClubCompetitionAttempt::where('club_competition_id', $competition->id)
            ->where('user_id', $userId)
            ->orderBy('challenge_pack_id')->orderBy('attempt_number')->orderBy('id')
            ->get(['id', 'challenge_pack_id', 'attempt_number', 'status']);

        $aggregates = $attempts->isEmpty() ? collect() : DB::table('club_competition_attempt_guesses')
            ->whereIn('attempt_id', $attempts->pluck('id'))
            ->where('submitted_at', '>=', $competition->starts_at)
            ->where('submitted_at', '<', $competition->ends_at)
            ->groupBy('attempt_id')
            ->selectRaw('attempt_id, SUM(score) as total, MAX(submitted_at) as last_at, COUNT(*) as n')
            ->get()
            ->keyBy('attempt_id');

        $out = ['attempts' => [], 'packs' => [], 'entry' => null];
        $byPack = [];
        foreach ($attempts as $attempt) {
            $agg   = $aggregates->get($attempt->id);
            $score = (int) ($agg->total ?? 0);
            $last  = isset($agg->last_at) ? self::norm($agg->last_at) : null;

            $out['attempts'][(int) $attempt->id] = ['total_score' => $score, 'last_scored_at' => $last];

            if ($attempt->status !== CompetitionAttemptStatus::Voided) {
                $byPack[(int) $attempt->challenge_pack_id][] = [
                    'id'        => (int) $attempt->id,
                    'number'    => (int) $attempt->attempt_number,
                    'score'     => $score,
                    'last_at'   => $last,
                    'guesses'   => (int) ($agg->n ?? 0),
                    'completed' => $attempt->status === CompetitionAttemptStatus::Completed,
                ];
            }
        }

        $best = $competition->score_rule === ScoreRule::Best && (int) $competition->max_attempts > 1;

        $hasGuesses = false;
        foreach ($byPack as $packId => $rows) {
            usort($rows, fn ($a, $b) => $a['number'] <=> $b['number']);
            $first = $rows[0];

            $counted = $first;
            if ($best) {
                $ranked = $rows;
                usort($ranked, fn ($a, $b) => [$b['score'], $a['last_at'] === null ? 1 : 0, $a['last_at'] ?? '', $a['number']]
                    <=> [$a['score'], $b['last_at'] === null ? 1 : 0, $b['last_at'] ?? '', $b['number']]);
                $counted = $ranked[0];
            }

            foreach ($rows as $r) {
                $hasGuesses = $hasGuesses || $r['guesses'] > 0;
            }

            $out['packs'][$packId] = [
                'attempts_used'      => count($rows),
                'counted_attempt_id' => $counted['id'],
                'counted_score'      => $counted['score'],
                'best_score'         => max(array_column($rows, 'score')),
                'first_score'        => $first['score'],
                'completed'          => (bool) array_filter($rows, fn ($r) => $r['completed']),
                'score_reached_at'   => $counted['last_at'],
                'counted_guesses'    => $counted['guesses'],
            ];
        }

        if ($hasGuesses) {
            $packs   = $out['packs'];
            $reached = array_values(array_filter(array_column($packs, 'score_reached_at')));
            $out['entry'] = [
                'total_score'      => array_sum(array_column($packs, 'counted_score')),
                'packs_counted'    => count(array_filter($packs, fn ($p) => $p['counted_guesses'] > 0)),
                'packs_completed'  => count(array_filter($packs, fn ($p) => $p['completed'])),
                'attempts_used'    => array_sum(array_column($packs, 'attempts_used')),
                'score_reached_at' => $reached === [] ? null : max($reached),
            ];
        }

        return $out;
    }

    private function persistPlayer(ClubCompetition $competition, int $userId, array $computed): void
    {
        $current = DB::table('club_competition_attempts')
            ->whereIn('id', array_keys($computed['attempts']) ?: [0])
            ->get(['id', 'total_score', 'last_scored_at'])
            ->keyBy('id');
        foreach ($computed['attempts'] as $id => $values) {
            $row = $current->get($id);
            if ($row && ((int) $row->total_score !== $values['total_score'] || self::norm($row->last_scored_at) !== $values['last_scored_at'])) {
                DB::table('club_competition_attempts')->where('id', $id)->update($values + ['updated_at' => now()]);
            }
        }

        $base = ['club_competition_id' => $competition->id, 'user_id' => $userId];

        ClubCompetitionPackResult::where($base)
            ->whereNotIn('challenge_pack_id', array_keys($computed['packs']) ?: [0])
            ->delete();
        foreach ($computed['packs'] as $packId => $values) {
            unset($values['counted_guesses']);
            $result = ClubCompetitionPackResult::firstOrNew($base + ['challenge_pack_id' => $packId]);
            $result->fill($values);
            if ($result->isDirty()) {
                $result->save();
            }
        }

        if ($computed['entry'] === null) {
            ClubCompetitionEntry::where($base)->delete();

            return;
        }
        $entry = ClubCompetitionEntry::firstOrNew($base);
        $entry->fill($computed['entry']);
        if ($entry->isDirty()) {
            $entry->save();
        }
    }

    /** Stored vs computed rows of one player, field by field. */
    private function diffPlayer(ClubCompetition $competition, int $userId, array $computed): array
    {
        $changes = [];
        $add = function (string $type, ?int $packId, string $field, $from, $to) use (&$changes, $userId) {
            $changes[] = ['type' => $type, 'user_id' => $userId, 'pack_id' => $packId, 'field' => $field, 'from' => $from, 'to' => $to];
        };

        $attempts = DB::table('club_competition_attempts')
            ->where('club_competition_id', $competition->id)->where('user_id', $userId)
            ->get(['id', 'challenge_pack_id', 'total_score', 'last_scored_at'])->keyBy('id');
        foreach ($computed['attempts'] as $id => $values) {
            $row = $attempts->get($id);
            if ((int) $row->total_score !== $values['total_score']) {
                $add('attempt', (int) $row->challenge_pack_id, "attempt#{$id}.total_score", (int) $row->total_score, $values['total_score']);
            }
            if (self::norm($row->last_scored_at) !== $values['last_scored_at']) {
                $add('attempt', (int) $row->challenge_pack_id, "attempt#{$id}.last_scored_at", self::norm($row->last_scored_at), $values['last_scored_at']);
            }
        }

        $stored = DB::table('club_competition_pack_results')
            ->where('club_competition_id', $competition->id)->where('user_id', $userId)
            ->get()->keyBy('challenge_pack_id');
        $fields = ['attempts_used', 'counted_attempt_id', 'counted_score', 'best_score', 'first_score', 'completed', 'score_reached_at'];
        foreach ($computed['packs'] as $packId => $values) {
            $row = $stored->get($packId);
            if ($row === null) {
                $add('pack_result', $packId, 'row', null, 'created');
                continue;
            }
            foreach ($fields as $field) {
                $from = self::cast($field, $row->{$field});
                if ($from !== $values[$field]) {
                    $add('pack_result', $packId, $field, $from, $values[$field]);
                }
            }
        }
        foreach ($stored as $packId => $row) {
            if (!isset($computed['packs'][(int) $packId])) {
                $add('pack_result', (int) $packId, 'row', 'exists', null);
            }
        }

        $entry = DB::table('club_competition_entries')
            ->where('club_competition_id', $competition->id)->where('user_id', $userId)->first();
        if ($computed['entry'] === null) {
            if ($entry !== null) {
                $add('entry', null, 'row', 'exists', null);
            }
        } elseif ($entry === null) {
            $add('entry', null, 'row', null, 'created');
        } else {
            foreach ($computed['entry'] as $field => $to) {
                $from = self::cast($field, $entry->{$field});
                if ($from !== $to) {
                    $add('entry', null, $field, $from, $to);
                }
            }
        }

        return $changes;
    }

    // ------------------------------------------------------------------
    // Reads (ranking)
    // ------------------------------------------------------------------

    /**
     * Every visible (active member) entry, best first, with shared ranks.
     * Bounded by the club's member cap, so ranking happens in memory.
     *
     * @return Collection<int, array{rank: int, user_id: int, username: string, avatar_url: ?string, total_score: int,
     *                                packs_completed: int, packs_counted: int, attempts_used: int, score_reached_at: ?string,
     *                                is_current_user: bool}>
     */
    public function ranked(ClubCompetition $competition, ?User $viewer = null): Collection
    {
        $rows = DB::table('club_competition_entries as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->whereIn('e.user_id', $this->activeMembers($competition))
            ->whereNull('u.anonymized_at')
            ->where('e.club_competition_id', $competition->id)
            ->get(['e.user_id', 'e.total_score', 'e.packs_counted', 'e.packs_completed', 'e.attempts_used', 'e.score_reached_at',
                'u.username', 'u.avatar_path']);

        $entries = $rows->map(fn ($r) => [
            'user_id'          => (int) $r->user_id,
            'username'         => (string) $r->username,
            'avatar_url'       => $r->avatar_path ? asset('storage/' . $r->avatar_path) : null,
            'total_score'      => (int) $r->total_score,
            'packs_completed'  => (int) $r->packs_completed,
            'packs_counted'    => (int) $r->packs_counted,
            'attempts_used'    => (int) $r->attempts_used,
            'score_reached_at' => self::norm($r->score_reached_at),
        ])->all();

        usort($entries, fn ($a, $b) => self::sortKey($a) <=> self::sortKey($b));

        $rank = 0;
        $prev = null;
        foreach ($entries as $i => &$entry) {
            $key = self::rankKey($entry);
            if ($key !== $prev) {
                $rank = $i + 1;
                $prev = $key;
            }
            $entry['rank']            = $rank;
            $entry['is_current_user'] = $viewer !== null && $entry['user_id'] === $viewer->id;
        }
        unset($entry);

        return collect($entries);
    }

    /**
     * Rank of one entry among the visible entries: 1 + COUNT(strictly better)
     * on the ranking index (portable SQL, no window functions). Null when the
     * player is not ranked (no entry, or hidden by D22).
     */
    public function rankOf(ClubCompetition $competition, ?ClubCompetitionEntry $entry): ?int
    {
        if ($entry === null) {
            return null;
        }
        $visible = DB::table('club_members')
            ->where('club_id', $competition->club_id)
            ->where('user_id', $entry->user_id)
            ->where('status', ClubMemberStatus::Active->value)
            ->exists();
        if (!$visible) {
            return null;
        }

        $total   = (int) $entry->total_score;
        $reached = self::norm($entry->getRawOriginal('score_reached_at'));
        $used    = (int) $entry->attempts_used;

        $e = 'club_competition_entries';
        $better = DB::table($e)
            ->join('users', 'users.id', '=', "{$e}.user_id")
            ->whereNull('users.anonymized_at')
            ->where("{$e}.club_competition_id", $competition->id)
            ->whereIn("{$e}.user_id", $this->activeMembers($competition))
            ->where(function ($q) use ($e, $total, $reached, $used) {
                $q->where("{$e}.total_score", '>', $total)->orWhere(function ($tie) use ($e, $total, $reached, $used) {
                    $tie->where("{$e}.total_score", $total);
                    if ($reached === null) {
                        $tie->where(fn ($t) => $t->whereNotNull("{$e}.score_reached_at")
                            ->orWhere(fn ($tt) => $tt->whereNull("{$e}.score_reached_at")->where("{$e}.attempts_used", '<', $used)));
                    } else {
                        $tie->where(fn ($t) => $t->where("{$e}.score_reached_at", '<', $reached)
                            ->orWhere(fn ($tt) => $tt->where("{$e}.score_reached_at", $reached)->where("{$e}.attempts_used", '<', $used)));
                    }
                });
            })
            ->count();

        return $better + 1;
    }

    /** The viewer's entry + rank, or null when not ranked. */
    public function myEntry(ClubCompetition $competition, User $user): ?array
    {
        $entry = ClubCompetitionEntry::where('club_competition_id', $competition->id)->where('user_id', $user->id)->first();
        $rank  = $this->rankOf($competition, $entry);
        if ($rank === null) {
            return null;
        }

        return [
            'rank'             => $rank,
            'total_score'      => (int) $entry->total_score,
            'packs_completed'  => (int) $entry->packs_completed,
            'attempts_used'    => (int) $entry->attempts_used,
            'score_reached_at' => $entry->score_reached_at?->toISOString(),
        ];
    }

    /**
     * CompetitionCard `me` ({rank, total_score, packs_completed}) for several
     * competitions at once.
     *
     * @param iterable<ClubCompetition> $competitions
     * @return array<int, array{rank: int, total_score: int, packs_completed: int}>
     */
    public function cardStandings(iterable $competitions, User $user): array
    {
        $byId = collect($competitions)->keyBy('id');
        if ($byId->isEmpty()) {
            return [];
        }

        $out = [];
        $entries = ClubCompetitionEntry::where('user_id', $user->id)->whereIn('club_competition_id', $byId->keys())->get();
        foreach ($entries as $entry) {
            $rank = $this->rankOf($byId[$entry->club_competition_id], $entry);
            if ($rank !== null) {
                $out[$entry->club_competition_id] = [
                    'rank'            => $rank,
                    'total_score'     => (int) $entry->total_score,
                    'packs_completed' => (int) $entry->packs_completed,
                ];
            }
        }

        return $out;
    }

    /**
     * GET …/competitions/{competition}/leaderboard page (offset cursor over the
     * in-memory ranking, like the club board).
     */
    public function page(ClubCompetition $competition, User $viewer, ?string $cursor, mixed $limit): array
    {
        $entries = $this->ranked($competition, $viewer);
        $perPage = ClubCursor::perPage($limit);
        $offset  = ClubCursor::decodeOffset($cursor);

        $slice = $entries->slice($offset, $perPage)->values();
        $next  = $offset + $perPage < $entries->count() ? ClubCursor::encodeOffset($offset + $perPage) : null;

        return [
            'data' => $slice->map(fn ($e) => ClubLeaderboardService::present($e))->all(),
            'meta' => app(ClubLeaderboardService::class)->meta($entries, $viewer) + ['next_cursor' => $next, 'per_page' => $perPage],
        ];
    }

    /** Top N entries (competition detail leaderboard_preview). */
    public function preview(ClubCompetition $competition, User $viewer, int $size = self::PREVIEW_SIZE): array
    {
        return $this->ranked($competition, $viewer)->take($size)->map(fn ($e) => ClubLeaderboardService::present($e))->values()->all();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function activeMembers(ClubCompetition $competition): \Closure
    {
        return fn ($q) => $q->select('user_id')->from('club_members')
            ->where('club_id', $competition->club_id)
            ->where('status', ClubMemberStatus::Active->value);
    }

    /** Ranking factors (D17) — equal key = shared rank. */
    private static function rankKey(array $e): array
    {
        return [-$e['total_score'], $e['score_reached_at'] === null ? 1 : 0, $e['score_reached_at'] ?? '', $e['attempts_used']];
    }

    /** Ranking factors + deterministic display order inside a shared rank. */
    private static function sortKey(array $e): array
    {
        return array_merge(self::rankKey($e), [mb_strtolower($e['username']), $e['user_id']]);
    }

    /** Normalised 'Y-m-d H:i:s' (UTC, as stored) or null. */
    public static function norm(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->utc()->format(self::TS);
        }

        return Carbon::parse((string) $value, 'UTC')->format(self::TS);
    }

    private static function cast(string $field, mixed $value): mixed
    {
        return match ($field) {
            'score_reached_at'                 => self::norm($value),
            'completed'                        => (bool) $value,
            'best_score', 'first_score', 'counted_attempt_id' => $value === null ? null : (int) $value,
            default                            => (int) $value,
        };
    }
}
