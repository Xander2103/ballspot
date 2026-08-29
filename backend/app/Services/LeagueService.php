<?php
namespace App\Services;

use App\Models\Challenge;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use Illuminate\Support\Str;

class LeagueService
{
    /** Statuses that count as an "in-use" tournament for the free create limit. */
    private const ACTIVE_STATUSES = ['lobby', 'active'];

    public function create(array $data, int $userId): League
    {
        // Free-tier limit — foundation only, no payments. Archived/completed/
        // cancelled tournaments do not count.
        $activeOwned = League::where('owner_user_id', $userId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->count();

        abort_if(
            $activeOwned >= $this->maxCreatedPerUser($userId),
            422,
            'You can only host one active tournament at a time.'
        );

        // v1.8.8: creating auto-joins the owner, so it must respect the
        // active-membership cap too.
        $this->assertMembershipCapacity($userId);

        $sport = $this->resolveSport($data, $userId);

        $league = League::create([
            'name'           => $data['name'],
            'join_code'      => $this->generateJoinCode(),
            'owner_user_id'  => $userId,
            'sport_id'       => $sport->id,
            'duration_days'  => $data['duration_days'],
            'rounds_per_day' => 1, // v1.8.8: one photo per day, regardless of input
            'status'         => 'lobby',
        ]);

        LeagueMember::create([
            'league_id' => $league->id,
            'user_id'   => $userId,
            'joined_at' => now(),
        ]);

        return $league;
    }

    /**
     * Decide which sport a new tournament belongs to.
     * Precedence: explicit request sport_id -> user's preferred sport -> football.
     * Only active sports are ever used; falls back to football if a stale
     * preference points at a now-inactive sport.
     */
    private function resolveSport(array $data, int $userId): Sport
    {
        if (!empty($data['sport_id'])) {
            $sport = Sport::where('id', $data['sport_id'])->where('status', Sport::STATUS_ACTIVE)->first();
            if ($sport) {
                return $sport;
            }
        }

        $user = \App\Models\User::find($userId);
        if ($user && $user->preferred_sport_id) {
            $preferred = Sport::where('id', $user->preferred_sport_id)->where('status', Sport::STATUS_ACTIVE)->first();
            if ($preferred) {
                return $preferred;
            }
        }

        return Sport::where('slug', 'football')->firstOrFail();
    }

    /** Shown (422) when a tournament cannot be filled with unique eligible photos. */
    public const NOT_ENOUGH_CHALLENGES_MESSAGE =
        'Not enough unused tournament challenges available. Add more tournament photos first.';

    /**
     * Challenges a NEW tournament for this sport may draw from.
     *
     * Fairness rules (v1.8.9):
     *  - active + ready (hidden image, ball position)
     *  - usage_pool is tournament or general
     *  - never used as a Daily Challenge (any daily_challenges row, any status)
     *
     * Rounds are shared by every player in the tournament, so this pool is
     * the same for all of them.
     */
    public function eligibleTournamentChallenges(int $sportId): \Illuminate\Support\Collection
    {
        return Challenge::tournamentEligible()
            ->where('sport_id', $sportId)
            ->get()
            ->filter->isReady()
            ->values();
    }

    public function start(League $league, int $userId): League
    {
        $total = $league->duration_days * $league->rounds_per_day;

        // Unique photos only: never the same challenge twice in one tournament,
        // never a Daily-used photo. Fail cleanly rather than recycle.
        $challenges = $this->eligibleTournamentChallenges((int) $league->sport_id)
            ->shuffle()
            ->take($total)
            ->values();

        abort_if($challenges->count() < $total, 422, self::NOT_ENOUGH_CHALLENGES_MESSAGE);

        foreach ($challenges as $i => $challenge) {
            LeagueRound::create([
                'league_id'    => $league->id,
                'challenge_id' => $challenge->id,
                'round_number' => $i + 1,
                'status'       => 'open',
            ]);
        }

        $league->update([
            'status'    => 'active',
            'starts_at' => now(),
            'ends_at'   => now()->addDays($league->duration_days),
        ]);

        return $league->fresh();
    }

    public function cancel(League $league, int $userId): void
    {
        $league->update(['status' => 'cancelled']);
    }

    public function join(string $joinCode, int $userId): League
    {
        $league = League::where('join_code', $joinCode)->firstOrFail();

        abort_if(
            $league->status !== 'lobby',
            422,
            'This tournament is no longer accepting players.'
        );

        // Already a member? Idempotent — never block re-joining your own lobby.
        $alreadyMember = $league->members()->where('user_id', $userId)->exists();

        if (!$alreadyMember) {
            // v1.8.8: max 2 lobby/active tournaments per user (hosting counts).
            $this->assertMembershipCapacity($userId);

            abort_if(
                $league->members()->count() >= $this->maxPlayersPerTournament($league->owner_user_id),
                422,
                'This tournament is full.'
            );

            LeagueMember::firstOrCreate([
                'league_id' => $league->id,
                'user_id'   => $userId,
            ], ['joined_at' => now()]);
        }

        return $league;
    }

    /**
     * Max tournaments a user may have in lobby/active at once.
     *
     * TODO(premium): return the premium limit for premium users once a billing/
     * entitlement system exists. No payments are implemented — everyone is free.
     */
    private function maxCreatedPerUser(int $userId): int
    {
        return config('ballspot.tournaments.max_created_per_user');
    }

    /**
     * Max players allowed in a single tournament.
     *
     * TODO(premium): premium owners could unlock a larger cap. Not enforced yet.
     */
    private function maxPlayersPerTournament(int $ownerUserId): int
    {
        return config('ballspot.tournaments.max_players_per_tournament');
    }

    /** Lobby/active tournaments the user is currently a member of. */
    private function activeMembershipCount(int $userId): int
    {
        return League::whereIn('status', self::ACTIVE_STATUSES)
            ->whereHas('members', fn ($q) => $q->where('users.id', $userId))
            ->count();
    }

    private function assertMembershipCapacity(int $userId): void
    {
        $max = (int) config('ballspot.tournaments.max_active_memberships_per_user', 2);

        abort_if(
            $this->activeMembershipCount($userId) >= $max,
            422,
            'You can only be in two active tournaments at the same time.'
        );
    }

    private function generateJoinCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (League::where('join_code', $code)->exists());
        return $code;
    }
}
