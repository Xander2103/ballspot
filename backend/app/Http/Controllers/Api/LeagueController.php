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
use App\Services\TournamentAvailabilityService;
use Illuminate\Http\Request;

class LeagueController extends Controller
{
    public function __construct(
        private LeagueService $leagueService,
        private TournamentAvailabilityService $availability,
    ) {}

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
            return response()->json(['message' => __('messages.tournaments.not_member')], 403);
        }
        if ($league->status !== 'completed') {
            return response()->json(['message' => __('messages.tournaments.hide_only_finished')], 422);
        }

        // Idempotent: hiding an already-hidden tournament is a no-op success.
        $league->members()->updateExistingPivot($userId, ['hidden_at' => now()]);

        return response()->noContent();
    }

    /**
     * GET /tournaments/availability?sport=<slug>&duration_days=7
     *
     * Can a tournament of this length be created right now? Lets the app
     * disable "Create tournament" before the user fills in the form. Sport
     * resolves like create: explicit slug → the user's preferred sport →
     * football. Counts only (no content, no ids).
     */
    public function availability(Request $request)
    {
        $days = (int) $request->query('duration_days', min((array) config('ballspot.tournaments.allowed_duration_days', [7])));
        if (!in_array($days, (array) config('ballspot.tournaments.allowed_duration_days', [7, 14, 30]), true)) {
            $days = (int) min((array) config('ballspot.tournaments.allowed_duration_days', [7]));
        }

        $sport = null;
        if ($slug = $request->query('sport')) {
            $sport = \App\Models\Sport::where('slug', (string) $slug)->where('status', \App\Models\Sport::STATUS_ACTIVE)->first();
        }
        if (!$sport && $request->user()->preferred_sport_id) {
            $sport = \App\Models\Sport::where('id', $request->user()->preferred_sport_id)->where('status', \App\Models\Sport::STATUS_ACTIVE)->first();
        }
        $sport ??= \App\Models\Sport::where('slug', 'football')->first();

        if (!$sport) {
            return response()->json([
                'available' => false, 'required' => $days, 'available_challenges' => 0,
                'message' => TournamentAvailabilityService::message(), 'sport' => null, 'duration_days' => $days,
            ]);
        }

        return response()->json($this->availability->check($sport, $days, 1) + ['duration_days' => $days]);
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
            return response()->json(['message' => __('messages.tournaments.not_member')], 403);
        }
        return new LeagueResource($league->load('members', 'sport'));
    }

    public function start(Request $request, League $league)
    {
        $userId = $request->user()->id;

        if (!$league->members()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => __('messages.tournaments.not_member')], 403);
        }
        if ((int) $league->owner_user_id !== (int) $userId) {
            return response()->json(['message' => __('messages.tournaments.owner_only_start')], 403);
        }
        if ($league->status !== 'lobby') {
            return response()->json(['message' => __('messages.tournaments.start_from_lobby')], 422);
        }

        // v1.8.9 fairness: needs duration_days * rounds_per_day UNIQUE
        // tournament-eligible photos (never Daily-used). Answered with the
        // structured "temporarily unavailable" body — never a bare 422 — so
        // the app can show friendly copy. The service repeats the check
        // inside the transaction (cooldown-aware selection) as the last word.
        $check = $this->availability->check($league->sport, (int) $league->duration_days, (int) $league->rounds_per_day);
        if (!$check['available']) {
            \App\Support\AppLog::warn('tournament.start_failed', [
                'league_id' => $league->id, 'user_id' => $userId, 'reason' => 'not_enough_challenges',
                'sport_id' => (int) $league->sport_id, 'requested_count' => $check['required'], 'eligible_count' => $check['available_challenges'],
            ]);
            return TournamentAvailabilityService::response($check['required'], $check['available_challenges']);
        }

        $league = $this->leagueService->start($league, $userId);
        return new LeagueResource($league->load('members', 'sport'));
    }

    public function destroy(Request $request, League $league)
    {
        $userId = $request->user()->id;

        if (!$league->members()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => __('messages.tournaments.not_member')], 403);
        }
        if ((int) $league->owner_user_id !== (int) $userId) {
            return response()->json(['message' => __('messages.tournaments.owner_only_cancel')], 403);
        }

        $this->leagueService->cancel($league, $userId);
        return response()->noContent();
    }

    public function removeMember(Request $request, League $league, User $user)
    {
        $requesterId = $request->user()->id;

        if (!$league->members()->where('user_id', $requesterId)->exists()) {
            return response()->json(['message' => __('messages.tournaments.not_member')], 403);
        }
        if ((int) $league->owner_user_id !== (int) $requesterId) {
            return response()->json(['message' => __('messages.tournaments.owner_only_remove')], 403);
        }
        if ($league->status !== 'lobby') {
            return response()->json(['message' => __('messages.tournaments.remove_in_lobby')], 422);
        }
        if ((int) $user->id === (int) $league->owner_user_id) {
            return response()->json(['message' => __('messages.tournaments.owner_not_removable')], 422);
        }

        $league->members()->detach($user->id);
        return response()->noContent();
    }

    public function currentRound(Request $request, League $league)
    {
        $userId = $request->user()->id;
        if (!$league->members()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => __('messages.tournaments.not_member')], 403);
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
                'message'          => __('messages.tournaments.daily_limit'),
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
            ->with(['challenge.category', 'challenge.sport'])
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
