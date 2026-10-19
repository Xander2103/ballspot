<?php

namespace Tests\Feature\Clubs\Portal;

use App\Enums\ClubRole;
use App\Models\User;

/**
 * `club.portal` middleware matrix: guest, unverified portal session,
 * member, admin, owner, admin of club B on club A, platform admin without a
 * membership, archived / suspended clubs, unknown slugs, scoped children,
 * feature flag.
 */
class PortalAccessMatrixTest extends PortalTestCase
{
    /** @return list<string> */
    private function pages(): array
    {
        return ['', '/members', '/codes', '/photos', '/audit'];
    }

    public function test_guest_is_sent_to_login_for_known_and_unknown_clubs_alike(): void
    {
        foreach ($this->pages() as $page) {
            $this->get($this->url($page))->assertRedirect('/club/login');
        }
        $this->get('/club/no-such-club')->assertRedirect('/club/login');
        $this->get('/club/no-such-club/members')->assertRedirect('/club/login');
        $this->get('/club')->assertRedirect('/club/login');
        $this->post($this->url('/codes'), ['grants_role' => 'member'])->assertRedirect('/club/login');
    }

    public function test_signed_in_without_portal_verification_is_sent_to_login(): void
    {
        $this->actingAs($this->owner)->get($this->url('/members'))->assertRedirect('/club/login');
        $this->actingAs($this->owner)->get('/club/no-such-club')->assertRedirect('/club/login');
    }

    public function test_owner_and_admin_reach_every_page(): void
    {
        $admin = $this->userWithRole(ClubRole::Admin);

        foreach ([$this->owner, $admin] as $user) {
            foreach ($this->pages() as $page) {
                $this->portalAs($user)->get($this->url($page))->assertOk();
            }
        }
    }

    public function test_settings_page_is_owner_only(): void
    {
        $admin = $this->userWithRole(ClubRole::Admin);

        $this->portalAs($this->owner)->get($this->url('/settings'))->assertOk();
        $this->portalAs($admin)->get($this->url('/settings'))->assertForbidden();
    }

    public function test_plain_member_gets_403(): void
    {
        $member = $this->userWithRole(ClubRole::Member);

        foreach ($this->pages() as $page) {
            $this->portalAs($member)->get($this->url($page))->assertForbidden();
        }
    }

    public function test_admin_of_another_club_gets_404(): void
    {
        $otherOwner = User::factory()->create();
        $other = $this->makeClub(['name' => 'Other FC', 'slug' => 'other-fc'], $otherOwner);

        foreach ($this->pages() as $page) {
            $this->portalAs($otherOwner)->get($this->url($page))->assertNotFound();
        }
        $this->portalAs($otherOwner)->get($this->url('', $other))->assertOk();
    }

    public function test_platform_admin_without_membership_gets_404(): void
    {
        $platform = User::factory()->create(['is_admin' => true]);

        foreach ($this->pages() as $page) {
            $this->portalAs($platform)->get($this->url($page))->assertNotFound();
        }
    }

    public function test_platform_admin_who_is_club_admin_may_use_the_portal(): void
    {
        $platform = User::factory()->create(['is_admin' => true]);
        $this->addMember($this->club, $platform, ClubRole::Admin);

        $this->portalAs($platform)->get($this->url('/members'))->assertOk();
        // ...but has no platform powers here: settings stay owner-only.
        $this->portalAs($platform)->get($this->url('/settings'))->assertForbidden();
    }

    public function test_unknown_slug_and_foreign_children_are_404_for_portal_users(): void
    {
        $otherOwner = User::factory()->create();
        $other = $this->makeClub(['slug' => 'other-fc'], $otherOwner);
        $foreign = $other->members()->firstOrFail();

        $this->portalAs($this->owner)->get('/club/no-such-club')->assertNotFound();
        $this->portalAs($this->owner)->post($this->url('/members/' . $foreign->id . '/remove'))->assertNotFound();
        $this->assertTrue($foreign->fresh()->isActive());
    }

    public function test_archived_club_is_404(): void
    {
        $this->club->forceFill(['status' => 'archived'])->save();

        $this->portalAs($this->owner)->get($this->url())->assertNotFound();
    }

    public function test_suspended_club_is_read_only(): void
    {
        $member = $this->userWithRole(ClubRole::Member);
        $this->club->forceFill(['status' => 'suspended'])->save();

        foreach ($this->pages() as $page) {
            $this->portalAs($this->owner)->get($this->url($page))->assertOk()
                ->assertSee('This club is suspended by BallPicker');
        }
        $this->portalAs($this->owner)->get($this->url('/settings'))->assertOk()->assertSee('Read-only while the club is suspended.');

        $this->portalAs($this->owner)->from($this->url('/codes'))->post($this->url('/codes'), ['grants_role' => 'member'])
            ->assertRedirect($this->url('/codes'))
            ->assertSessionHas('error', __('clubs.errors.club_unavailable'));
        $memberRow = $this->club->members()->where('user_id', $member->id)->firstOrFail();
        $this->portalAs($this->owner)->post($this->url('/members/' . $memberRow->id . '/remove'))->assertSessionHas('error');

        $this->assertSame(0, $this->club->inviteCodes()->count());
        $this->assertTrue($memberRow->fresh()->isActive());
    }

    public function test_club_switcher_lists_every_managed_club(): void
    {
        $second = $this->makeClub(['name' => 'Second FC', 'slug' => 'second-fc']);
        $this->addMember($second, $this->owner, ClubRole::Admin);
        $memberOnly = $this->makeClub(['name' => 'Just Member FC', 'slug' => 'just-member']);
        $this->addMember($memberOnly, $this->owner, ClubRole::Member);
        $archived = $this->makeClub(['name' => 'Old FC', 'slug' => 'old-fc', 'status' => 'archived']);
        $this->addMember($archived, $this->owner, ClubRole::Owner);

        $this->portalAs($this->owner)->get('/club')->assertOk()
            ->assertSee('Portal FC')->assertSee('Second FC')
            ->assertDontSee('Just Member FC')->assertDontSee('Old FC');

        $this->portalAs($this->owner)->get($this->url('', $second))->assertOk()
            ->assertSee('Switch club');
    }

    public function test_feature_flag_off_hides_the_portal(): void
    {
        $this->enableClubs(false);

        $this->get('/club/login')->assertNotFound();
        $this->portalAs($this->owner)->get($this->url())->assertNotFound();
    }

    public function test_portal_pages_are_not_cached(): void
    {
        $response = $this->portalAs($this->owner)->get($this->url('/codes'))->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
