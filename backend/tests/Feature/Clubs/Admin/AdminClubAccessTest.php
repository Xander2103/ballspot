<?php

namespace Tests\Feature\Clubs\Admin;

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\ClubInviteCode;
use App\Models\User;
use App\Services\Clubs\ClubInviteCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * AT-ADM-01: every central-admin club route is closed to guests (→ login)
 * and to signed-in non-admins (403) — including club owners — and nothing
 * changes. Data-driven over every route of routes/admin_clubs.php.
 */
class AdminClubAccessTest extends TestCase
{
    use CreatesClubs, RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> method + uri template */
    public static function routes(): array
    {
        return [
            'index'              => ['GET', '/admin/clubs'],
            'create'             => ['GET', '/admin/clubs/create'],
            'store'              => ['POST', '/admin/clubs'],
            'show'               => ['GET', '/admin/clubs/{club}'],
            'show tab'           => ['GET', '/admin/clubs/{club}?tab=members'],
            'edit'               => ['GET', '/admin/clubs/{club}/edit'],
            'update'             => ['PUT', '/admin/clubs/{club}'],
            'settings'           => ['PUT', '/admin/clubs/{club}/settings'],
            'suspend'            => ['POST', '/admin/clubs/{club}/suspend'],
            'archive'            => ['POST', '/admin/clubs/{club}/archive'],
            'reactivate'         => ['POST', '/admin/clubs/{club}/reactivate'],
            'destroy'            => ['DELETE', '/admin/clubs/{club}'],
            'pack preset'        => ['GET', '/admin/clubs/{club}/packs/create'],
            'members index'      => ['GET', '/admin/clubs/{club}/members'],
            'members store'      => ['POST', '/admin/clubs/{club}/members'],
            'member role'        => ['PATCH', '/admin/clubs/{club}/members/{member}'],
            'member remove'      => ['POST', '/admin/clubs/{club}/members/{member}/remove'],
            'member ban'         => ['POST', '/admin/clubs/{club}/members/{member}/ban'],
            'member unban'       => ['POST', '/admin/clubs/{club}/members/{member}/unban'],
            'member reactivate'  => ['POST', '/admin/clubs/{club}/members/{member}/reactivate'],
            'transfer ownership' => ['POST', '/admin/clubs/{club}/transfer-ownership'],
            'codes index'        => ['GET', '/admin/clubs/{club}/codes'],
            'codes store'        => ['POST', '/admin/clubs/{club}/codes'],
            'code revoke'        => ['POST', '/admin/clubs/{club}/codes/{code}/revoke'],
            'code regenerate'    => ['POST', '/admin/clubs/{club}/codes/{code}/regenerate'],
            'code print'         => ['GET', '/admin/clubs/{club}/codes/{code}/print'],
            'competitions'       => ['GET', '/admin/club-competitions'],
            'photo review'       => ['GET', '/admin/club-photos'],
            'audit log'          => ['GET', '/admin/club-audit'],
        ];
    }

    /** @return array{0: Club, 1: string} */
    private function fixture(string $template): array
    {
        $owner  = User::factory()->create();
        $club   = $this->makeClub([], $owner);
        $member = $this->addMember($club, User::factory()->create());
        $code   = app(ClubInviteCodeService::class)->create($club, [], User::factory()->create(['is_admin' => true]), 'platform_admin');

        $uri = str_replace(['{club}', '{member}', '{code}'], [$club->id, $member->id, $code->id], $template);

        return [$club, $uri, $owner];
    }

    #[DataProvider('routes')]
    public function test_guests_are_sent_to_the_admin_login(string $method, string $template): void
    {
        [, $uri] = $this->fixture($template);

        $this->call($method, $uri, ['reason' => 'x', 'name' => 'x'])->assertRedirect('/admin/login');
    }

    #[DataProvider('routes')]
    public function test_non_admins_get_403_even_as_club_owner(string $method, string $template): void
    {
        [$club, $uri, $owner] = $this->fixture($template);
        $before = [$club->fresh()->toArray(), ClubInviteCode::count(), $club->members()->get()->toArray()];

        $this->actingAs($owner)->call($method, $uri, ['reason' => 'Because', 'name' => 'Hacked', 'role' => 'owner', 'identifier' => $owner->username])
            ->assertForbidden();

        $this->assertSame($before, [$club->fresh()->toArray(), ClubInviteCode::count(), $club->members()->get()->toArray()]);
    }

    public function test_admins_can_open_every_page_of_a_populated_club(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $club  = $this->makeClub(['city' => 'Gent'], $owner);
        $this->addMember($club, User::factory()->create(), ClubRole::Admin);
        $pack = $this->makeClubPack($club, 2);
        $this->makeCompetition($club, [$pack]);
        \App\Models\ClubPhotoSubmission::factory()->create(['club_id' => $club->id]);
        $code = app(ClubInviteCodeService::class)->create($club, ['label' => 'Parents'], $admin, 'platform_admin');

        foreach (['/admin/clubs', '/admin/clubs/create', "/admin/clubs/{$club->id}/edit", "/admin/clubs/{$club->id}/codes/{$code->id}/print",
                  '/admin/club-competitions', '/admin/club-photos', '/admin/club-audit'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        foreach (['overview', 'members', 'codes', 'packs', 'competitions', 'photos', 'settings', 'audit'] as $tab) {
            $this->actingAs($admin)->get("/admin/clubs/{$club->id}?tab={$tab}")->assertOk()->assertSee($club->name);
        }
    }

    public function test_nested_rows_of_another_club_are_404(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $a = $this->makeClub();
        $b = $this->makeClub();
        $memberOfB = $this->addMember($b, User::factory()->create());
        $codeOfB   = app(ClubInviteCodeService::class)->create($b, [], $admin, 'platform_admin');

        $this->actingAs($admin)->post("/admin/clubs/{$a->id}/members/{$memberOfB->id}/remove")->assertNotFound();
        $this->actingAs($admin)->post("/admin/clubs/{$a->id}/codes/{$codeOfB->id}/revoke")->assertNotFound();
        $this->actingAs($admin)->get("/admin/clubs/{$a->id}/codes/{$codeOfB->id}/print")->assertNotFound();
        $this->assertTrue($memberOfB->fresh()->isActive());
        $this->assertNull($codeOfB->fresh()->revoked_at);
    }
}
