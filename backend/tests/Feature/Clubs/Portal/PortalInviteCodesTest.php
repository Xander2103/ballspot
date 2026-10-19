<?php

namespace Tests\Feature\Clubs\Portal;

use App\Enums\ClubRole;
use App\Models\ClubAuditEvent;
use App\Models\ClubInviteCode;
use App\Models\User;

/**
 * Portal invite codes: create (member codes for admins, admin-granting codes
 * owner-only), revoke, regenerate, printable QR sheet, limits, audit.
 */
class PortalInviteCodesTest extends PortalTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->userWithRole(ClubRole::Admin);
    }

    private function create(User $as, array $data = [])
    {
        return $this->portalAs($as)->from($this->url('/codes'))->post($this->url('/codes'), $data + ['grants_role' => 'member']);
    }

    public function test_admin_creates_a_member_code_with_options(): void
    {
        $this->create($this->admin, ['label' => 'U15 parents', 'max_uses' => 30, 'expires_at' => now()->addWeek()->toDateString()])
            ->assertRedirect($this->url('/codes'))
            ->assertSessionHas('success');

        $code = ClubInviteCode::where('club_id', $this->club->id)->firstOrFail();
        $this->assertSame('U15 parents', $code->label);
        $this->assertSame(30, $code->max_uses);
        $this->assertSame(ClubRole::Member, $code->grants_role);
        $this->assertTrue($code->expires_at->isSameDay(now()->addWeek()));

        $event = ClubAuditEvent::where('action', 'code.created')->firstOrFail();
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, $event->actor_kind);
        $this->assertStringNotContainsString($code->code, json_encode($event->metadata));

        $this->portalAs($this->admin)->get($this->url('/codes'))->assertOk()
            ->assertSee($code->formatted())->assertSee('U15 parents');
    }

    public function test_admin_granting_codes_are_owner_only(): void
    {
        $this->create($this->admin, ['grants_role' => 'admin'])->assertSessionHasErrors('grants_role', null, 'newCode');
        $this->assertSame(0, ClubInviteCode::count());

        $this->create($this->owner, ['grants_role' => 'admin'])->assertSessionHas('success');
        $this->assertSame(ClubRole::Admin, ClubInviteCode::firstOrFail()->grants_role);

        $this->create($this->owner, ['grants_role' => 'owner'])->assertSessionHasErrors('grants_role', null, 'newCode');
    }

    public function test_admin_cannot_see_regenerate_or_print_an_admin_code_but_may_revoke_it(): void
    {
        $this->create($this->owner, ['grants_role' => 'admin']);
        $code = ClubInviteCode::firstOrFail();

        $this->portalAs($this->admin)->get($this->url('/codes'))->assertOk()
            ->assertDontSee($code->formatted())->assertSee('admin code (owner only)');
        $this->portalAs($this->admin)->get($this->url('/codes/' . $code->id . '/print'))->assertForbidden();
        $this->portalAs($this->admin)->post($this->url('/codes/' . $code->id . '/regenerate'))
            ->assertSessionHas('error', __('clubs.errors.club_forbidden'));
        $this->assertSame(1, ClubInviteCode::count());

        $this->portalAs($this->admin)->post($this->url('/codes/' . $code->id . '/revoke'))->assertSessionHas('success');
        $this->assertTrue($code->fresh()->isRevoked());
    }

    public function test_revoke_and_regenerate_member_code(): void
    {
        $this->create($this->admin, ['label' => 'Season']);
        $code = ClubInviteCode::firstOrFail();

        $this->portalAs($this->admin)->post($this->url('/codes/' . $code->id . '/regenerate'))
            ->assertRedirect($this->url('/codes'))->assertSessionHas('success');
        $this->assertTrue($code->fresh()->isRevoked());
        $new = ClubInviteCode::whereNull('revoked_at')->firstOrFail();
        $this->assertSame('Season', $new->label);
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, ClubAuditEvent::where('action', 'code.regenerated')->firstOrFail()->actor_kind);

        $this->portalAs($this->admin)->post($this->url('/codes/' . $new->id . '/revoke'))->assertSessionHas('success');
        $this->assertTrue($new->fresh()->isRevoked());
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, ClubAuditEvent::where('action', 'code.revoked')->firstOrFail()->actor_kind);
    }

    public function test_print_sheet_renders_qr_payload_with_the_pinned_cdn_library(): void
    {
        $this->create($this->admin);
        $code = ClubInviteCode::firstOrFail();

        $response = $this->portalAs($this->admin)->get($this->url('/codes/' . $code->id . '/print'))->assertOk()
            ->assertSee('ballpicker:club:' . $code->code)
            ->assertSee($code->formatted())
            ->assertSee('https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_code_of_another_club_is_404(): void
    {
        $otherOwner = User::factory()->create();
        $other = $this->makeClub(['slug' => 'other-fc'], $otherOwner);
        $foreign = ClubInviteCode::factory()->create(['club_id' => $other->id]);

        $this->portalAs($this->owner)->post($this->url('/codes/' . $foreign->id . '/revoke'))->assertNotFound();
        $this->portalAs($this->owner)->get($this->url('/codes/' . $foreign->id . '/print'))->assertNotFound();
        $this->assertFalse($foreign->fresh()->isRevoked());
    }

    public function test_active_code_limit_is_a_flash_error(): void
    {
        config(['ballspot.clubs.limits.max_active_codes' => 1]);
        $this->create($this->admin)->assertSessionHas('success');

        $this->create($this->admin)->assertRedirect($this->url('/codes'))
            ->assertSessionHas('error', __('clubs.errors.club_code_limit'));
        $this->assertSame(1, ClubInviteCode::count());
    }

    public function test_validation(): void
    {
        $this->create($this->admin, ['expires_at' => now()->subDay()->toDateString(), 'max_uses' => 0, 'label' => str_repeat('x', 61)])
            ->assertSessionHasErrors(['expires_at', 'max_uses', 'label'], null, 'newCode');
        $this->assertSame(0, ClubInviteCode::count());
    }
}
