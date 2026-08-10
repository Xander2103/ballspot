<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLeagueRequest;
use App\Http\Resources\LeagueResource;
use App\Http\Resources\LeagueRoundResource;
use App\Models\Challenge;
use App\Models\Guess;
use App\Models\League;
use App\Models\User;
use App\Services\LeagueService;
use Illuminate\Http\Request;

class LeagueController extends Controller
{
    public function __construct(private LeagueService $leagueService) {}

    public function index(Request $request)
    {
        $leagues = $request->user()
            ->leagues()
            ->where('status', '!=', 'cancelled')
            // Completed tournaments the user chose to remove from their list.
            // History (tournament_finishes) is unaffected.
            ->wherePivotNull('hidden_at')
            ->with(['rounds', 'members', 'sport'])
            ->get();

        return LeagueResource::collection($leagues);
    }

    /**
     * POST /leagues/{league}/hide — remove a FINISHED tournament from this
     * user's own list. Nothing is deleted: the membership row stays, and so do
     * guesses, scores, leaderboard rows, XP, badges and tournament_finishes.
     */
    public function hide(Request $request, League $league)
    {
        $userId = $request->user()->id;

        if (!$league->members()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Not a member of this league'], 403);
        }
        if ($league->status !== 'completed') {
            return response()->json(['message' => 'Only finished tournaments can be removed from your list.'], 422);
        }

        // Idempotent: hiding an already-hidden tournament is a no-op success.
        $league->members()->updateExistingPivot($userId, ['hidden_at' => now()]);

        return response()->noContent();
    }

    public function store(CreateLeagueRequest $request)
    {
        $league = $this->leagueService->create($request->validated(), $request->user()->id);

        // v1.8.6 Host Starter — first tournament created. Silent award.
        app(\App\Services\BadgeService::class)->evaluateTournamentCreated($request->user());

        return new LeagueResource($league->load('members', 'sport'));
    }

    public function join(Request $request)
    {
        $request->validate(['join_code' => ['required', 'string', 'size:6']]);
        $league = $this->leagueService->join($request->join_code, $request->user()->id);
        return new LeagueResource($league->load('members', 'sport'));
    }

    public function show(Request $request, League $league)
    {
        if (!$league->members()->where('user_id', $request->user()->id)->exists()) {
            return response()->json(['message' => 'Not a member of this league'], 403);
        }
        return new LeagueResource($league->load('members', 'sport'));
    }

    public function start(Request $request, League $league)
    {
        $userId = $request->user()->id;

        if (!$league->members()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Not a member of this league'], 403);
        }
        if ((int) $league->owner_user_id !== (int) $userId) {
            return response()->json(['message' => 'Only the owner can start this tournament.'], 403);
        }
        if ($league->status !== 'lobby') {
            return response()->json(['message' => 'Tournament can only be started from lobby status.'], 422);
        }

        $sport = $league->sport;
        $hasChallenges = Challenge::where('status', 'active')
            ->where('sport_id', $sport->id)
            ->exists();

        if (!$hasChallenges) {
            return response()->json(
                ['message' => "No active {$sport->name} challenges available. Add challenges in admin."],
                422
            );
        }

        $league = $this->leagueService->start($league, $userId);
        return new LeagueResource($league->load('members', 'sport'));
    }

    public function destroy(Request $request, League $league)
    {
        $userId = $request->user()->id;

        if (!$league->members()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Not a member of this league'], 403);
        }
        if ((int) $league->owner_user_id !== (int) $userId) {
            return response()->json(['message' => 'Only the owner can cancel this tournament.'], 403);
        }

        $this->leagueService->cancel($league, $userId);
        return response()->noContent();
    }

    public function removeMember(Request $request, League $league, User $user)
    {
        $requesterId = $request->user()->id;

        if (!$league->members()->where('user_id', $requesterId)->exists()) {
            return response()->json(['message' => 'Not a member of this league'], 403);
        }
        if ((int) $league->owner_user_id !== (int) $requesterId) {
            return response()->json(['message' => 'Only the owner can remove players.'], 403);
        }
        if ($league->status !== 'lobby') {
            return response()->json(['message' => 'Players can only be removed while the tournament is in lobby.'], 422);
        }
        if ((int) $user->id === (int) $league->owner_user_id) {
            return response()->json(['message' => 'The owner cannot be removed.'], 422);
        }

        $league->members()->detach($user->id);
        return response()->noContent();
    }

    public function currentRound(Request $request, League $league)
    {
        $userId = $request->user()->id;
        if (!$league->members()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Not a member of this league'], 403);
        }

        $totalRounds = $league->rounds()->where('status', 'open')->count();
        $completedRounds = $league->rounds()
            ->where('status', 'open')
            ->whereHas('guesses', fn($q) => $q->where('user_id', $userId))
            ->count();
        $remaining = max(0, $totalRounds - $completedRounds);
        $pct = $totalRounds > 0 ? (int) round($completedRounds / $totalRounds * 100) : 0;

        $progress = [
            'completed' => $completedRounds,
            'total'     => $totalRounds,
            'remaining' => $remaining,
            'pct'       => $pct,
        ];

        $today = now()->toDateString();
        $playedToday = Guess::whereHas('round', fn($q) => $q->where('league_id', $league->id))
            ->where('user_id', $userId)
            ->whereDate('submitted_at', $today)
            ->count();

        if ($playedToday >= $league->rounds_per_day) {
            return response()->json([
                'current_round'    => null,
                'has_current_round'=> false,
                'completed'        => false,
                'reason'           => 'daily_limit_reached',
                'message'          => 'You have played all rounds available for today.',
                'next_available_at'=> null,
                'progress'         => $progress,
                'rounds_per_day'   => $league->rounds_per_day,
                'played_today_count'=> $playedToday,
                'remaining_today_count' => max(0, $league->rounds_per_day - $playedToday),
            ]);
        }

        $round = $league->rounds()
            ->where('status', 'open')
            ->whereDoesntHave('guesses', fn($q) => $q->where('user_id', $userId))
            ->orderBy('round_number')
            ->with('challenge.category')
            ->first();

        if (!$round) {
            return response()->json([
                'current_round'     => null,
                'has_current_round' => false,
                'completed'         => true,
                'reason'            => 'all_rounds_complete',
                'progress'          => $progress,
                'rounds_per_day'    => $league->rounds_per_day,
                'played_today_count'=> $playedToday,
                'remaining_today_count' => max(0, $league->rounds_per_day - $playedToday),
            ]);
        }

        return response()->json([
            'current_round'     => new LeagueRoundResource($round),
            'has_current_round' => true,
            'completed'         => false,
            'reason'            => 'has_pending_round',
            'progress'          => $progress,
            'rounds_per_day'    => $league->rounds_per_day,
            'played_today_count'=> $playedToday,
            'remaining_today_count' => max(0, $league->rounds_per_day - $playedToday),
        ]);
    }
}
