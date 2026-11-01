<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeaderboardEntryResource;
use App\Models\League;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaderboardController extends Controller
{
    public function index(Request $request, League $league)
    {
        if (!$league->members()->where('user_id', $request->user()->id)->exists()) {
            return response()->json(['message' => __('messages.tournaments.not_member')], 403);
        }

        $currentUserId = $request->user()->id;

        $entries = DB::table('guesses')
            ->join('league_rounds', 'guesses.league_round_id', '=', 'league_rounds.id')
            ->join('users', 'guesses.user_id', '=', 'users.id')
            ->where('league_rounds.league_id', $league->id)
            // Deleted accounts keep their rows for history, never a rank.
            ->whereNull('users.anonymized_at')
            ->select(
                'users.id as user_id',
                'users.username',
                DB::raw('SUM(guesses.score) as total_score'),
                DB::raw('COUNT(guesses.id) as guesses_count'),
                DB::raw('ROUND(AVG(guesses.score), 1) as avg_score')
            )
            ->groupBy('users.id', 'users.username')
            // Same order as TournamentCompletionService::calculateStandings, so the
            // live board never disagrees with the final placement on a tie.
            ->orderByDesc('total_score')
            ->orderByRaw('MAX(guesses.submitted_at) ASC')
            ->orderBy('users.id')
            // Tournaments are capped at 8 players; this is a pure safety net.
            ->limit(100)
            ->get()
            // The real name is not even selected. `meta` re-serializes these rows,
            // so the public identity has to be set here, not in the resource.
            //
            // SUM() and AVG() are DECIMAL on MariaDB/MySQL, which PDO returns as
            // strings ("100"); SQLite returns numbers. The API contract is numeric
            // on every database, and the app hides a score that is not a number.
            ->map(fn($row, $i) => array_merge((array) $row, [
                'user_id' => (int) $row->user_id,
                'username' => $publicName = User::publicNameFor($row->user_id, $row->username),
                'name' => $publicName,
                'total_score' => (int) $row->total_score,
                'guesses_count' => (int) $row->guesses_count,
                'avg_score' => round((float) $row->avg_score, 1),
                'rank' => $i + 1,
                'is_current_user' => (int) $row->user_id === $currentUserId,
            ]));

        return LeaderboardEntryResource::collection($entries)
            ->additional(['meta' => $this->buildMeta($entries, $currentUserId)]);
    }

    /**
     * "You are #X of Y / better than Z%" metadata for a ranked (sorted) collection.
     */
    private function buildMeta($entries, int $userId): array
    {
        $total = $entries->count();
        $me    = $entries->firstWhere('user_id', $userId);

        if (!$me) {
            return [
                'total_players'          => $total,
                'current_user_rank'      => null,
                'current_user_score'     => null,
                'current_user_average'   => null,
                'better_than_percentage' => null,
                'top_users'              => $entries->take(3)->values(),
                'nearby_users'           => [],
            ];
        }

        $rank       = $me['rank'];
        $betterThan = $total > 1 ? (int) round(($total - $rank) / $total * 100) : 0;
        $start      = max(0, $rank - 2);

        return [
            'total_players'          => $total,
            'current_user_rank'      => $rank,
            'current_user_score'     => $me['total_score'] ?? null,
            'current_user_average'   => $me['avg_score'] ?? null,
            'better_than_percentage' => $betterThan,
            'top_users'              => $entries->take(3)->values(),
            'nearby_users'           => $entries->slice($start, 3)->values(),
        ];
    }
}
