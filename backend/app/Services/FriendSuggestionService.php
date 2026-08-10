<?php

namespace App\Services;

use App\Models\DailyChallengeGuess;
use App\Models\FriendRequest;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cheap, index-backed friend suggestions. v1 signals, in preference order:
 *   1. same tournament (league_members self-join — both directions indexed)
 *   2. recently active players (daily_challenge_guesses in the last 14 days)
 *
 * Excludes: self, existing friends, users with a pending request in either
 * direction, recently-rejected pairs (mirrors FriendController's 30-day
 * cooldown), and anonymized (deleted) accounts.
 */
class FriendSuggestionService
{
    public const MAX_SUGGESTIONS = 10;

    /** Sliding window for the "active player" fallback signal. */
    private const ACTIVE_DAYS = 14;

    /** Per-signal candidate pool size before visibility filtering. */
    private const CANDIDATE_POOL = 50;

    /**
     * @return array<int, array{user: User, reason: string}>
     */
    public function forUser(User $user, int $limit = self::MAX_SUGGESTIONS): array
    {
        $excludedIds = $this->excludedIds($user);

        $peerIds = DB::table('league_members as mine')
            ->join('league_members as theirs', 'mine.league_id', '=', 'theirs.league_id')
            ->where('mine.user_id', $user->id)
            ->where('theirs.user_id', '!=', $user->id)
            ->groupBy('theirs.user_id')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit(self::CANDIDATE_POOL)
            ->pluck('theirs.user_id');

        $rows = [];
        foreach ($this->visibleUsers($peerIds, $excludedIds, $limit) as $peer) {
            $rows[] = ['user' => $peer, 'reason' => 'same_tournament'];
            $excludedIds[] = $peer->id;
        }

        if (count($rows) < $limit) {
            $activeIds = DailyChallengeGuess::query()
                ->where('submitted_at', '>=', now()->subDays(self::ACTIVE_DAYS))
                ->groupBy('user_id')
                ->orderByDesc(DB::raw('MAX(submitted_at)'))
                ->limit(self::CANDIDATE_POOL)
                ->pluck('user_id');

            foreach ($this->visibleUsers($activeIds, $excludedIds, $limit - count($rows)) as $peer) {
                $rows[] = ['user' => $peer, 'reason' => 'active_player'];
            }
        }

        return $rows;
    }

    /** @return array<int, int> user ids that must never be suggested */
    private function excludedIds(User $user): array
    {
        $friendIds = Friendship::where('user_id', $user->id)->pluck('friend_id');

        $requestPairs = FriendRequest::query()
            ->where(fn ($q) => $q->where('requester_id', $user->id)->orWhere('recipient_id', $user->id))
            ->where(fn ($q) => $q->where('status', FriendRequest::STATUS_PENDING)
                ->orWhere(fn ($qq) => $qq->where('status', FriendRequest::STATUS_REJECTED)
                    ->where('updated_at', '>', now()->subDays(\App\Http\Controllers\Api\FriendController::REJECTED_COOLDOWN_DAYS))))
            ->get(['requester_id', 'recipient_id']);

        return $friendIds
            ->merge($requestPairs->pluck('requester_id'))
            ->merge($requestPairs->pluck('recipient_id'))
            ->push($user->id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Candidate ids -> visible User models, preserving the signal's ordering.
     *
     * @param  Collection<int, int|string>  $candidateIds
     * @param  array<int, int>  $excludedIds
     * @return Collection<int, User>
     */
    private function visibleUsers(Collection $candidateIds, array $excludedIds, int $limit): Collection
    {
        if ($limit <= 0 || $candidateIds->isEmpty()) {
            return collect();
        }

        $order = $candidateIds->map(fn ($id) => (int) $id)->values();

        return User::query()
            ->whereIn('id', $order)
            ->whereNotIn('id', $excludedIds)
            ->whereNull('anonymized_at')
            ->whereNotNull('friend_code')
            ->get()
            ->sortBy(fn (User $u) => $order->search($u->id))
            ->take($limit)
            ->values();
    }
}
