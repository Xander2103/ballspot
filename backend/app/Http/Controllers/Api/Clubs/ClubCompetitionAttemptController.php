<?php

namespace App\Http\Controllers\Api\Clubs;

use App\Exceptions\ClubDomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\BadgeResource;
use App\Http\Resources\Clubs\CompetitionAttemptResource;
use App\Models\ClubCompetitionAttempt;
use App\Models\User;
use App\Services\Clubs\ClubAccess;
use App\Services\Clubs\ClubCompetitionPlayService;
use App\Services\Clubs\ClubStandingsService;
use App\Services\Clubs\CompetitionEligibility;
use App\Services\PackPlayService;
use App\Support\ChallengeReveal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Competition attempts by id (contracts §5). Owner only: anybody else —
 * and an owner who lost the membership — gets 404 club_not_found. Guessing
 * also needs an active club (423 club_unavailable when suspended).
 */
class ClubCompetitionAttemptController extends Controller
{
    public function __construct(
        private ClubCompetitionPlayService $play,
        private ClubStandingsService $standings,
        private CompetitionEligibility $eligibility,
        private PackPlayService $packs,
    ) {}

    // GET /api/club-competition-attempts/{attempt}
    public function show(Request $request, ClubCompetitionAttempt $attempt): JsonResponse
    {
        $this->authorizeOwner($request->user(), $attempt);

        return response()->json([
            'attempt'   => (new CompetitionAttemptResource($attempt))->resolve(),
            'challenge' => $this->packs->challengePayload($this->play->currentChallenge($attempt)),
        ]);
    }

    // POST /api/club-competition-attempts/{attempt}/guess {challenge_id, guessed_x, guessed_y}
    public function guess(Request $request, ClubCompetitionAttempt $attempt): JsonResponse
    {
        $club = $this->authorizeOwner($request->user(), $attempt);
        ClubAccess::requireActiveClub($club);

        $data = $request->validate([
            'challenge_id' => ['required', 'integer'],
            'guessed_x'    => ['required', 'numeric', 'min:0', 'max:1'],
            'guessed_y'    => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        $r = $this->play->guess($request->user(), $attempt, (int) $data['challenge_id'], (float) $data['guessed_x'], (float) $data['guessed_y']);

        /** @var ClubCompetitionAttempt $row */
        $row         = $r['attempt'];
        $guess       = $r['guess'];
        $competition = $row->competition;
        $completed   = $r['completed'];

        $entry   = $this->standings->myEntry($competition, $request->user());
        $packRes = $competition->packResults()->where('user_id', $request->user()->id)->where('challenge_pack_id', $row->challenge_pack_id)->first();
        $state   = $this->eligibility->packState($competition, $club, (int) $row->challenge_pack_id, $request->user(), null, $packRes);

        return response()->json([
            'result'            => ChallengeReveal::result($r['challenge'], (int) $guess->score, (float) $guess->distance, (float) $guess->guess_x, (float) $guess->guess_y),
            'progress'          => (new CompetitionAttemptResource($row))->resolve(),
            'next_challenge'    => $completed ? null : $this->packs->challengePayload($this->play->currentChallenge($row)),
            'rank_progress'     => $r['rank_progress'],
            'rank_up'           => $r['rank_up'],
            'new_badges'        => BadgeResource::collection($r['new_badges'])->resolve(),
            'pack_completed'    => $completed,
            'already_completed' => $r['replayed'] && $completed,
            'final_score'       => $completed ? (int) $row->total_score : null,
            'completion_xp'     => $completed ? (int) $r['completion_xp'] : null,
            'completion'        => null,
            'replayed'          => $r['replayed'],
            'is_rewarded_run'   => (bool) $row->is_rewarded_run,
            'competition'       => [
                'counted_score'      => $state['counted_score'],
                'total_score'        => $entry['total_score'] ?? null,
                'rank'               => $entry['rank'] ?? null,
                'attempts_remaining' => $state['attempts_remaining'],
            ],
        ]);
    }

    /** Owner + still an active member of the competition's (non-archived) club, else 404. */
    private function authorizeOwner(?User $user, ClubCompetitionAttempt $attempt): \App\Models\Club
    {
        $club = $attempt->competition?->club;
        if ($user === null || $club === null || (int) $attempt->user_id !== (int) $user->id) {
            throw ClubDomainException::notFound();
        }
        ClubAccess::requireMember($club, $user);

        return $club;
    }
}
