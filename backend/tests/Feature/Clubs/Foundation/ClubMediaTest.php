<?php

namespace Tests\Feature\Clubs\Foundation;

use App\Exceptions\ClubDomainException;
use App\Models\Challenge;
use App\Services\Clubs\ClubMediaService;
use App\Support\ChallengeImageUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Private club media (plan §6.7, AT-ISO-06) and the ChallengeImageUrl helper.
 */
class ClubMediaTest extends TestCase
{
    use CreatesClubs;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('club_private');
        Storage::fake('public');
    }

    /** A real JPEG with an APP1/EXIF segment carrying a GPS-like marker. */
    private function jpegWithExif(int $w = 64, int $h = 48): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 40));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = ob_get_clean();

        $tiff    = "MM\x00\x2A\x00\x00\x00\x08\x00\x00\x00\x00\x00\x00" . 'GPS-SECRET-51.05N-3.72E';
        $payload = "Exif\x00\x00" . $tiff;
        $app1    = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        $withExif = substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);

        $path = tempnam(sys_get_temp_dir(), 'exif') . '.jpg';
        file_put_contents($path, $withExif);

        return new UploadedFile($path, 'phone.jpg', 'image/jpeg', null, true);
    }

    public function test_store_image_re_encodes_strips_metadata_and_uses_a_random_private_path(): void
    {
        $club = $this->makeClub();
        $upload = $this->jpegWithExif();
        $this->assertStringContainsString('GPS-SECRET', file_get_contents($upload->getRealPath()));

        $stored = app(ClubMediaService::class)->storeImage($upload, $club, ClubMediaService::KIND_PHOTO);

        $this->assertMatchesRegularExpression('#^' . $club->id . '/photos/[A-Za-z0-9]{40}\.jpg$#', $stored['path']);
        Storage::disk('club_private')->assertExists($stored['path']);
        Storage::disk('public')->assertMissing($stored['path']);

        $bytes = Storage::disk('club_private')->get($stored['path']);
        $this->assertStringNotContainsString('GPS-SECRET', $bytes);
        $this->assertStringNotContainsString('Exif', $bytes);
        $this->assertSame([64, 48, 'image/jpeg'], [$stored['width'], $stored['height'], $stored['mime']]);
        $this->assertSame(strlen($bytes), $stored['bytes']);
        $this->assertSame(hash('sha256', file_get_contents($upload->getRealPath())), $stored['sha256']);
    }

    public function test_store_image_caps_the_longest_edge_and_supports_challenge_kinds(): void
    {
        config(['ballspot.clubs.media.max_edge' => 32]);
        $club = $this->makeClub();

        $stored = app(ClubMediaService::class)->storeImage($this->jpegWithExif(128, 64), $club, ClubMediaService::KIND_CHALLENGE_HIDDEN);

        $this->assertSame([32, 16], [$stored['width'], $stored['height']]);
        $this->assertStringStartsWith($club->id . '/challenges/hidden/', $stored['path']);
        $this->assertStringStartsWith(
            $club->id . '/challenges/original/',
            app(ClubMediaService::class)->storeImage($this->jpegWithExif(), $club, ClubMediaService::KIND_CHALLENGE_ORIGINAL)['path'],
        );
    }

    public function test_non_images_and_oversized_files_are_refused_with_photo_invalid(): void
    {
        $club = $this->makeClub();
        $media = app(ClubMediaService::class);

        foreach ([
            UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "not an image";'),
            UploadedFile::fake()->create('big.jpg', 20000, 'image/jpeg'),
        ] as $file) {
            try {
                $media->storeImage($file, $club, ClubMediaService::KIND_PHOTO);
                $this->fail('expected photo_invalid');
            } catch (ClubDomainException $e) {
                $this->assertSame(ClubDomainException::PHOTO_INVALID, $e->errorCode());
            }
        }

        $this->assertSame([], Storage::disk('club_private')->allFiles());
    }

    public function test_logo_goes_to_the_public_disk_under_a_random_name(): void
    {
        $club = $this->makeClub();

        $path = app(ClubMediaService::class)->storeLogo($this->jpegWithExif(), $club);

        $this->assertMatchesRegularExpression('#^clubs/logos/[A-Za-z0-9]{40}\.(webp|jpg)$#', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringNotContainsString('GPS-SECRET', Storage::disk('public')->get($path));

        app(ClubMediaService::class)->deleteLogo($path);
        Storage::disk('public')->assertMissing($path);
    }

    // ------------------------------------------------------------- signed route

    public function test_a_valid_signed_url_streams_the_file_privately(): void
    {
        $club = $this->makeClub();
        $stored = app(ClubMediaService::class)->storeImage($this->jpegWithExif(), $club, ClubMediaService::KIND_PHOTO);

        $url = app(ClubMediaService::class)->signedUrl($stored['path']);
        $this->assertStringContainsString('/media/clubs/' . $stored['path'], $url);
        $this->assertStringContainsString('signature=', $url);

        $res = $this->get($url);

        $res->assertOk();
        $this->assertStringContainsString('private', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=3600', $res->headers->get('Cache-Control'));
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
    }

    public function test_unsigned_tampered_and_expired_urls_are_forbidden(): void
    {
        $club = $this->makeClub();
        $stored = app(ClubMediaService::class)->storeImage($this->jpegWithExif(), $club, ClubMediaService::KIND_PHOTO);
        $url = app(ClubMediaService::class)->signedUrl($stored['path']);

        $this->get('/media/clubs/' . $stored['path'])->assertForbidden();
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=' . str_repeat('0', 64), $url))->assertForbidden();

        // Same signature, another file: the signature covers the path.
        $other = app(ClubMediaService::class)->storeImage($this->jpegWithExif(), $club, ClubMediaService::KIND_PHOTO);
        $this->get(str_replace($stored['path'], $other['path'], $url))->assertForbidden();

        $this->travel(61)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_traversal_and_missing_files_are_not_found_even_when_signed(): void
    {
        file_put_contents(storage_path('app/private/probe-outside.txt'), 'outside');

        try {
            foreach (['1/../../probe-outside.txt', '1/photos/..%2F..%2Fprobe-outside.txt', '1/photos/missing.jpg'] as $path) {
                $url = URL::temporarySignedRoute('clubs.media', now()->addMinutes(5), ['path' => $path]);
                $res = $this->get($url);
                $this->assertContains($res->status(), [403, 404], $path);
                $this->assertStringNotContainsString('outside', (string) $res->getContent());
            }

            $missing = URL::temporarySignedRoute('clubs.media', now()->addMinutes(5), ['path' => '1/photos/missing.jpg']);
            $this->get($missing)->assertNotFound();
        } finally {
            @unlink(storage_path('app/private/probe-outside.txt'));
        }

        $this->expectException(\InvalidArgumentException::class);
        app(ClubMediaService::class)->signedUrl('1/../../.env');
    }

    public function test_safe_path_rules(): void
    {
        $this->assertTrue(ClubMediaService::isSafePath('12/photos/abc.jpg'));
        foreach (['', '/etc/passwd', '../x', '1/../x', '1\\x', "1/x\0.jpg", '1/x y.jpg'] as $bad) {
            $this->assertFalse(ClubMediaService::isSafePath($bad), $bad);
        }
    }

    // ------------------------------------------------------------- ChallengeImageUrl

    public function test_public_challenge_urls_are_unchanged(): void
    {
        $challenge = $this->makePublicChallenge([
            'hidden_image_path'   => 'challenges/hidden/a.jpg',
            'original_image_path' => 'challenges/original/a.jpg',
        ]);

        $this->assertSame(asset('storage/challenges/hidden/a.jpg'), ChallengeImageUrl::hidden($challenge));
        $this->assertSame(asset('storage/challenges/original/a.jpg'), ChallengeImageUrl::original($challenge));

        $challenge->original_image_path = null;
        $this->assertNull(ChallengeImageUrl::original($challenge));
        $challenge->hidden_image_path = '';
        $this->assertNull(ChallengeImageUrl::hidden($challenge));
    }

    public function test_club_challenge_urls_are_signed_media_urls(): void
    {
        $club = $this->makeClub();
        $challenge = $this->makeClubChallenge($club);

        $hidden = ChallengeImageUrl::hidden($challenge);

        $this->assertStringContainsString('/media/clubs/' . $challenge->hidden_image_path, $hidden);
        $this->assertStringContainsString('signature=', $hidden);
        $this->assertStringNotContainsString('/storage/', $hidden);
        $this->assertStringContainsString('/media/clubs/' . $challenge->original_image_path, ChallengeImageUrl::original($challenge));
    }

    public function test_api_payloads_for_public_challenges_keep_the_storage_url(): void
    {
        $pack = $this->makePublicPack(1);
        /** @var Challenge $challenge */
        $challenge = $pack->challenges()->first();
        $user = \App\Models\User::factory()->create();

        $this->withToken($this->clubToken($user))
            ->getJson('/api/packs/' . $pack->slug)
            ->assertOk()
            ->assertJsonPath('data.challenges.0.hidden_image_url', asset('storage/' . $challenge->hidden_image_path));
    }
}
