<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guess;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function stats(Request $request)
    {
        $user = $request->user();

        $tournamentsCount = $user->leagues()->count();
        $completedCount   = $user->leagues()->where('status', 'completed')->count();
        $guessesCount     = Guess::where('user_id', $user->id)->count();
        $totalScore       = (int) (Guess::where('user_id', $user->id)->sum('score') ?? 0);
        $avgScore         = $guessesCount > 0
            ? round((float) Guess::where('user_id', $user->id)->avg('score'), 1)
            : 0.0;

        return response()->json([
            'tournaments_count'           => $tournamentsCount,
            'completed_tournaments_count' => $completedCount,
            'guesses_count'               => $guessesCount,
            'total_score'                 => $totalScore,
            'average_score'               => $avgScore,
        ]);
    }
}
