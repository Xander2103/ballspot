<?php

namespace App\Services\ClubPortal;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who may use the club portal, and for which clubs: an ACTIVE owner/admin
 * membership of an active or suspended club (suspended = read-only;
 * archived clubs are never reachable). Platform admins get no bypass here.
 * Deliberately not memoised: controllers (and their injected services) are
 * reused across requests by the router.
 */
class ClubPortalAccess
{
    /**
     * Clubs the user manages, name order, each with `portal_role` (ClubRole).
     *
     * @return Collection<int, Club>
     */
    public function clubsFor(User $user): Collection
    {
        $rows = ClubMember::query()
            ->where('user_id', $user->id)
            ->where('status', ClubMemberStatus::Active->value)
            ->whereIn('role', [ClubRole::Owner->value, ClubRole::Admin->value])
            ->whereHas('club', fn ($q) => $q->whereIn('status', [ClubStatus::Active->value, ClubStatus::Suspended->value]))
            ->with('club')
            ->get();

        return $rows
            ->map(function (ClubMember $m) {
                $club = $m->club;
                $club->setAttribute('portal_role', $m->role);

                return $club;
            })
            ->sortBy(fn (Club $c) => mb_strtolower($c->name))
            ->values();
    }

    public function isEligible(User $user): bool
    {
        if ($user->anonymized_at !== null) {
            return false;
        }

        return $this->clubsFor($user)->isNotEmpty();
    }
}
