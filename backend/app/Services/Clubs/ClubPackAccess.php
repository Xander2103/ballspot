<?php

namespace App\Services\Clubs;

use App\Contracts\PackAccessGate;
use App\Exceptions\ClubDomainException;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Club packs on the normal track (contracts §3.7):
 *  - the user must be an ACTIVE member (banned/left/removed → 404
 *    club_not_found) of a non-archived club (archived → 404);
 *  - a suspended club is read-only → 423 club_unavailable;
 *  - start only: the pack must be active (else 404) and not reserved by a
 *    published competition with normal_play_policy = blocked
 *    (409 pack_reserved_for_competition, D15).
 */
final class ClubPackAccess implements PackAccessGate
{
    public function __construct(private ClubMembershipService $memberships) {}

    public function assertCanPlay(User $user, ChallengePack $pack): void
    {
        $this->assertMemberOfActiveClub($user, $pack);

        if ($pack->status !== ChallengePack::STATUS_ACTIVE) {
            throw ClubDomainException::notFound();
        }

        if ($this->isReservedForCompetition($pack)) {
            throw new ClubDomainException(ClubDomainException::PACK_RESERVED_FOR_COMPETITION, 409);
        }
    }

    public function assertCanContinue(User $user, ChallengePack $pack): void
    {
        $this->assertMemberOfActiveClub($user, $pack);
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
