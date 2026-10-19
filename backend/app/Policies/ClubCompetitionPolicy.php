<?php

namespace App\Policies;

use App\Enums\CompetitionPhase;
use App\Models\ClubCompetition;
use App\Models\User;
use App\Services\Clubs\ClubMembershipService;

/**
 * Competition abilities for club members (plan §6.2). Platform-only actions
 * (void, finalize, rebuild) are not granted to any club role: platform
 * admins get them through Gate::before in the admin area.
 */
class ClubCompetitionPolicy
{
    public function __construct(private ClubMembershipService $memberships) {}

    /** Members see every non-draft competition of their (non-archived) club. */
    public function view(User $user, ClubCompetition $competition): bool
    {
        $club = $competition->club;

        return $club !== null
            && !$club->isArchived()
            && $competition->phase() !== CompetitionPhase::Draft
            && $this->memberships->membership($club, $user) !== null;
    }

    /** Start attempts / guess: active member of an active club while the window is open. */
    public function play(User $user, ClubCompetition $competition): bool
    {
        $club = $competition->club;

        return $club !== null
            && $club->isActive()
            && $competition->phase() === CompetitionPhase::Active
            && $this->memberships->membership($club, $user) !== null;
    }

    /** Create/edit/publish/cancel (club self-service, phase 7): owner/admin of an active club. */
    public function manage(User $user, ClubCompetition $competition): bool
    {
        $club = $competition->club;

        return $club !== null
            && $club->isActive()
            && (bool) $this->memberships->membership($club, $user)?->role->canManage();
    }

    /** Void a competition or attempt — platform admins only (via Gate::before). */
    public function void(User $user, ClubCompetition $competition): bool
    {
        return false;
    }
}
