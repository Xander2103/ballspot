<?php

namespace App\Contracts;

use App\Models\ChallengePack;
use App\Models\User;

/**
 * Who may play a pack on the NORMAL track (pack_attempts), contracts §3.7.
 *
 * Bound to App\Services\Clubs\PackAccessResolver, which delegates by
 * pack.club_id to PublicPackAccess (BallPicker packs — behaviour identical
 * to before Clubs) or ClubPackAccess (members of an active club only, plus
 * the competition reservation check of D15).
 *
 * Both methods return nothing or throw.
 */
interface PackAccessGate
{
    /**
     * Starting (or resuming via a start call) a normal attempt.
     * Club packs also refuse with 409 `pack_reserved_for_competition` while a
     * linked competition with normal_play_policy = blocked is published.
     */
    public function assertCanPlay(User $user, ChallengePack $pack): void;

    /**
     * Continuing an attempt that already exists (each guess). Same access
     * rules without the pack-status check, like today's public guess
     * endpoint. Club packs KEEP the reservation check (security review
     * 2026-10): a normal attempt on a pack reserved by a blocked competition,
     * or while the player has an active competition attempt on it, is frozen
     * (409 pack_reserved_for_competition) — its reveal would otherwise leak
     * the answer key to the competition track.
     */
    public function assertCanContinue(User $user, ChallengePack $pack): void;
}
