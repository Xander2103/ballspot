<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLeagueRequest;
use App\Http\Resources\LeagueResource;
use App\Http\Resources\LeagueRoundResource;
use App\Models\League;
use App\Services\LeagueService;
use Illuminate\Http\Request;

class LeagueController extends Controller
{
    public function __construct(private LeagueService $leagueService) {}

    public function index(Request $request)
    {
        $leagues = $request->user()->members()->with('rounds')->get();
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

    public function show(League $league)
    {
        return new LeagueResource($league->load('members'));
    }

    public function currentRound(Request $request, League $league)
    {
        $userId = $request->user()->id;
        if (!$league->members()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Not a member of this league'], 403);
        }

        $round = $league->rounds()
            ->where('status', 'open')
            ->whereDoesntHave('guesses', fn($q) => $q->where('user_id', $userId))
            ->orderBy('round_number')
            ->with('challenge')
            ->first();

        if (!$round) {
            return response()->json(['current_round' => null, 'completed' => true]);
        }

        return response()->json(['current_round' => new LeagueRoundResource($round), 'completed' => false]);
    }
}
