<?php

namespace Tests\Feature\Clubs\Portal;

use App\Enums\ClubRole;
use App\Models\ClubAuditEvent;
use App\Models\User;
use App\Services\Clubs\ClubAuditLogger;

/**
 * Owner-only settings (joining, member list visibility, competition
 * defaults; never max_members/status) and the club-scoped audit log.
 */
class PortalSettingsAndAuditTest extends PortalTestCase
{
    private function settings(array $overrides = []): array
    {
        return $overrides + [
            'version'                    => $this->club->fresh()->version,
            'join_enabled'               => '0',
            'member_list_visibility'     => 'members',
            'default_replay_mode'        => 'multiple_attempts',
            'default_normal_play_policy' => 'allowed',
        ];
    }

    public function test_owner_updates_settings_audited_as_club_admin(): void
    {
        $this->portalAs($this->owner)->get($this->url('/settings'))->assertOk()->assertSee('Who sees the member list in the app');

        $this->portalAs($this->owner)->put($this->url('/settings'), $this->settings())
            ->assertRedirect($this->url('/settings'))
            ->assertSessionHas('success', 'Settings saved.');

        $club = $this->club->fresh();
        $this->assertFalse($club->join_enabled);
        $this->assertSame('members', $club->setting('member_list_visibility'));
        $this->assertSame('multiple_attempts', $club->setting('default_replay_mode'));
        $this->assertSame('allowed', $club->setting('default_normal_play_policy'));

        $event = ClubAuditEvent::where('action', 'club.settings_changed')->firstOrFail();
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, $event->actor_kind);
    }

    public function test_platform_only_fields_are_ignored(): void
    {
        $this->club->forceFill(['max_members' => 100])->save();

        $this->portalAs($this->owner)->put($this->url('/settings'), $this->settings(['max_members' => 9999, 'status' => 'archived', 'timezone' => 'UTC']));

        $club = $this->club->fresh();
        $this->assertSame(100, $club->max_members);
        $this->assertTrue($club->isActive());
    }

    public function test_admin_cannot_change_settings(): void
    {
        $admin = $this->userWithRole(ClubRole::Admin);

        $this->portalAs($admin)->put($this->url('/settings'), $this->settings())->assertForbidden();
        $this->assertTrue($this->club->fresh()->join_enabled);
    }

    public function test_stale_version_is_refused(): void
    {
        $this->portalAs($this->owner)->from($this->url('/settings'))
            ->put($this->url('/settings'), $this->settings(['version' => $this->club->version + 5]))
            ->assertRedirect($this->url('/settings'))
            ->assertSessionHas('error', __('clubs.errors.stale_version'));
        $this->assertTrue($this->club->fresh()->join_enabled);
    }

    public function test_validation(): void
    {
        $this->portalAs($this->owner)->put($this->url('/settings'), $this->settings(['member_list_visibility' => 'everyone', 'default_replay_mode' => 'x']))
            ->assertSessionHasErrors(['member_list_visibility', 'default_replay_mode']);
    }

    public function test_audit_log_is_club_scoped_and_shows_usernames(): void
    {
        $member = $this->userWithRole(ClubRole::Member, null, ['username' => 'kicked_player', 'email' => 'kicked@example.test']);
        $this->portalAs($this->owner)->post($this->url('/members/' . $this->club->members()->where('user_id', $member->id)->value('id') . '/remove'));

        $otherOwner = User::factory()->create();
        $other = $this->makeClub(['slug' => 'other-fc'], $otherOwner);
        app(ClubAuditLogger::class)->log($other, 'club.updated', $otherOwner, $other, ['name' => 'SECRET OTHER CLUB EVENT']);

        $this->portalAs($this->owner)->get($this->url('/audit'))->assertOk()
            ->assertSee('club_owner')
            ->assertSee('player: kicked_player')
            ->assertSee('Club portal')
            ->assertDontSee('SECRET OTHER CLUB EVENT')
            ->assertDontSee('kicked@example.test');

        $this->portalAs($this->owner)->get($this->url('/audit?action=code.created'))->assertOk()->assertSee('No events match these filters.');
    }

    public function test_dashboard_shows_stats_photos_and_recent_activity(): void
    {
        $this->userWithRole(ClubRole::Member);
        $this->portalAs($this->owner)->post($this->url('/codes'), ['grants_role' => 'member']);

        $this->portalAs($this->owner)->get($this->url())->assertOk()
            ->assertSee('Members')
            ->assertSee('Live competitions')
            ->assertSee('Photo submissions')
            ->assertSee('Recent activity')
            ->assertSee(__('clubs.audit.code.created'));
    }
}
