<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\DailyChallengeGuess;
use App\Models\FriendRequest;
use App\Models\Guess;
use App\Models\User;
use App\Services\PlayerRankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only public view of another player. Deliberately hand-built rather than
 * reusing UserResource: every field here is an explicit allow-list decision.
 */
class PublicProfileController extends Controller
{
    public function __construct(private PlayerRankService $rankService) {}

    public function show(Request $request, User $user): JsonResponse
    {
        // Deleted accounts keep their row (anonymized) but must not stay
        // browsable as a profile.
        if ($user->anonymized_at !== null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $viewer = $request->user();
        $rank   = $this->rankService->forUser($user);

        $guessAgg = Guess::where('user_id', $user->id)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(score), 0) as total_score, AVG(score) as avg_score')
            ->first();
        $guessesCount = (int) $guessAgg->total;

        $dailyAgg = DailyChallengeGuess::where('user_id', $user->id)
            ->selectRaw('COUNT(*) as total, MAX(score) as best_score')
            ->first();

        $pending = FriendRequest::where('status', FriendRequest::STATUS_PENDING)
            ->where(function ($q) use ($viewer, $user) {
                $q->where(fn ($w) => $w->where('requester_id', $viewer->id)->where('recipient_id', $user->id))
                  ->orWhere(fn ($w) => $w->where('requester_id', $user->id)->where('recipient_id', $viewer->id));
            })
            ->exists();

        return response()->json([
            'data' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'username'   => $user->username,
                'avatar_url' => $user->avatarUrl(),
                'rank'       => $rank,
                'total_xp'   => $rank['total_xp'],
                'stats'      => [
                    'tournaments_played'      => $user->leagues()->count(),
                    'tournaments_completed'   => $user->leagues()->where('leagues.status', 'completed')->count(),
                    'guesses_count'           => $guessesCount,
                    'total_score'             => (int) $guessAgg->total_score,
                    'average_score'           => $guessesCount > 0 ? round((float) $guessAgg->avg_score, 1) : 0.0,
                    'daily_challenges_played' => (int) $dailyAgg->total,
                    'best_daily_score'        => (int) ($dailyAgg->best_score ?? 0),
                ],
                'badges' => [
                    'earned_count' => $user->badges()->count(),
                    'total_count'  => Badge::count(),
                ],
                'is_friend'           => $viewer->isFriendsWith($user),
                'has_pending_request' => $pending,
            ],
        ]);
    }
}
