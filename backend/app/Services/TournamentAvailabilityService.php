<?php

namespace App\Services;

use App\Models\Sport;
use App\Support\AppLog;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/**
 * "Can a tournament of this length be filled right now?"
 *
 * One answer for the availability endpoint, the create flow and the start
 * flow, so the app never sees a bare 422 when the content pool is short:
 *
 *   { message, code: TOURNAMENTS_TEMPORARILY_UNAVAILABLE,
 *     reason: INSUFFICIENT_TOURNAMENT_CHALLENGES, required, available }
 *
 * Eligibility is LeagueService::eligibleTournamentChallenges (active + ready,
 * usage_pool tournament/general, never Daily-used, correct sport) — daily-only
 * and pack-only content does not help, so the message stays tournament-
 * specific even when other content exists. The copy lives in
 * lang/<locale>/messages.php and the variant (soon / next month) is picked by
 * config('ballspot.tournaments.unavailable_copy').
 */
class TournamentAvailabilityService
{
    public const CODE   = 'TOURNAMENTS_TEMPORARILY_UNAVAILABLE';
    public const REASON = 'INSUFFICIENT_TOURNAMENT_CHALLENGES';

    public function __construct(private LeagueService $leagues) {}

    /**
     * @return array{available: bool, required: int, available_challenges: int, message: string|null, sport: array}
     */
    public function check(Sport $sport, int $durationDays, int $roundsPerDay = 1): array
    {
        $required  = max(1, $durationDays) * max(1, $roundsPerDay);
        $available = $this->leagues->eligibleTournamentChallenges((int) $sport->id)->count();
        $ok        = $available >= $required;

        return [
            'available'            => $ok,
            'required'             => $required,
            'available_challenges' => $available,
            'message'              => $ok ? null : self::message(),
            'sport'                => ['id' => $sport->id, 'slug' => $sport->slug, 'name' => $sport->name],
        ];
    }

    /** The user-facing sentence, in the current locale. */
    public static function message(): string
    {
        $variant = (string) config('ballspot.tournaments.unavailable_copy', 'soon');
        $key     = $variant === 'next_month'
            ? 'messages.tournaments.temporarily_unavailable_next_month'
            : 'messages.tournaments.temporarily_unavailable';

        return __($key);
    }

    /** The structured 422 body every failing create/start answers with. */
    public static function response(int $required, int $available): JsonResponse
    {
        return response()->json([
            'message'   => self::message(),
            'code'      => self::CODE,
            'reason'    => self::REASON,
            'required'  => $required,
            'available' => $available,
        ], 422);
    }

    /**
     * Abort the request with the structured body. Logged by category only
     * (ids + counts — never content).
     */
    public static function throw(int $required, int $available, array $log = []): never
    {
        AppLog::warn('tournament.unavailable', $log + [
            'reason'          => 'not_enough_challenges',
            'requested_count' => $required,
            'eligible_count'  => $available,
        ]);

        throw new HttpResponseException(self::response($required, $available));
    }
}
