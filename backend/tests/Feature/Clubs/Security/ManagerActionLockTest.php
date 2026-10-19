<?php

namespace Tests\Feature\Clubs\Security;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Exceptions\ClubDomainException;
use App\Models\ClubMember;
use App\Models\User;
use App\Services\ClubPortal\ClubPortalMemberRules;
use App\Services\Clubs\ClubMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Wave F review L1: who-may-act-on-whom is re-checked under the club lock on
 * fresh rows, so an admin demoted (or a target promoted to owner) after the
 * request's first check cannot still complete the action.
 */
class ManagerActionLockTest extends TestCase
{
    use CreatesClubs, RefreshDatabase;

    public function test_an_admin_demoted_after_the_first_check_cannot_ban(): void
    {
        $owner  = User::factory()->create();
        $club   = $this->makeClub([], $owner);
        $admin  = User::factory()->create();
        $player = User::factory()->create();
        $this->addMember($club, $admin, ClubRole::Admin);
        $target = $this->addMember($club, $player);

        // The request already passed its checks; meanwhile the owner demotes the admin.
        ClubMember::where('club_id', $club->id)->where('user_id', $admin->id)->update(['role' => ClubRole::Member->value]);

        try {
            app(ClubMembershipService::class)->actAsManager($club, $admin, $target, ClubPortalMemberRules::BAN,
                fn (ClubMember $t) => app(ClubMembershipService::class)->ban($t, $admin, 'club_admin', null));
            $this->fail('the stale admin must be refused');
        } catch (ClubDomainException $e) {
            $this->assertSame(ClubDomainException::CLUB_FORBIDDEN, $e->errorCode());
        }

        $this->assertSame(ClubMemberStatus::Active, $target->fresh()->status);
    }

    public function test_a_target_promoted_to_owner_meanwhile_cannot_be_removed_by_an_admin(): void
    {
        $owner = User::factory()->create();
        $club  = $this->makeClub([], $owner);
        $admin = User::factory()->create();
        $other = User::factory()->create();
        $this->addMember($club, $admin, ClubRole::Admin);
        $target = $this->addMember($club, $other);

        ClubMember::whereKey($target->id)->update(['role' => ClubRole::Owner->value]);

        $this->expectException(ClubDomainException::class);
        app(ClubMembershipService::class)->actAsManager($club, $admin, $target, ClubPortalMemberRules::REMOVE,
            fn (ClubMember $t) => app(ClubMembershipService::class)->remove($t, $admin, 'club_admin', null));
    }

    public function test_a_valid_action_still_goes_through(): void
    {
        $owner  = User::factory()->create();
        $club   = $this->makeClub([], $owner);
        $player = User::factory()->create();
        $target = $this->addMember($club, $player);

        app(ClubMembershipService::class)->actAsManager($club, $owner, $target, ClubPortalMemberRules::REMOVE,
            fn (ClubMember $t) => app(ClubMembershipService::class)->remove($t, $owner, 'club_admin', null));

        $this->assertSame(ClubMemberStatus::Removed, $target->fresh()->status);
    }
}
