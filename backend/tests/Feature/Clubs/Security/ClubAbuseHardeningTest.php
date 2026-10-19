<?php

namespace Tests\Feature\Clubs\Security;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\ClubInviteCode;
use App\Models\ClubMember;
use App\Models\ClubPortalLoginCode;
use App\Models\User;
use App\Notifications\ClubPortalLoginCodeNotification;
use App\Services\ClubPortal\ClubPortalCodeException;
use App\Services\ClubPortal\ClubPortalLoginCodeService;
use App\Services\DiagnosticsService;
use App\Services\SportReadinessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Clubs\Api\ClubApiTestCase;

/**
 * Security review 2026-10, findings 3, 4, 5 and 7:
 *  3. the per-IP join limit no longer blocks a team onboarding behind one
 *     NAT; a per-IP FAILED-code counter is the cross-account guessing gate;
 *  4. parallel portal code guesses can only spend the remaining budget;
 *  5. club pack covers are GD re-encoded (no EXIF/GPS), public ones unchanged;
 *  7. catalogue counts exclude club challenges.
 */
class ClubAbuseHardeningTest extends ClubApiTestCase
{
    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function join(User $user, string $code, string $ip = '198.51.100.7')
    {
        return $this->api($user)->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/clubs/join', ['code' => $code]);
    }

    // ------------------------------------------------------------ 3. join limits

    public function test_defaults_are_generous_per_ip_and_strict_on_failures(): void
    {
        $this->assertSame(300, config('ballspot.clubs.join.per_ip_per_hour'));
        $this->assertSame(60, config('ballspot.clubs.join.failures_per_ip_per_hour'));
        $this->assertSame(15, config('ballspot.clubs.join.failures_per_hour'));
    }

    public function test_a_team_onboarding_behind_one_ip_is_not_blocked(): void
    {
        $club = $this->makeClub([], User::factory()->create());
        ClubInviteCode::factory()->create(['club_id' => $club->id, 'code' => 'TEAMCODE', 'max_uses' => null]);

        // 40 players (above the old 30/h per-IP cap) join from the club house Wi-Fi.
        for ($i = 0; $i < 40; $i++) {
            $this->join(User::factory()->create(), 'TEAMCODE')->assertOk()->assertJsonPath('joined', true);
        }
        $this->assertSame(41, ClubMember::where('club_id', $club->id)->count());
    }

    public function test_failed_codes_per_ip_lock_joining_across_accounts(): void
    {
        config(['ballspot.clubs.join.failures_per_ip_per_hour' => 3]);
        $club = $this->makeClub([], User::factory()->create());
        ClubInviteCode::factory()->create(['club_id' => $club->id, 'code' => 'GOODCODE']);

        // Three throwaway accounts, one wrong code each (below their per-user budget).
        foreach (['WRNGAAAA', 'WRNGBBBB', 'WRNGCCCC'] as $guess) {
            $this->join(User::factory()->create(), $guess)->assertStatus(422)->assertJsonPath('code', 'club_code_invalid');
        }

        // The address is locked — for a fresh account and even the right code.
        $fresh  = User::factory()->create();
        $locked = $this->join($fresh, 'GOODCODE')->assertStatus(429)->assertJsonPath('code', 'club_join_locked');
        $this->assertGreaterThan(0, $locked->json('retry_after'));
        $this->assertSame(0, ClubMember::where('user_id', $fresh->id)->count());

        // Another address is not affected.
        $this->join($fresh, 'GOODCODE', '203.0.113.50')->assertOk()->assertJsonPath('joined', true);
    }

    public function test_successful_joins_never_count_as_ip_failures(): void
    {
        config(['ballspot.clubs.join.failures_per_ip_per_hour' => 2]);
        $club = $this->makeClub([], User::factory()->create());
        ClubInviteCode::factory()->create(['club_id' => $club->id, 'code' => 'GOODCODE', 'max_uses' => null]);

        for ($i = 0; $i < 5; $i++) {
            $this->join(User::factory()->create(), 'GOODCODE')->assertOk();
        }
        $this->assertSame(0, app(\App\Services\Clubs\ClubJoinFailureCounter::class)->ipFailures('198.51.100.7'));
    }

