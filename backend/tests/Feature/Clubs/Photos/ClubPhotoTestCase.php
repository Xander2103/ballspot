<?php

namespace Tests\Feature\Clubs\Photos;

use App\Models\Club;
use App\Models\ClubPhotoSubmission;
use App\Models\User;
use App\Services\Clubs\ClubPhotoReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Shared fixtures for the photo workflow suites (D1). Both disks are faked:
 * nothing touches storage/app.
 */
abstract class ClubPhotoTestCase extends TestCase
{
    use CreatesClubs;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('club_private');
        Storage::fake('public');
        $this->admin = User::factory()->create(['is_admin' => true, 'username' => 'reviewer']);
        $this->clubSport();
    }

    protected function service(): ClubPhotoReviewService
    {
        return app(ClubPhotoReviewService::class);
    }

    /**
     * A real JPEG (decodable by GD). $seed changes the pixels so two calls
     * with different seeds never collide on sha256; the same seed gives the
     * same bytes (duplicate tests). With $exif an APP1 segment carrying a
     * GPS-like marker is injected.
     */
    protected function jpeg(int $seed = 1, int $w = 640, int $h = 480, bool $exif = true, string $name = 'photo.jpg'): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, $seed % 256, (int) ($seed / 256) % 256, 90));
        imagefilledrectangle($img, 10, 10, 40, 40, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();

        if ($exif) {
            $tiff    = "MM\x00\x2A\x00\x00\x00\x08\x00\x00\x00\x00\x00\x00" . 'GPS-SECRET-51.05N-3.72E';
            $payload = "Exif\x00\x00" . $tiff;
            $app1    = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
            $jpeg    = substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
        }

        $path = tempnam(sys_get_temp_dir(), 'clubphoto') . '.jpg';
        file_put_contents($path, $jpeg);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    protected function submitPhoto(Club $club, int $seed = 1, string $source = ClubPhotoSubmission::SOURCE_ADMIN_UPLOAD, ?User $uploader = null): ClubPhotoSubmission
    {
        return $this->service()->submit($club, $this->jpeg($seed), $uploader ?? $this->admin, $source, 'derby goal min 89', true,
            $source === ClubPhotoSubmission::SOURCE_CLUB_UPLOAD ? 'club_admin' : 'platform_admin');
    }

    /** @return array<string, mixed> */
    protected function challengeData(array $overrides = []): array
    {
        return array_merge([
            'title'        => 'Derby goal',
            'difficulty'   => 'medium',
            'status'       => 'active',
            'ball_x_ratio' => '0,4321',
            'ball_y_ratio' => '0.65',
        ], $overrides);
    }
}
