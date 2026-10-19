<?php

namespace Tests\Feature\Clubs\Foundation;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Exceptions\ClubDomainException;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubInviteCode;
use App\Models\ClubPhotoSubmission;
use App\Models\User;
use App\Services\Clubs\ClubAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Role matrix basics (plan §6.2, contracts §3.5) and Gate::before scope.
 */
class ClubPolicyTest extends TestCase
{
    use CreatesClubs;
    use RefreshDatabase;

    private Club $club;
    /** @var array<string, User> */
    private array $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = [
            'owner'    => User::factory()->create(),
            'admin'    => User::factory()->create(),
            'member'   => User::factory()->create(),
            'banned'   => User::factory()->create(),
            'outsider' => User::factory()->create(),
            'platform' => User::factory()->create(['is_admin' => true]),
        ];

        $this->club = $this->makeClub([], $this->users['owner']);
        $this->addMember($this->club, $this->users['admin'], ClubRole::Admin);
        $this->addMember($this->club, $this->users['member']);
        $this->addMember($this->club, $this->users['banned'], ClubRole::Member, ClubMemberStatus::Banned);
    }

    private function can(string $who, string $ability, mixed $args = null): bool
    {
        return Gate::forUser($this->users[$who])->allows($ability, $args ?? $this->club);
    }

    private function inArea(string $path): void
    {
        $this->app->instance('request', Request::create($path));
    }

    public function test_member_level_matrix_outside_the_admin_area(): void
    {
        $this->inArea('/api/clubs/x');

        $matrix = [
            //                  owner  admin  member banned outsider platform
            'view'              => [true,  true,  true,  false, false, false],
            'viewMembers'       => [true,  true,  false, false, false, false],
            'leave'             => [false, true,  true,  false, false, false], // sole owner cannot leave
            'play'              => [true,  true,  true,  false, false, false],
            'manageCodes'       => [true,  true,  false, false, false, false],
            'manageMembers'     => [true,  true,  false, false, false, false],
            'manageCompetitions'=> [true,  true,  false, false, false, false],
            'uploadPhotos'      => [true,  true,  false, false, false, false],
            'manageSettings'    => [true,  false, false, false, false, false],
            'transferOwnership' => [true,  false, false, false, false, false],
            'portal.access'     => [true,  true,  false, false, false, false],
            'admin.manage'      => [false, false, false, false, false, true],
        ];

        $who = array_keys($this->users);
        foreach ($matrix as $ability => $expected) {
            foreach ($who as $i => $role) {
                $this->assertSame($expected[$i], $this->can($role, $ability), "{$role} → {$ability}");
            }
        }
    }

    public function test_platform_admins_get_club_abilities_only_in_the_admin_area(): void
    {
        $this->inArea('/admin/clubs/1');
        $this->assertTrue($this->can('platform', 'manageMembers'));
        $this->assertTrue($this->can('platform', 'view'));
        $this->assertFalse($this->can('member', 'manageMembers'), 'members get nothing extra in the admin area');

        $this->inArea('/club/dashboard');
        $this->assertFalse($this->can('platform', 'portal.access'), 'the portal is decided by membership');
        $this->assertTrue($this->can('platform', 'admin.manage'), 'admin.* abilities hold everywhere');

        $this->assertTrue(Gate::forUser($this->users['platform'])->allows('admin.manage'), 'index authorize without a club');
        $this->assertFalse(Gate::forUser($this->users['owner'])->allows('admin.manage'));
    }

    public function test_member_list_visibility_setting_opens_the_list_to_members(): void
    {
        $this->inArea('/api/clubs/x');
        $this->club->update(['settings' => ['member_list_visibility' => 'members']]);

        $this->assertTrue($this->can('member', 'viewMembers'));
        $this->assertFalse($this->can('outsider', 'viewMembers'));
    }

    public function test_suspended_clubs_are_read_only_and_archived_clubs_invisible(): void
    {
        $this->inArea('/api/clubs/x');
        $this->club->update(['status' => ClubStatus::Suspended]);

        $this->assertTrue($this->can('member', 'view'));
        $this->assertFalse($this->can('member', 'play'));
        $this->assertFalse($this->can('owner', 'manageCodes'));
        $this->assertFalse($this->can('owner', 'portal.access'));

        $this->club->update(['status' => ClubStatus::Archived]);
        $this->assertFalse($this->can('member', 'view'));
    }

    public function test_co_owner_may_leave(): void
    {
        $this->inArea('/api/clubs/x');
        $this->addMember($this->club, $this->users['admin'], ClubRole::Owner);

        $this->assertTrue($this->can('owner', 'leave'));
    }

    public function test_competition_code_and_photo_policies(): void
    {
        $this->inArea('/api/clubs/x');
        $draft = ClubCompetition::factory()->create(['club_id' => $this->club->id]);
        $live  = ClubCompetition::factory()->live()->create(['club_id' => $this->club->id]);
        $code  = ClubInviteCode::factory()->create(['club_id' => $this->club->id]);
        $photo = ClubPhotoSubmission::factory()->create(['club_id' => $this->club->id]);

        $this->assertFalse($this->can('member', 'view', $draft), 'drafts are invisible to members');
        $this->assertTrue($this->can('member', 'view', $live));
        $this->assertTrue($this->can('member', 'play', $live));
        $this->assertFalse($this->can('member', 'play', $draft));
        $this->assertFalse($this->can('outsider', 'view', $live));
        $this->assertTrue($this->can('admin', 'manage', $live));
        $this->assertFalse($this->can('member', 'manage', $live));
        $this->assertFalse($this->can('owner', 'void', $live), 'void is platform-only');

        $this->assertTrue($this->can('admin', 'view', $code));
        $this->assertFalse($this->can('member', 'view', $code), 'codes are for club admins only');
        $this->assertTrue($this->can('owner', 'create', [ClubInviteCode::class, $this->club]));
        $this->assertTrue($this->can('owner', 'revoke', $code));

        $this->assertTrue($this->can('admin', 'view', $photo));
        $this->assertFalse($this->can('member', 'view', $photo));
        $this->assertFalse($this->can('owner', 'review', $photo), 'review is a BallPicker task');

        $this->inArea('/admin/club-photos');
        $this->assertTrue($this->can('platform', 'review', $photo));
        $this->assertTrue($this->can('platform', 'void', $live));
    }

    public function test_require_member_answers_404_for_every_non_member(): void
    {
        $this->assertSame($this->users['member']->id, ClubAccess::requireMember($this->club, $this->users['member'])->user_id);

        foreach (['banned', 'outsider', 'platform'] as $who) {
            try {
                ClubAccess::requireMember($this->club, $this->users[$who]);
                $this->fail("{$who} passed requireMember");
            } catch (ClubDomainException $e) {
                $this->assertSame(ClubDomainException::CLUB_NOT_FOUND, $e->errorCode());
                $this->assertSame(404, $e->status());
            }
        }

        $this->club->update(['status' => ClubStatus::Archived]);
        $this->expectExceptionObject(ClubDomainException::notFound());
        ClubAccess::requireMember($this->club, $this->users['member']);
    }

    public function test_require_active_club_and_ability_helpers(): void
    {
        ClubAccess::requireActiveClub($this->club);
        $this->inArea('/api/clubs/x');

        try {
            ClubAccess::requireAbility($this->users['member'], 'viewMembers', $this->club);
            $this->fail('member saw the member list');
        } catch (ClubDomainException $e) {
            $this->assertSame(ClubDomainException::CLUB_FORBIDDEN, $e->errorCode());
            $this->assertSame(403, $e->status());
        }

        $this->club->update(['status' => ClubStatus::Suspended]);
        try {
            ClubAccess::requireActiveClub($this->club);
            $this->fail('suspended club passed');
        } catch (ClubDomainException $e) {
            $this->assertSame(ClubDomainException::CLUB_UNAVAILABLE, $e->errorCode());
            $this->assertSame(423, $e->status());
        }
    }
}
