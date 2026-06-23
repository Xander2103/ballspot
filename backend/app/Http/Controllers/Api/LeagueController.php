<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLeagueRequest;
use App\Http\Resources\LeagueResource;
use App\Http\Resources\LeagueRoundResource;
use App\Models\Challenge;
use App\Models\League;
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
            ->with(['rounds', 'members'])
            ->get();

        return LeagueResource::collection($leagues);
    }

    public function store(CreateLeagueRequest $request)
    {
        $league = $this->leagueService->create($request->validated(), $request->user()->id);
        return new LeagueResource($league->load('members'));
    }

    public function join(Request $request)
    {
        $request->validate(['join_code' => ['required', 'string', 'size:6']]);
        $league = $this->leagueService->join($request->join_code, $request->user()->id);
        return new LeagueResource($league->load('members'));
    }

    public function show(Request $request, League $league)
    {
        if (!$league->members()->where('user_id', $request->user()->id)->exists()) {
            return response()->json(['message' => 'Not a member of this league'], 403);
        }
        return new LeagueResource($league->load('members'));
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
                ['message' => 'No active football challenges available. Add challenges in admin.'],
                422
            );
        }

        $league = $this->leagueService->start($league, $userId);
        return new LeagueResource($league->load('members'));
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
            ]);
        }

        return response()->json([
            'current_round'     => new LeagueRoundResource($round),
            'has_current_round' => true,
            'completed'         => false,
            'reason'            => 'has_pending_round',
            'progress'          => $progress,
        ]);
    }
}
