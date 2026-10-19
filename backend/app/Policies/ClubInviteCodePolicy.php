<?php

namespace App\Policies;

use App\Models\Club;
use App\Models\ClubInviteCode;
use App\Models\User;
use App\Services\Clubs\ClubMembershipService;

/**
 * Invite codes are visible to and managed by the club's owners/admins only
 * (plan §11.2); platform admins via Gate::before in the admin area.
 */
class ClubInviteCodePolicy
{
    public function __construct(private ClubMembershipService $memberships) {}

    public function viewAny(User $user, Club $club): bool
    {
        return $this->manages($user, $club);
    }

    public function view(User $user, ClubInviteCode $code): bool
    {
        return $this->manages($user, $code->club);
    }

    public function create(User $user, Club $club): bool
    {
        return $this->manages($user, $club);
    }

    public function revoke(User $user, ClubInviteCode $code): bool
    {
        return $this->manages($user, $code->club);
    }

    public function regenerate(User $user, ClubInviteCode $code): bool
    {
        return $this->manages($user, $code->club);
    }

    private function manages(User $user, ?Club $club): bool
    {
        return $club !== null
            && $club->isActive()
            && (bool) $this->memberships->membership($club, $user)?->role->canManage();
    }
}
