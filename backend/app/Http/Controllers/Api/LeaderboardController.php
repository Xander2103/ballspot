<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeaderboardEntryResource;
use App\Models\League;
use Illuminate\Support\Facades\DB;

class LeaderboardController extends Controller
{
    public function index(League $league)
    {
        $entries = DB::table('guesses')
            ->join('league_rounds', 'guesses.league_round_id', '=', 'league_rounds.id')
            ->join('users', 'guesses.user_id', '=', 'users.id')
            ->where('league_rounds.league_id', $league->id)
            ->select('users.id as user_id', 'users.username', 'users.name',
                DB::raw('SUM(guesses.score) as total_score'),
                DB::raw('COUNT(guesses.id) as guesses_count'))
            ->groupBy('users.id', 'users.username', 'users.name')
            ->orderByDesc('total_score')
            ->get()
            ->map(fn($row, $i) => array_merge((array) $row, ['rank' => $i + 1]));

        return LeaderboardEntryResource::collection($entries);
    }
}
