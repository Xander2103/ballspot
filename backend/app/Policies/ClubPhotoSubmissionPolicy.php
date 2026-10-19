<?php

namespace App\Policies;

use App\Models\Club;
use App\Models\ClubPhotoSubmission;
use App\Models\User;
use App\Services\Clubs\ClubMembershipService;

/**
 * Club photo submissions (plan §4.7, phase 6/7). Club owners/admins upload
 * and follow their club's submissions; review, preparation and conversion
 * are BallPicker tasks (platform admins via Gate::before).
 */
class ClubPhotoSubmissionPolicy
{
    public function __construct(private ClubMembershipService $memberships) {}

    public function viewAny(User $user, Club $club): bool
    {
        return $this->manages($user, $club);
    }

    public function view(User $user, ClubPhotoSubmission $submission): bool
    {
        return $this->manages($user, $submission->club);
    }

    public function create(User $user, Club $club): bool
    {
        return $this->manages($user, $club);
    }

    /** Assign / prepare / convert / reject — never a club role. */
    public function review(User $user, ClubPhotoSubmission $submission): bool
    {
        return false;
    }

    private function manages(User $user, ?Club $club): bool
    {
        return $club !== null
            && $club->isActive()
            && (bool) $this->memberships->membership($club, $user)?->role->canManage();
    }
}
