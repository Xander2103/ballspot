<?php

namespace Tests\Feature\Clubs\Api;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Enums\PhotoSubmissionStatus;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubInviteCode;
use App\Models\ClubMember;
use App\Models\ClubPhotoSubmission;
use App\Models\User;
use App\Services\Clubs\ClubInviteCodeService;
use App\Services\Clubs\ClubPhotoReviewService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Wave F club-admin API (contracts §9): invite codes, member actions and
 * photo submissions through the member API, with exactly the portal's role
 * rules (ClubPortalMemberRules + ClubPolicy). Matrix: member / admin / owner
 * / outsider / admin of another club; admin-granting codes owner-only; no
 * self-actions; owners untouchable by admins; suspended = read-only (423).
 */
class ClubAdminApiTest extends ClubApiTestCase
{
    private Club $club;
    private User $owner;
    private User $admin;
    private User $member;
    private User $outsider;
    private User $otherClubAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('club_private');
        Storage::fake('public');

        $this->owner  = User::factory()->create(['username' => 'the_owner']);
        $this->club   = $this->makeClub(['name' => 'API FC'], $this->owner);
        $this->admin  = User::factory()->create(['username' => 'the_admin']);
        $this->addMember($this->club, $this->admin, ClubRole::Admin);
        $this->member = User::factory()->create(['username' => 'the_member']);
        $this->addMember($this->club, $this->member);
        $this->outsider = User::factory()->create();

