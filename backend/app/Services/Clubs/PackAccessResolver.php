<?php

namespace App\Services\Clubs;

use App\Contracts\PackAccessGate;
use App\Models\ChallengePack;
use App\Models\User;

/**
 * The bound PackAccessGate: picks the implementation by pack.club_id.
 * Inject PackAccessGate (or this class) wherever normal pack play starts or
 * continues.
 */
final class PackAccessResolver implements PackAccessGate
{
    public function __construct(
        private PublicPackAccess $public,
        private ClubPackAccess $club,
    ) {}

    public function for(ChallengePack $pack): PackAccessGate
    {
        return $pack->club_id === null ? $this->public : $this->club;
    }

    public function assertCanPlay(User $user, ChallengePack $pack): void
    {
        $this->for($pack)->assertCanPlay($user, $pack);
    }

    public function assertCanContinue(User $user, ChallengePack $pack): void
    {
        $this->for($pack)->assertCanContinue($user, $pack);
    }

    /** Convenience for ClubPackCard `reserved_for_competition`. */
    public function isReservedForCompetition(ChallengePack $pack): bool
    {
        return $this->club->isReservedForCompetition($pack);
    }
}
