<?php

namespace Tests\Feature\Clubs\Api;

use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Models\Club;
use App\Models\User;
use App\Services\Clubs\ClubInviteCodeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsJsonShape;

/**
 * Contract guard for the Wave F endpoints (contracts §9) the app's
 * club-admin and report screens call: every key with its JSON type and
 * nullability, the cursor meta, and the `{message, code}` error bodies.
 * Change a payload → change the TS type, this schema and §9 together.
 */
class ClubAdminContractShapeTest extends ClubApiTestCase
{
    use AssertsJsonShape;

    private Club $club;
    private User $owner;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('club_private');
        Storage::fake('public');
        $this->owner  = User::factory()->create(['username' => 'shape_owner']);
        $this->club   = $this->makeClub([], $this->owner);
        $this->member = User::factory()->create(['username' => 'shape_member']);
        $this->addMember($this->club, $this->member);
    }

    private static function cursorMeta(): array
    {
        return ['next_cursor' => '?string', 'per_page' => 'int'];
    }

    private static function inviteCode(): array
    {
        return [
            'id' => 'int', 'code' => 'string', 'qr_payload' => '?string', 'label' => '?string',
            'grants_role' => 'enum:member|admin', 'uses_count' => 'int', 'max_uses' => '?int',
            'expires_at' => '?iso', 'revoked_at' => '?iso', 'is_usable' => 'bool', 'created_at' => 'iso',
        ];
    }

    private static function member(): array
    {
        return [
            'user_id' => 'int', 'username' => 'string', 'avatar_url' => '?string', 'role' => 'enum:owner|admin|member',
            'joined_at' => '?iso', 'actions' => ['change_role' => 'bool', 'remove' => 'bool', 'ban' => 'bool'],
        ];
    }

    private static function photo(): array
    {
        return [
            'id' => 'int', 'status' => 'enum:pending|in_review|in_preparation|converted|rejected',
            'club_note' => '?string', 'rejection_reason' => '?string', 'thumbnail_url' => '?string', 'created_at' => 'iso',
        ];
    }

    private function shape(TestResponse $res, array $spec): array
    {
        $json = $res->json();
        $this->assertShape($json, $spec);

        return $json;
    }

    private function assertError(TestResponse $res, int $status, string $code): void
    {
        $res->assertStatus($status);
        $this->assertShape($res->json(), ['message' => 'string', 'code' => 'enum:' . $code]);
    }

    private function jpeg(): UploadedFile
    {
        $img = imagecreatetruecolor(400, 400);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 60));
        ob_start();
        imagejpeg($img, null, 85);
        $path = tempnam(sys_get_temp_dir(), 'shape') . '.jpg';
        file_put_contents($path, (string) ob_get_clean());

        return new UploadedFile($path, 'shape.jpg', 'image/jpeg', null, true);
    }

    public function test_club_admin_payloads_match_the_contract(): void
    {
        app(ClubInviteCodeService::class)->create($this->club, ['label' => 'seed', 'max_uses' => 5, 'expires_at' => now()->addDay()], $this->owner, 'platform_admin');

        // Detail abilities.
        $this->shape($this->api($this->owner)->getJson($this->clubPath($this->club))->assertOk(), ['data' => ['abilities' => [
            'view_members' => 'bool', 'leave' => 'bool', 'manage' => 'bool', 'manage_codes' => 'bool', 'manage_members' => 'bool', 'upload_photos' => 'bool',
        ]]]);

        // Invite codes.
        $list = $this->shape($this->api($this->owner)->getJson($this->clubPath($this->club, '/invite-codes'))->assertOk(), [
            'data' => self::listOf(self::inviteCode()),
            'meta' => self::cursorMeta() + ['active_count' => 'int', 'limit' => 'int'],
        ]);
        $this->assertNotEmpty($list['data']);
        $created = $this->shape($this->api($this->owner)->postJson($this->clubPath($this->club, '/invite-codes'), ['grants_role' => 'admin'])->assertCreated(), ['data' => self::inviteCode()]);
        $this->assertSame('ballpicker:club:' . str_replace('-', '', $created['data']['code']), $created['data']['qr_payload']);
        $this->shape($this->api($this->owner)->postJson($this->clubPath($this->club, '/invite-codes/' . $created['data']['id'] . '/revoke'))->assertOk(), ['data' => self::inviteCode()]);

        // Members + actions.
        $members = $this->shape($this->api($this->owner)->getJson($this->clubPath($this->club, '/members'))->assertOk(), [
            'data' => self::listOf(self::member()), 'meta' => self::cursorMeta() + ['total' => 'int'],
        ]);
        $this->assertCount(2, $members['data']);
        $this->shape($this->api($this->owner)->patchJson($this->clubPath($this->club, '/members/' . $this->member->id), ['role' => 'admin'])->assertOk(), ['data' => self::member()]);
        $this->assertSame(['removed' => true], $this->api($this->owner)->postJson($this->clubPath($this->club, '/members/' . $this->member->id . '/remove'))->assertOk()->json());
        $other = User::factory()->create();
        $this->addMember($this->club, $other);
        $this->assertSame(['banned' => true], $this->api($this->owner)->postJson($this->clubPath($this->club, '/members/' . $other->id . '/ban'))->assertOk()->json());

        // Photos.
        $this->shape($this->api($this->owner)->post($this->clubPath($this->club, '/photos'), ['photo' => $this->jpeg(), 'rights_confirmed' => 'true'], ['Accept' => 'application/json'])->assertCreated(), ['data' => self::photo()]);
        $photos = $this->shape($this->api($this->owner)->getJson($this->clubPath($this->club, '/photos'))->assertOk(), ['data' => self::listOf(self::photo()), 'meta' => self::cursorMeta()]);
        $this->assertIsString($photos['data'][0]['thumbnail_url']);

        // Reports (any member).
        $reporter = User::factory()->create();
        $this->addMember($this->club, $reporter);
        $this->shape($this->api($reporter)->postJson($this->clubPath($this->club, '/reports'), ['subject_type' => 'club', 'reason' => 'spam'])->assertCreated(), [
            'data' => ['id' => 'int', 'status' => 'enum:open'],
        ]);

        // Notification settings category.
        $this->shape($this->api($reporter)->getJson('/api/me/notification-settings')->assertOk(), [
            'daily_reminder_enabled' => 'bool', 'tournament_reminder_enabled' => 'bool', 'admin_notifications_enabled' => 'bool',
            'club_updates_enabled' => 'bool', 'reminder_time' => 'string', 'timezone' => '?string', 'daily_reminder_push_active' => 'bool',
        ]);
    }

    public function test_error_bodies_carry_the_codes_the_app_maps(): void
    {
        $admin = User::factory()->create();
        $this->addMember($this->club, $admin, ClubRole::Admin);
        $outsider = User::factory()->create();

        $this->assertError($this->api($outsider)->getJson($this->clubPath($this->club, '/invite-codes')), 404, 'club_not_found');
        $this->assertError($this->api($this->member)->getJson($this->clubPath($this->club, '/invite-codes')), 403, 'club_forbidden');
        $this->assertError($this->api($admin)->postJson($this->clubPath($this->club, '/invite-codes'), ['grants_role' => 'admin']), 403, 'club_forbidden');
        $this->assertError($this->api($admin)->postJson($this->clubPath($this->club, '/members/' . $this->owner->id . '/ban')), 403, 'club_forbidden');
        $this->assertError($this->api($this->owner)->postJson($this->clubPath($this->club, '/members/' . $outsider->id . '/ban')), 404, 'club_member_not_found');

        $this->club->forceFill(['status' => ClubStatus::Suspended])->save();
        app(\App\Services\Clubs\ClubAccessContext::class)->flush();
        $this->assertError($this->api($this->owner)->postJson($this->clubPath($this->club, '/invite-codes')), 423, 'club_unavailable');
    }

    public function test_cursor_lists_walk_to_the_end_without_duplicates(): void
    {
        $codes = app(ClubInviteCodeService::class);
        for ($i = 0; $i < 5; $i++) {
            $codes->create($this->club, ['label' => "c{$i}"], $this->owner, 'platform_admin');
        }
        $revoked = $codes->create($this->club, ['label' => 'revoked'], $this->owner, 'platform_admin');
        $codes->revoke($revoked, $this->owner, 'platform_admin');

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $res = $this->api($this->owner)->getJson($this->clubPath($this->club, '/invite-codes?limit=2' . ($cursor ? '&cursor=' . $cursor : '')))->assertOk();
            foreach ($res->json('data') as $row) {
                $seen[] = $row['id'];
            }
            $cursor = $res->json('meta.next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertCount(6, $seen);
        $this->assertSame($seen, array_values(array_unique($seen)));
        $this->assertSame($revoked->id, end($seen), 'revoked codes come last');

        $this->api($this->owner)->getJson($this->clubPath($this->club, '/invite-codes?cursor=broken'))->assertUnprocessable()->assertJsonValidationErrors('cursor');
    }
}
