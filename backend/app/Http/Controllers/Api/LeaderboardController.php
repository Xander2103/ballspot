<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeaderboardEntryResource;
use App\Models\League;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaderboardController extends Controller
{
    public function index(Request $request, League $league)
    {
        if (!$league->members()->where('user_id', $request->user()->id)->exists()) {
            return response()->json(['message' => 'Not a member of this league'], 403);
        }

        $currentUserId = $request->user()->id;

        $entries = DB::table('guesses')
            ->join('league_rounds', 'guesses.league_round_id', '=', 'league_rounds.id')
            ->join('users', 'guesses.user_id', '=', 'users.id')
            ->where('league_rounds.league_id', $league->id)
            ->select(
                'users.id as user_id',
                'users.username',
                'users.name',
                DB::raw('SUM(guesses.score) as total_score'),
                DB::raw('COUNT(guesses.id) as guesses_count'),
                DB::raw('ROUND(AVG(guesses.score), 1) as avg_score')
            )
            ->groupBy('users.id', 'users.username', 'users.name')
            ->orderByDesc('total_score')
            ->get()
            ->map(fn($row, $i) => array_merge((array) $row, [
                'rank' => $i + 1,
                'is_current_user' => (int) $row->user_id === $currentUserId,
            ]));

        return LeaderboardEntryResource::collection($entries);
    }
}
