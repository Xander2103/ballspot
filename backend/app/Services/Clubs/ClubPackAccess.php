<?php

namespace App\Services\Clubs;

use App\Contracts\PackAccessGate;
use App\Enums\CompetitionAttemptStatus;
use App\Enums\CompetitionState;
use App\Exceptions\ClubDomainException;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Club packs on the normal track (contracts §3.7):
 *  - the Clubs feature flag must be on (off → 404 feature_disabled, also for
 *    a guess on an attempt that already exists);
 *  - the user must be an ACTIVE member (banned/left/removed → 404
 *    club_not_found) of a non-archived club (archived → 404);
 *  - a suspended club is read-only → 423 club_unavailable;
 *  - start only: the pack must be active (else 404);
 *  - start AND every guess: the pack is not reserved by a published, not yet
 *    finalized/cancelled/voided competition with normal_play_policy = blocked
 *    (409 pack_reserved_for_competition, D15). Normal attempts in progress on
 *    a reserved pack are FROZEN and resume once the competition is finalized,
 *    cancelled or voided — otherwise a member could guess on the normal
 *    attempt first, read ball_x/y from the reveal and submit that position to
 *    the competition (security review 2026-10, finding 1);
 *  - start AND every guess: the player has no ACTIVE competition attempt on
 *    the pack in a live competition (normal_play_policy = allowed) — same
 *    409 pack_reserved_for_competition, same reason.
 */
final class ClubPackAccess implements PackAccessGate
{
    public function __construct(private ClubMembershipService $memberships) {}

    public function assertCanPlay(User $user, ChallengePack $pack): void
    {
        $this->assertFeatureEnabled();
        $this->assertMemberOfActiveClub($user, $pack);

        if ($pack->status !== ChallengePack::STATUS_ACTIVE) {
            throw ClubDomainException::notFound();
        }

        $this->assertNotReservedFor($user, $pack);
    }

    public function assertCanContinue(User $user, ChallengePack $pack): void
    {
        $this->assertFeatureEnabled();
        $this->assertMemberOfActiveClub($user, $pack);
        $this->assertNotReservedFor($user, $pack);
    }

    /**
     * True while the player has an active competition attempt on this pack
     * in a competition whose window is still open (any normal_play_policy).
     */
    public function hasActiveCompetitionAttempt(User $user, ChallengePack $pack): bool
    {
        return $pack->club_id !== null && ClubCompetitionAttempt::query()
            ->where('user_id', $user->id)
            ->where('challenge_pack_id', $pack->id)
            ->where('status', CompetitionAttemptStatus::Active->value)
            ->whereHas('competition', fn ($q) => $q
                ->where('club_id', $pack->club_id)
                ->where('state', CompetitionState::Published->value)
                ->where('ends_at', '>', now()))
            ->exists();
    }

    /** True while a linked competition blocks normal play (published + blocked). */
    public function isReservedForCompetition(ChallengePack $pack): bool
    {
        return $pack->club_id !== null && $this->reservingCompetitionsQuery($pack)->exists();
    }

    /** The competitions currently reserving the pack (for the `competitions` list on pack cards). */
    public function reservingCompetitions(ChallengePack $pack): Collection
    {
        return $pack->club_id === null ? collect() : $this->reservingCompetitionsQuery($pack)->get();
    }

    private function reservingCompetitionsQuery(ChallengePack $pack)
    {
        return ClubCompetition::query()
            ->where('club_id', $pack->club_id)
            ->reservingNormalPlay()
            ->whereHas('competitionPacks', fn ($q) => $q->where('challenge_pack_id', $pack->id));
    }

    private function assertNotReservedFor(User $user, ChallengePack $pack): void
    {
        if ($this->isReservedForCompetition($pack) || $this->hasActiveCompetitionAttempt($user, $pack)) {
            throw new ClubDomainException(ClubDomainException::PACK_RESERVED_FOR_COMPETITION, 409);
        }
    }

    private function assertFeatureEnabled(): void
    {
        if (!EnsureFeatureEnabled::enabled('clubs')) {
            throw new ClubDomainException(ClubDomainException::FEATURE_DISABLED, 404);
        }
    }

    private function assertMemberOfActiveClub(User $user, ChallengePack $pack): void
    {
        /** @var Club|null $club */
        $club = $pack->club_id !== null ? ($pack->relationLoaded('club') ? $pack->club : $pack->club()->first()) : null;
        if ($club === null) {
            throw ClubDomainException::notFound();
        }

        ClubAccess::requireMember($club, $user);
        ClubAccess::requireActiveClub($club);
    }
}