    // ------------------------------------------------------------ 4. portal code race

    public function test_portal_code_attempt_is_reserved_atomically_before_the_hash_check(): void
    {
        Notification::fake();
        config(['ballspot.clubs.portal.code_max_attempts' => 3]);
        $user    = User::factory()->create(['email' => 'owner@example.test']);
        $service = app(ClubPortalLoginCodeService::class);
        $record  = $service->start($user, '127.0.0.1', 'phpunit');
        $code    = null;
        Notification::assertSentTo($user, ClubPortalLoginCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        // Simulate parallel requests: between this request's read (attempts
        // 0, looks open) and its compare, the others spent the whole budget.
        ClubPortalLoginCode::retrieved(function (ClubPortalLoginCode $row) {
            if ($row->attempts < 3) {
                ClubPortalLoginCode::whereKey($row->id)->update(['attempts' => 3]);
            }
        });
        Hash::spy();

        try {
            $service->verify($record->challenge_id, $user->id, (string) $code);
            $this->fail('A code was accepted after the attempt budget was spent.');
        } catch (ClubPortalCodeException $e) {
            $this->assertSame(ClubPortalCodeException::LOCKED, $e->reason);
        }
        Hash::shouldNotHaveReceived('check');
        $this->assertSame(3, (int) ClubPortalLoginCode::whereKey($record->id)->value('attempts'));
    }

    public function test_portal_code_budget_counts_exactly_max_attempts(): void
    {
        Notification::fake();
        config(['ballspot.clubs.portal.code_max_attempts' => 2]);
        $user    = User::factory()->create(['email' => 'owner2@example.test']);
        $service = app(ClubPortalLoginCodeService::class);
        $record  = $service->start($user, '127.0.0.1', 'phpunit');
        $code    = null;
        Notification::assertSentTo($user, ClubPortalLoginCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $wrong = $code === '000000' ? '111111' : '000000';

        $reasons = [];
        foreach ([$wrong, $wrong, $code] as $try) {
            try {
                $service->verify($record->challenge_id, $user->id, (string) $try);
                $reasons[] = 'ok';
            } catch (ClubPortalCodeException $e) {
                $reasons[] = $e->reason;
            }
        }
        $this->assertSame([ClubPortalCodeException::WRONG, ClubPortalCodeException::LOCKED, ClubPortalCodeException::LOCKED], $reasons);

        // The right code on the last allowed try still signs in.
        $record = $service->start($user, '127.0.0.1', 'phpunit');
        $codes  = [];
        Notification::assertSentTo($user, ClubPortalLoginCodeNotification::class, function ($n) use (&$codes) {
            $codes[] = $n->code;

            return true;
        });
        $code = end($codes);
        try {
            $service->verify($record->challenge_id, $user->id, $code === '000000' ? '111111' : '000000');
        } catch (ClubPortalCodeException) {
        }
        $this->assertSame($user->id, $service->verify($record->challenge_id, $user->id, (string) $code)->id);
    }

    // ------------------------------------------------------------ 5. pack covers

    /** A real JPEG carrying an APP1/Exif segment with a recognisable marker. */
    private function jpegWithExif(): UploadedFile
    {
        $img = imagecreatetruecolor(32, 24);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();

        $payload = "Exif\0\0" . "MM\0*\0\0\0\x08" . "\0\0" . "\0\0\0\0" . 'GPS-SECRET-51.0543N-3.7174E';
        $app1    = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        $bytes   = substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);

        $base = tempnam(sys_get_temp_dir(), 'cover');
        @unlink($base);
        $path = $base . '.jpg';
        file_put_contents($path, $bytes);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new UploadedFile($path, 'IMG_0001.jpg', 'image/jpeg', null, true);
    }

    private function packForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cover pack ' . uniqid(), 'status' => 'draft', 'visibility' => 'public', 'difficulty' => 'easy',
        ], $overrides);
    }

    public function test_club_pack_covers_are_re_encoded_without_exif_and_public_ones_unchanged(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);
        $club  = $this->makeClub([], User::factory()->create());

        // Club pack: create + update both re-encode under a random name.
        $this->actingAs($admin)->post('/admin/packs', $this->packForm(['club_id' => $club->id, 'cover_image' => $this->jpegWithExif()]))->assertRedirect();
        $pack = ChallengePack::where('club_id', $club->id)->latest('id')->firstOrFail();
        $this->assertMatchesRegularExpression('#^packs/covers/[A-Za-z0-9]{40}\.(webp|jpg)$#', $pack->cover_image_path);
        $stored = Storage::disk('public')->get($pack->cover_image_path);
        $this->assertStringNotContainsString('GPS-SECRET', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertNotFalse(@getimagesizefromstring($stored));

        $old = $pack->cover_image_path;
        $this->actingAs($admin)->put('/admin/packs/' . $pack->id, $this->packForm(['name' => $pack->name, 'cover_image' => $this->jpegWithExif()]))->assertRedirect();
        $pack->refresh();
        $this->assertNotSame($old, $pack->cover_image_path);
        Storage::disk('public')->assertMissing($old);
        $this->assertStringNotContainsString('GPS-SECRET', Storage::disk('public')->get($pack->cover_image_path));

        // An undecodable club cover is refused; the pack keeps its cover.
        $bad = UploadedFile::fake()->createWithContent('bad.jpg', 'not an image at all');
        $this->actingAs($admin)->put('/admin/packs/' . $pack->id, $this->packForm(['name' => $pack->name, 'cover_image' => $bad]));
        $this->assertSame($pack->cover_image_path, $pack->fresh()->cover_image_path);

        // BallPicker pack: the upload is stored as before (no re-encode).
        $this->actingAs($admin)->post('/admin/packs', $this->packForm(['cover_image' => $this->jpegWithExif()]))->assertRedirect();
        $public = ChallengePack::whereNull('club_id')->latest('id')->firstOrFail();
        $this->assertStringStartsWith('packs/covers/', $public->cover_image_path);
        $this->assertStringContainsString('GPS-SECRET', Storage::disk('public')->get($public->cover_image_path));
    }

    // ------------------------------------------------------------ 7. counts

    public function test_sport_readiness_and_diagnostics_totals_exclude_club_challenges(): void
    {
        $club  = $this->makeClub([], User::factory()->create());
        $sport = $this->clubSport();
        $readyBefore = app(SportReadinessService::class)->for($sport)['ready_challenges'];
        $content     = fn () => (fn () => $this->content())->call(app(DiagnosticsService::class));
        $before      = $content();

        $this->makeClubChallenge($club, ['sport_id' => $sport->id]);
        $this->makeClubChallenge($club, ['sport_id' => $sport->id, 'status' => 'draft']);
        $this->makeClubChallenge($club, ['sport_id' => $sport->id, 'status' => 'archived']);

        $this->assertSame($readyBefore, app(SportReadinessService::class)->for($sport)['ready_challenges']);
        $after = $content();
        foreach (['active_ready', 'total', 'draft', 'archived', 'pack_only', 'daily_pool', 'tournament_eligible'] as $key) {
            $this->assertSame($before[$key], $after[$key], "diagnostics `{$key}` counted a club challenge");
        }
        $row = collect($after['per_sport'])->firstWhere('sport_id', $sport->id);
        $this->assertSame(collect($before['per_sport'])->firstWhere('sport_id', $sport->id)['active_ready'] ?? 0, $row['active_ready'] ?? 0);

        // A BallPicker challenge still counts.
        $this->makePublicChallenge(['sport_id' => $sport->id]);
        $this->assertSame($readyBefore + 1, app(SportReadinessService::class)->for($sport)['ready_challenges']);
        $this->assertSame($before['total'] + 1, $content()['total']);
        $this->assertSame(3, Challenge::whereNotNull('club_id')->count());
    }
}