        $other = $this->makeClub([], User::factory()->create());
        $this->otherClubAdmin = User::factory()->create();
        $this->addMember($other, $this->otherClubAdmin, ClubRole::Admin);
    }

    private function code(array $opts = [], ?Club $club = null): ClubInviteCode
    {
        return app(ClubInviteCodeService::class)->create($club ?? $this->club, $opts, $this->owner, 'platform_admin');
    }

    private function jpeg(int $seed = 1): UploadedFile
    {
        $img = imagecreatetruecolor(640, 480);
        imagefill($img, 0, 0, imagecolorallocate($img, $seed % 256, (int) ($seed / 256) % 256, 70));
        ob_start();
        imagejpeg($img, null, 90);
        $path = tempnam(sys_get_temp_dir(), 'apiphoto') . '.jpg';
        file_put_contents($path, (string) ob_get_clean());

        return new UploadedFile($path, 'photo-' . $seed . '.jpg', 'image/jpeg', null, true);
    }

    private function suspend(): void
    {
        $this->club->forceFill(['status' => ClubStatus::Suspended])->save();
        app(\App\Services\Clubs\ClubAccessContext::class)->flush();
    }

    // ------------------------------------------------------------------ abilities

    public function test_detail_abilities_follow_the_role_and_club_status(): void
    {
        $flags = fn (User $u) => $this->api($u)->getJson($this->clubPath($this->club))->assertOk()->json('data.abilities');

        $this->assertSame([true, true, true], array_values(array_intersect_key($flags($this->owner), array_flip(['manage_codes', 'manage_members', 'upload_photos']))));
        $this->assertSame([true, true, true], array_values(array_intersect_key($flags($this->admin), array_flip(['manage_codes', 'manage_members', 'upload_photos']))));
        $this->assertSame([false, false, false], array_values(array_intersect_key($flags($this->member), array_flip(['manage_codes', 'manage_members', 'upload_photos']))));

        $this->suspend();
        $owner = $flags($this->owner);
        $this->assertFalse($owner['manage_codes']);
        $this->assertFalse($owner['manage_members']);
        $this->assertFalse($owner['upload_photos']);
        $this->assertFalse($owner['manage']);
    }

    // ------------------------------------------------------------------ invite codes

    public function test_invite_code_list_is_for_owners_and_admins_usable_first(): void
    {
        $revoked = $this->code(['label' => 'old']);
        app(ClubInviteCodeService::class)->revoke($revoked, $this->owner, 'platform_admin');
        $expired = $this->code(['label' => 'expired']);
        $expired->forceFill(['expires_at' => now()->subDay()])->save();
        $usable  = $this->code(['label' => 'parents', 'max_uses' => 100]);
        $this->code(['label' => 'other club'], $this->makeClub([], User::factory()->create()));

        $res = $this->api($this->admin)->getJson($this->clubPath($this->club, '/invite-codes'))->assertOk();
        $this->assertSame(['parents', 'expired', 'old'], array_column($res->json('data'), 'label'));
        $this->assertSame([true, false, false], array_column($res->json('data'), 'is_usable'));
        $this->assertSame($usable->formatted(), $res->json('data.0.code'));
        $this->assertSame('ballpicker:club:' . $usable->code, $res->json('data.0.qr_payload'));
        $this->assertSame(1, $res->json('meta.active_count'));

        $this->api($this->member)->getJson($this->clubPath($this->club, '/invite-codes'))->assertForbidden()->assertJsonPath('code', 'club_forbidden');
        $this->api($this->outsider)->getJson($this->clubPath($this->club, '/invite-codes'))->assertNotFound()->assertJsonPath('code', 'club_not_found');
        $this->api($this->otherClubAdmin)->getJson($this->clubPath($this->club, '/invite-codes'))->assertNotFound()->assertJsonPath('code', 'club_not_found');
    }

    public function test_admins_see_admin_granting_codes_masked_owners_in_full(): void
    {
        $adminCode = $this->code(['grants_role' => 'admin']);

        $asAdmin = $this->api($this->admin)->getJson($this->clubPath($this->club, '/invite-codes'))->assertOk();
        $this->assertSame($adminCode->masked(), $asAdmin->json('data.0.code'));
        $this->assertNull($asAdmin->json('data.0.qr_payload'));
        $this->assertStringNotContainsString($adminCode->code, $asAdmin->getContent());

        $asOwner = $this->api($this->owner)->getJson($this->clubPath($this->club, '/invite-codes'))->assertOk();
        $this->assertSame($adminCode->formatted(), $asOwner->json('data.0.code'));
    }

    public function test_create_member_code_as_admin_and_admin_code_as_owner_only(): void
    {
        $res = $this->api($this->admin)->postJson($this->clubPath($this->club, '/invite-codes'), [
            'label' => '  U15 parents ', 'max_uses' => 30, 'expires_at' => now()->addWeek()->toISOString(),
        ])->assertCreated();
        $res->assertJsonPath('data.label', 'U15 parents')->assertJsonPath('data.grants_role', 'member')
            ->assertJsonPath('data.max_uses', 30)->assertJsonPath('data.uses_count', 0)->assertJsonPath('data.is_usable', true);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $res->json('data.code'));

        $event = ClubAuditEvent::where('action', 'code.created')->latest('id')->first();
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, $event->actor_kind);
        $this->assertSame($this->admin->id, $event->actor_user_id);

        $this->api($this->admin)->postJson($this->clubPath($this->club, '/invite-codes'), ['grants_role' => 'admin'])
            ->assertForbidden()->assertJsonPath('code', 'club_forbidden');
        $this->assertSame(0, ClubInviteCode::where('grants_role', 'admin')->count());

        $this->api($this->owner)->postJson($this->clubPath($this->club, '/invite-codes'), ['grants_role' => 'admin'])
            ->assertCreated()->assertJsonPath('data.grants_role', 'admin');

        // Ownership is never handed out by a code; the past is not an expiry.
        $this->api($this->owner)->postJson($this->clubPath($this->club, '/invite-codes'), ['grants_role' => 'owner'])
            ->assertUnprocessable()->assertJsonValidationErrors('grants_role');
        $this->api($this->owner)->postJson($this->clubPath($this->club, '/invite-codes'), ['expires_at' => now()->subHour()->toISOString()])
            ->assertUnprocessable()->assertJsonValidationErrors('expires_at');

        $this->api($this->member)->postJson($this->clubPath($this->club, '/invite-codes'))->assertForbidden()->assertJsonPath('code', 'club_forbidden');
        $this->api($this->outsider)->postJson($this->clubPath($this->club, '/invite-codes'), ['grants_role' => 'nope'])
            ->assertNotFound()->assertJsonPath('code', 'club_not_found');
    }

    public function test_code_limit_is_the_service_rule(): void
    {
        config(['ballspot.clubs.limits.max_active_codes' => 1]);
        $this->code();

        $this->api($this->owner)->postJson($this->clubPath($this->club, '/invite-codes'))
            ->assertUnprocessable()->assertJsonPath('code', 'club_code_limit');
    }

    public function test_revoke_any_code_as_admin_but_not_another_clubs_code(): void
    {
        $adminCode = $this->code(['grants_role' => 'admin']);

        $this->api($this->admin)->postJson($this->clubPath($this->club, "/invite-codes/{$adminCode->id}/revoke"))
            ->assertOk()->assertJsonPath('data.is_usable', false)->assertJsonPath('data.code', $adminCode->masked());
        $this->assertNotNull($adminCode->fresh()->revoked_at);
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, ClubAuditEvent::where('action', 'code.revoked')->first()->actor_kind);

        // Idempotent.
        $this->api($this->admin)->postJson($this->clubPath($this->club, "/invite-codes/{$adminCode->id}/revoke"))->assertOk();
        $this->assertSame(1, ClubAuditEvent::where('action', 'code.revoked')->count());

        $foreign = $this->code([], $this->makeClub([], User::factory()->create()));
        $this->api($this->owner)->postJson($this->clubPath($this->club, "/invite-codes/{$foreign->id}/revoke"))
            ->assertNotFound()->assertJsonPath('code', 'club_not_found');
        $this->assertNull($foreign->fresh()->revoked_at);

        $mine = $this->code();
        $this->api($this->member)->postJson($this->clubPath($this->club, "/invite-codes/{$mine->id}/revoke"))->assertForbidden();
        $this->assertNull($mine->fresh()->revoked_at);
    }

    // ------------------------------------------------------------------ members

    public function test_member_list_actions_flags_per_caller(): void
    {
        $other = User::factory()->create(['username' => 'zz_plain']);
        $this->addMember($this->club, $other);
        $this->club->forceFill(['settings' => ['member_list_visibility' => 'members']])->save();

        $byUser = fn (User $caller) => collect($this->api($caller)->getJson($this->clubPath($this->club, '/members'))->assertOk()->json('data'))
            ->keyBy('username')->map(fn ($m) => $m['actions'])->all();

        $owner = $byUser($this->owner);
        $this->assertSame(['change_role' => false, 'remove' => false, 'ban' => false], $owner['the_owner'], 'never on yourself');
        $this->assertSame(['change_role' => true, 'remove' => true, 'ban' => true], $owner['the_admin']);
        $this->assertSame(['change_role' => true, 'remove' => true, 'ban' => true], $owner['the_member']);

        $admin = $byUser($this->admin);
        $this->assertSame(['change_role' => false, 'remove' => false, 'ban' => false], $admin['the_owner'], 'owners only touchable by owners');
        $this->assertSame(['change_role' => false, 'remove' => false, 'ban' => false], $admin['the_admin']);
        $this->assertSame(['change_role' => false, 'remove' => true, 'ban' => true], $admin['the_member'], 'role changes are owner-only');

        foreach ($byUser($this->member) as $actions) {
            $this->assertSame(['change_role' => false, 'remove' => false, 'ban' => false], $actions);
        }

        $this->suspend();
        foreach ($byUser($this->owner) as $actions) {
            $this->assertSame(['change_role' => false, 'remove' => false, 'ban' => false], $actions, 'suspended = read-only');
        }
    }

    public function test_role_change_is_owner_only_admin_member_only(): void
    {
        $path = $this->clubPath($this->club, '/members/' . $this->member->id);

        $this->api($this->admin)->patchJson($path, ['role' => 'admin'])->assertForbidden()->assertJsonPath('code', 'club_forbidden');
        $this->assertSame(ClubRole::Member, $this->membership($this->member)->role);

        $res = $this->api($this->owner)->patchJson($path, ['role' => 'admin'])->assertOk();
        $res->assertJsonPath('data.user_id', $this->member->id)->assertJsonPath('data.username', 'the_member')
            ->assertJsonPath('data.role', 'admin')->assertJsonPath('data.actions', ['change_role' => true, 'remove' => true, 'ban' => true]);
        $this->assertSame(ClubRole::Admin, $this->membership($this->member)->role);
        $event = ClubAuditEvent::where('action', 'member.role_changed')->first();
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, $event->actor_kind);

        $this->api($this->owner)->patchJson($path, ['role' => 'owner'])->assertUnprocessable()->assertJsonValidationErrors('role');
        // The (last) owner cannot be demoted or demote themselves.
        $this->api($this->owner)->patchJson($this->clubPath($this->club, '/members/' . $this->owner->id), ['role' => 'member'])
            ->assertForbidden()->assertJsonPath('code', 'club_forbidden');
        $this->assertSame(ClubRole::Owner, $this->membership($this->owner)->role);

        $this->api($this->member)->patchJson($this->clubPath($this->club, '/members/' . $this->admin->id), ['role' => 'member'])->assertForbidden();
        $this->api($this->outsider)->patchJson($path, ['role' => 'x'])->assertNotFound()->assertJsonPath('code', 'club_not_found');
        // Unknown / not-active target.
        $this->api($this->owner)->patchJson($this->clubPath($this->club, '/members/' . $this->outsider->id), ['role' => 'admin'])
            ->assertNotFound()->assertJsonPath('code', 'club_member_not_found');
        $this->api($this->owner)->patchJson($this->clubPath($this->club, '/members/99999999999999999999999'), ['role' => 'admin'])
            ->assertNotFound()->assertJsonPath('code', 'club_member_not_found');
    }

    public function test_remove_and_ban_follow_the_portal_rules(): void
    {
        // Admins cannot touch owners or themselves.
        $this->api($this->admin)->postJson($this->clubPath($this->club, '/members/' . $this->owner->id . '/remove'))->assertForbidden();
        $this->api($this->admin)->postJson($this->clubPath($this->club, '/members/' . $this->admin->id . '/ban'))->assertForbidden();
        $this->api($this->owner)->postJson($this->clubPath($this->club, '/members/' . $this->owner->id . '/remove'))->assertForbidden();
        $this->assertTrue($this->membership($this->owner)->isActive());

        $this->api($this->admin)->postJson($this->clubPath($this->club, '/members/' . $this->member->id . '/remove'), ['reason' => '  rude '])
            ->assertOk()->assertExactJson(['removed' => true]);
        $removed = $this->membership($this->member);
        $this->assertSame(ClubMemberStatus::Removed, $removed->status);
        $this->assertSame('rude', $removed->removal_reason);
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, ClubAuditEvent::where('action', 'member.removed')->first()->actor_kind);

        // A removed member is no longer addressable.
        $this->api($this->admin)->postJson($this->clubPath($this->club, '/members/' . $this->member->id . '/ban'))
            ->assertNotFound()->assertJsonPath('code', 'club_member_not_found');

        $target = User::factory()->create();
        $this->addMember($this->club, $target);
        $this->api($this->owner)->postJson($this->clubPath($this->club, '/members/' . $target->id . '/ban'))->assertOk()->assertExactJson(['banned' => true]);
        $this->assertSame(ClubMemberStatus::Banned, $this->membership($target)->status);

        // An owner may remove an admin.
        $this->api($this->owner)->postJson($this->clubPath($this->club, '/members/' . $this->admin->id . '/remove'))->assertOk();

        $this->api($this->owner)->postJson($this->clubPath($this->club, '/members/1/remove'), ['reason' => str_repeat('x', 201)])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_members_of_another_club_are_not_addressable(): void
    {
        $this->api($this->owner)->postJson($this->clubPath($this->club, '/members/' . $this->otherClubAdmin->id . '/ban'))
            ->assertNotFound()->assertJsonPath('code', 'club_member_not_found');
        $this->assertSame(1, ClubMember::where('user_id', $this->otherClubAdmin->id)->where('status', 'active')->count());

        // And the other club's admin cannot act here at all.
        $this->api($this->otherClubAdmin)->postJson($this->clubPath($this->club, '/members/' . $this->member->id . '/ban'))
            ->assertNotFound()->assertJsonPath('code', 'club_not_found');
        $this->assertTrue($this->membership($this->member)->isActive());
    }

    // ------------------------------------------------------------------ photos

    public function test_photo_upload_lands_in_the_admin_queue_as_club_upload(): void
    {
        $res = $this->api($this->admin)->post($this->clubPath($this->club, '/photos'), [
            'photo' => $this->jpeg(1), 'rights_confirmed' => '1', 'note' => ' derby ',
        ], ['Accept' => 'application/json'])->assertCreated();

        $res->assertJsonPath('data.status', 'pending')->assertJsonPath('data.club_note', 'derby')->assertJsonPath('data.rejection_reason', null);
        $this->assertStringContainsString('/media/clubs/', (string) $res->json('data.thumbnail_url'));
        $this->assertStringContainsString('signature=', (string) $res->json('data.thumbnail_url'));
        $this->assertStringNotContainsString('"path"', $res->getContent());

        $sub = ClubPhotoSubmission::findOrFail($res->json('data.id'));
        $this->assertSame(ClubPhotoSubmission::SOURCE_CLUB_UPLOAD, $sub->source);
        $this->assertSame(PhotoSubmissionStatus::Pending, $sub->status);
        $this->assertNotNull($sub->rights_confirmed_at);
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, ClubAuditEvent::where('action', 'photo.uploaded')->first()->actor_kind);

        $platform = User::factory()->create(['is_admin' => true]);
        $this->assertSame(1, app(ClubPhotoReviewService::class)->queue([], $platform)->total());
        $this->actingAs($platform)->get('/admin/club-photos')->assertOk()->assertSee('API FC');
    }

    public function test_photo_upload_requires_rights_and_an_image_and_the_role(): void
    {
        $this->api($this->owner)->post($this->clubPath($this->club, '/photos'), ['photo' => $this->jpeg(2)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('rights_confirmed');
        $this->api($this->owner)->post($this->clubPath($this->club, '/photos'), [
            'photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'), 'rights_confirmed' => '1',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');

        $this->api($this->member)->post($this->clubPath($this->club, '/photos'), ['photo' => $this->jpeg(3), 'rights_confirmed' => '1'], ['Accept' => 'application/json'])
            ->assertForbidden()->assertJsonPath('code', 'club_forbidden');

        // Duplicate = the service rule.
        $this->api($this->owner)->post($this->clubPath($this->club, '/photos'), ['photo' => $this->jpeg(4), 'rights_confirmed' => '1'], ['Accept' => 'application/json'])->assertCreated();
        $this->api($this->owner)->post($this->clubPath($this->club, '/photos'), ['photo' => $this->jpeg(4), 'rights_confirmed' => '1'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('code', 'photo_duplicate');

        $this->assertSame(1, ClubPhotoSubmission::count());
    }

    public function test_photo_list_is_the_clubs_own_newest_first(): void
    {
        foreach ([1, 2, 3] as $seed) {
            $this->api($this->admin)->post($this->clubPath($this->club, '/photos'), ['photo' => $this->jpeg(10 + $seed), 'rights_confirmed' => '1'], ['Accept' => 'application/json'])->assertCreated();
        }
        ClubPhotoSubmission::factory()->create(['club_id' => $this->makeClub([], User::factory()->create())->id]);
        $latest = ClubPhotoSubmission::where('club_id', $this->club->id)->orderByDesc('id')->first();
        $latest->forceFill(['status' => PhotoSubmissionStatus::Rejected, 'rejection_reason' => 'Ball not visible'])->save();

        $page1 = $this->api($this->owner)->getJson($this->clubPath($this->club, '/photos?limit=2'))->assertOk();
        $this->assertCount(2, $page1->json('data'));
        $this->assertSame('rejected', $page1->json('data.0.status'));
        $this->assertSame('Ball not visible', $page1->json('data.0.rejection_reason'));
        $page2 = $this->api($this->owner)->getJson($this->clubPath($this->club, '/photos?limit=2&cursor=' . $page1->json('meta.next_cursor')))->assertOk();
        $this->assertCount(1, $page2->json('data'));
        $this->assertNull($page2->json('meta.next_cursor'));

        $this->api($this->member)->getJson($this->clubPath($this->club, '/photos'))->assertForbidden();
    }

    // ------------------------------------------------------------------ suspended

    public function test_suspended_club_is_read_only_for_its_admins(): void
    {
        $code = $this->code();
        $this->suspend();

        $this->api($this->owner)->getJson($this->clubPath($this->club, '/invite-codes'))->assertOk()->assertJsonCount(1, 'data');
        $this->api($this->owner)->getJson($this->clubPath($this->club, '/photos'))->assertOk();

        $writes = [
            ['POST', '/invite-codes', ['grants_role' => 'member']],
            ['POST', "/invite-codes/{$code->id}/revoke", []],
            ['PATCH', '/members/' . $this->member->id, ['role' => 'admin']],
            ['POST', '/members/' . $this->member->id . '/remove', []],
            ['POST', '/members/' . $this->member->id . '/ban', []],
        ];
        foreach ($writes as [$method, $suffix, $body]) {
            $this->api($this->owner)->json($method, $this->clubPath($this->club, $suffix), $body)
                ->assertStatus(423)->assertJsonPath('code', 'club_unavailable');
        }
        $this->api($this->owner)->post($this->clubPath($this->club, '/photos'), ['photo' => $this->jpeg(30), 'rights_confirmed' => '1'], ['Accept' => 'application/json'])
            ->assertStatus(423)->assertJsonPath('code', 'club_unavailable');
        // A plain member is refused for the role first.
        $this->api($this->member)->postJson($this->clubPath($this->club, '/invite-codes'))->assertForbidden();

        $this->assertNull($code->fresh()->revoked_at);
        $this->assertTrue($this->membership($this->member)->isActive());
        $this->assertSame(ClubRole::Member, $this->membership($this->member)->role);
        $this->assertSame(0, ClubPhotoSubmission::count());
    }

    public function test_platform_admin_without_membership_gets_404(): void
    {
        $platform = User::factory()->create(['is_admin' => true]);

        $this->api($platform)->getJson($this->clubPath($this->club, '/invite-codes'))->assertNotFound()->assertJsonPath('code', 'club_not_found');
        $this->api($platform)->postJson($this->clubPath($this->club, '/members/' . $this->member->id . '/ban'))->assertNotFound();
    }

    private function membership(User $user): ClubMember
    {
        return ClubMember::where('club_id', $this->club->id)->where('user_id', $user->id)->firstOrFail();
    }
}
