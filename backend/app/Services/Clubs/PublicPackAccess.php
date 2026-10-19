<?php

namespace App\Services\Clubs;

use App\Contracts\PackAccessGate;
use App\Models\ChallengePack;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * BallPicker (public) packs: exactly the pre-Clubs rules. Start requires an
 * active + public pack without a club, else the same 404 the public routes
 * already answer (ModelNotFoundException for ChallengePack). Continuing an
 * existing attempt has no extra check, as before.
 */
final class PublicPackAccess implements PackAccessGate
{
    public function assertCanPlay(User $user, ChallengePack $pack): void
    {
        if ($pack->club_id !== null
            || $pack->status !== ChallengePack::STATUS_ACTIVE
            || $pack->visibility !== ChallengePack::VISIBILITY_PUBLIC) {
            throw (new ModelNotFoundException())->setModel(ChallengePack::class);
        }
    }

    public function assertCanContinue(User $user, ChallengePack $pack): void
    {
        if ($pack->club_id !== null) {
            throw (new ModelNotFoundException())->setModel(ChallengePack::class);
        }
    }
}
