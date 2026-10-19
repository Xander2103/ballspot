<?php

namespace Tests\Feature\Clubs\Foundation;

use App\Enums\ClubRole;
use App\Exceptions\ClubDomainException;
use App\Models\ClubAuditEvent;
use App\Models\ClubInviteCode;
use App\Models\User;
use App\Services\Clubs\ClubInviteCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

class ClubInviteCodeServiceTest extends TestCase
{
    use CreatesClubs;
    use RefreshDatabase;

    private ClubInviteCodeService $codes;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->codes = app(ClubInviteCodeService::class);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_generated_codes_use_the_unambiguous_alphabet_and_length(): void
    {
        $club = $this->makeClub();

        for ($i = 0; $i < 20; $i++) {
            $code = $this->codes->create($club, [], $this->admin, 'platform_admin');
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/', $code->code);
            $this->assertSame(ClubRole::Member, $code->grants_role);
            $this->assertSame($this->admin->id, $code->created_by_user_id);
            $code->forceFill(['revoked_at' => now()])->save(); // stay under the active-code cap
        }
    }

    public function test_create_stores_options_and_audits_a_masked_code_only(): void
    {
        $club = $this->makeClub();

        $code = $this->codes->create($club, [
            'label' => 'U15 parents', 'max_uses' => 40, 'expires_at' => now()->addMonth(), 'grants_role' => 'admin',
        ], $this->admin, 'platform_admin');

        $this->assertSame('U15 parents', $code->label);
        $this->assertSame(40, $code->max_uses);
        $this->assertSame(ClubRole::Admin, $code->grants_role);

        $event = ClubAuditEvent::where('action', 'code.created')->firstOrFail();
        $this->assertSame($code->masked(), $event->metadata['code_masked']);
        $this->assertStringNotContainsString($code->code, json_encode($event->metadata));
        $this->assertSame(ClubInviteCode::class, $event->subject_type);
    }

    public function test_codes_never_grant_ownership(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->codes->create($this->makeClub(), ['grants_role' => ClubRole::Owner], $this->admin, 'platform_admin');
    }

    public function test_active_code_limit(): void
    {
        config(['ballspot.clubs.limits.max_active_codes' => 2]);
        $club = $this->makeClub();
        // Revoked / expired / exhausted codes do not count.
        ClubInviteCode::factory()->revoked()->create(['club_id' => $club->id]);
        ClubInviteCode::factory()->expired()->create(['club_id' => $club->id]);
        ClubInviteCode::factory()->exhausted()->create(['club_id' => $club->id]);

        $this->codes->create($club, [], $this->admin, 'platform_admin');
        $this->codes->create($club, [], $this->admin, 'platform_admin');

        try {
            $this->codes->create($club, [], $this->admin, 'platform_admin');
            $this->fail('expected club_code_limit');
        } catch (ClubDomainException $e) {
            $this->assertSame(ClubDomainException::CLUB_CODE_LIMIT, $e->errorCode());
            $this->assertSame(422, $e->status());
        }
    }

    public function test_revoke_is_idempotent_and_makes_the_code_unusable(): void
    {
        $club = $this->makeClub();
        $code = $this->codes->create($club, [], $this->admin, 'platform_admin');

        $this->codes->revoke($code, $this->admin, 'platform_admin');
        $this->codes->revoke($code, $this->admin, 'platform_admin');

        $this->assertNotNull($code->revoked_at);
        $this->assertSame($this->admin->id, $code->fresh()->revoked_by_user_id);
        $this->assertNull($this->codes->resolveUsable($code->code));
        $this->assertSame(1, ClubAuditEvent::where('action', 'code.revoked')->count());
    }

    public function test_regenerate_revokes_and_issues_a_new_code_with_the_same_options(): void
    {
        config(['ballspot.clubs.limits.max_active_codes' => 1]);
        $club = $this->makeClub();
        $old  = $this->codes->create($club, ['label' => 'Season', 'max_uses' => 5, 'grants_role' => 'admin'], $this->admin, 'platform_admin');

        // Allowed at the cap: it replaces the code one-for-one.
        $new = $this->codes->regenerate($old, $this->admin, 'platform_admin');

        $this->assertNotSame($old->code, $new->code);
        $this->assertTrue($old->fresh()->isRevoked());
        $this->assertSame(['Season', 5, ClubRole::Admin], [$new->label, $new->max_uses, $new->grants_role]);
        $this->assertSame($old->id, ClubAuditEvent::where('action', 'code.regenerated')->first()->metadata['replaced_code_id']);
        $this->assertSame($new->id, $this->codes->resolveUsable($new->code)?->id);
    }

    public function test_resolve_usable_respects_the_club_status_and_join_switch(): void
    {
        $club = $this->makeClub(['join_enabled' => false]);
        $code = ClubInviteCode::factory()->create(['club_id' => $club->id]);

        $this->assertNull($this->codes->resolveUsable($code->code));
        $this->assertSame(ClubInviteCodeService::REASON_JOIN_DISABLED, $this->codes->unusableReason($code->fresh()));

        $club->update(['join_enabled' => true]);
        $this->assertSame($code->id, $this->codes->resolveUsable($code->code)?->id);
        $this->assertNull($this->codes->resolveUsable(''));
        $this->assertNull($this->codes->resolveUsable(str_repeat('A', 40)));
    }
}
