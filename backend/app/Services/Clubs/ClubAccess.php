<?php

namespace App\Services\Clubs;

use App\Exceptions\ClubDomainException;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Entry guard for every member-facing club route (contracts §3.5).
 *
 *   $member = ClubAccess::requireMember($club, $request->user());
 *
 * Non-members — outsiders, members of another club, left/removed/banned
 * users, platform admins without a membership — and archived clubs all get
 * the same 404 `club_not_found`: the API never confirms that a club exists.
 * Suspended clubs stay readable (D18); use requireActiveClub() before any
 * write/play action (423 `club_unavailable`).
 */
final class ClubAccess
{
    public static function requireMember(Club $club, ?User $user): ClubMember
    {
        if ($user === null || $club->isArchived()) {
            throw ClubDomainException::notFound();
        }

        $member = app(ClubMembershipService::class)->membership($club, $user);
        if ($member === null) {
            throw ClubDomainException::notFound();
        }

        return $member;
    }

    /** Suspended (read-only) clubs refuse play/join/write with 423 club_unavailable. */
    public static function requireActiveClub(Club $club): void
    {
        if ($club->isArchived()) {
            throw ClubDomainException::notFound();
        }
        if (!$club->isActive()) {
            throw ClubDomainException::unavailable();
        }
    }

    /**
     * A member-level ability (ClubPolicy) for a user already known to be a
     * member: 403 `club_forbidden` when the role/settings do not allow it.
     */
    public static function requireAbility(User $user, string $ability, Club $club): void
    {
        if (!Gate::forUser($user)->allows($ability, $club)) {
            throw ClubDomainException::forbidden();
        }
    }
}
