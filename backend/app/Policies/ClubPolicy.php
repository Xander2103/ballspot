<?php

namespace App\Policies;

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\User;
use App\Services\Clubs\ClubMembershipService;

/**
 * Club abilities by ACTIVE membership role (plan §6.2, contracts §3.5).
 *
 * Platform admins: Gate::before (AppServiceProvider) grants them every
 * ability in the admin area and every `admin.*` ability. Outside the admin
 * area — the member API, the club portal — an admin is decided by
 * membership like anyone else, so an admin who is not a member gets 404.
 *
 * Non-members are simply denied here; member routes turn that into the
 * uniform 404 through ClubAccess::requireMember() before any policy check.
 */
class ClubPolicy
{
    public function __construct(private ClubMembershipService $memberships) {}

    /** See the club, its packs, competitions and leaderboards (suspended = read-only, still visible). */
    public function view(User $user, Club $club): bool
    {
        return !$club->isArchived() && $this->member($user, $club) !== null;
    }

    /** Member list: owners/admins always; members only when the club allows it (D9). */
    public function viewMembers(User $user, Club $club): bool
    {
        $member = $this->member($user, $club);
        if ($member === null || $club->isArchived()) {
            return false;
        }

        return $member->role->canManage() || $club->memberListVisibleToMembers();
    }

    /** Leave — never as the sole owner (they must transfer first). */
    public function leave(User $user, Club $club): bool
    {
        $member = $this->member($user, $club);
        if ($member === null) {
            return false;
        }

        return $member->role !== ClubRole::Owner || $this->memberships->activeOwnerCount($club) > 1;
    }

    /** Play packs / competitions: active club only. */
    public function play(User $user, Club $club): bool
    {
        return $club->isActive() && $this->member($user, $club) !== null;
    }

    public function manageCodes(User $user, Club $club): bool
    {
        return $this->manager($user, $club);
    }

    public function manageMembers(User $user, Club $club): bool
    {
        return $this->manager($user, $club);
    }

    /** Packs and competitions (rule changes after lock are refused by the rules guard, not here). */
    public function manageCompetitions(User $user, Club $club): bool
    {
        return $this->manager($user, $club);
    }

    public function uploadPhotos(User $user, Club $club): bool
    {
        return $this->manager($user, $club);
    }

    /** Owner only. */
    public function manageSettings(User $user, Club $club): bool
    {
        return $club->isActive() && $this->member($user, $club)?->role === ClubRole::Owner;
    }

    /** Owner only. */
    public function transferOwnership(User $user, Club $club): bool
    {
        return $club->isActive() && $this->member($user, $club)?->role === ClubRole::Owner;
    }

    /**
     * `admin.manage` — platform administration of clubs (D21: every is_admin
     * user for now; narrower admin roles can hook in here later). Registered
     * with Gate::define in AppServiceProvider; the club is optional for the
     * index (`Gate::authorize('admin.manage')`).
     */
    public function adminManage(User $user, ?Club $club = null): bool
    {
        return (bool) $user->is_admin;
    }

    /** `portal.access` — owner/admin of an ACTIVE club may use the club portal. */
    public function portalAccess(User $user, Club $club): bool
    {
        return $this->manager($user, $club);
    }

    // ------------------------------------------------------------------

    private function member(User $user, Club $club): ?ClubMember
    {
        return $this->memberships->membership($club, $user);
    }

    /** Active owner/admin of an active club. */
    private function manager(User $user, Club $club): bool
    {
        return $club->isActive() && (bool) $this->member($user, $club)?->role->canManage();
    }
}
