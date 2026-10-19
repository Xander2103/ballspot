<?php

namespace Tests\Feature\Clubs\Admin;

use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubInviteCode;
use App\Models\User;
use App\Services\Clubs\ClubInviteCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Invite codes tab: create (label / expiry / max uses / role), masked list,
 * revoke, regenerate, printable QR sheet. Full codes never reach the flash,
 * logs or the audit trail.
 */
class AdminClubInviteCodesTest extends TestCase
{
    use CreatesClubs, RefreshDatabase;

    private User $admin;
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->club  = $this->makeClub(['name' => 'Codes FC']);
    }

    private function url(string $suffix = ''): string
    {
        return '/admin/clubs/' . $this->club->id . '/codes' . $suffix;
    }

    public function test_create_a_code_with_options(): void
    {
        $expires = now()->addMonth()->toDateString();

        $res = $this->actingAs($this->admin)->post($this->url(), [
            'label' => 'U15 parents', 'expires_at' => $expires, 'max_uses' => '40', 'grants_role' => 'admin',
        ]);

        $code = ClubInviteCode::firstOrFail();
        $res->assertRedirect('/admin/clubs/' . $this->club->id . '?tab=codes')
            ->assertSessionHas('success', "Invite code {$code->masked()} created.")
            ->assertSessionHas('highlight_code_id', $code->id);

        $this->assertSame('U15 parents', $code->label);
        $this->assertSame(40, $code->max_uses);
        $this->assertSame('admin', $code->grants_role->value);
        $this->assertSame($expires . ' 23:59:59', $code->expires_at->format('Y-m-d H:i:s'));

        $event = ClubAuditEvent::where('action', 'code.created')->firstOrFail();
        $this->assertSame($code->masked(), $event->metadata['code_masked']);
        $this->assertStringNotContainsString($code->code, json_encode($event->metadata));
        $this->assertStringNotContainsString($code->code, (string) session('success'));
    }

    public function test_code_validation_and_limit(): void
    {
        $from = '/admin/clubs/' . $this->club->id . '?tab=codes';

        $this->actingAs($this->admin)->from($from)->post($this->url(), ['grants_role' => 'owner'])
            ->assertSessionHasErrorsIn('newCode', ['grants_role']);
        $this->actingAs($this->admin)->from($from)->post($this->url(), ['grants_role' => 'member', 'expires_at' => now()->subDay()->toDateString(), 'max_uses' => '0'])
            ->assertSessionHasErrorsIn('newCode', ['expires_at', 'max_uses']);
        $this->assertSame(0, ClubInviteCode::count());

        config(['ballspot.clubs.limits.max_active_codes' => 2]);
        $this->actingAs($this->admin)->post($this->url(), ['grants_role' => 'member']);
        $this->actingAs($this->admin)->post($this->url(), ['grants_role' => 'member']);
        $this->actingAs($this->admin)->from($from)->post($this->url(), ['grants_role' => 'member'])
            ->assertSessionHas('error', __('clubs.errors.club_code_limit'));
        $this->assertSame(2, ClubInviteCode::count());

        $this->actingAs($this->admin)->get($from)->assertSee('Limit reached — revoke a code first.');
    }

    public function test_revoke_and_regenerate(): void
    {
        $codes = app(ClubInviteCodeService::class);
        $code  = $codes->create($this->club, ['label' => 'Season', 'max_uses' => 5], $this->admin, 'platform_admin');

        $this->actingAs($this->admin)->post($this->url('/' . $code->id . '/regenerate'))->assertSessionHas('success');
        $new = ClubInviteCode::whereKeyNot($code->id)->firstOrFail();
        $this->assertNotNull($code->fresh()->revoked_at);
        $this->assertSame('Season', $new->label);
        $this->assertSame(5, $new->max_uses);
        $this->assertTrue($new->isUsable());

        $this->actingAs($this->admin)->post($this->url('/' . $new->id . '/revoke'))->assertSessionHas('success');
        $this->assertNotNull($new->fresh()->revoked_at);
        $this->assertSame($this->admin->id, $new->fresh()->revoked_by_user_id);

        $this->assertSame(['code.created', 'code.regenerated', 'code.revoked'], ClubAuditEvent::orderBy('id')->pluck('action')->all());
    }

    public function test_codes_tab_masks_codes_by_default(): void
    {
        $code = app(ClubInviteCodeService::class)->create($this->club, ['label' => 'Parents'], $this->admin, 'platform_admin');

        $html = $this->actingAs($this->admin)->get('/admin/clubs/' . $this->club->id . '?tab=codes')->assertOk()->getContent();

        $this->assertStringContainsString('data-code-masked="' . $code->masked() . '" data-code-full="' . $code->formatted() . '">' . $code->masked() . '</span>', $html);
        foreach (['Show codes', 'New invite code', 'Print QR', 'data-action="regenerate"', 'data-action="revoke"', 'Ownership is never handed out by a code.'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function test_print_sheet_renders_the_qr_payload_and_refuses_unusable_codes(): void
    {
        $code = app(ClubInviteCodeService::class)->create($this->club, ['label' => 'Flyer'], $this->admin, 'platform_admin');

        $res = $this->actingAs($this->admin)->get($this->url('/' . $code->id . '/print'))->assertOk();
        $res->assertHeader('Cache-Control', 'no-store, private');
        $html = $res->getContent();
        $this->assertStringContainsString('data-qr="ballpicker:club:' . $code->code . '"', $html);
        $this->assertSame(6, substr_count($html, 'data-qr="'));
        $this->assertStringContainsString('>' . $code->formatted() . '</div>', $html);
        $this->assertStringContainsString('https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', $html);
        $this->assertStringContainsString('Codes FC', $html);

        app(ClubInviteCodeService::class)->revoke($code, $this->admin, 'platform_admin');
        $revoked = $this->actingAs($this->admin)->get($this->url('/' . $code->id . '/print'))->assertOk()->getContent();
        $this->assertStringContainsString('This code can no longer be used', $revoked);
        $this->assertStringNotContainsString('data-qr=', $revoked);
        $this->assertStringNotContainsString('qrcode.min.js', $revoked);
    }

    public function test_codes_index_route_redirects_to_the_tab(): void
    {
        $this->actingAs($this->admin)->get($this->url())->assertRedirect('/admin/clubs/' . $this->club->id . '?tab=codes');
    }
}
