<?php

namespace Tests\Feature\Clubs\Portal;

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\User;
use App\Services\ClubPortal\ClubPortalSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Shared fixtures for the club portal suites (D2). The Clubs flag is on,
 * both media disks are faked (nothing touches storage/app).
 */
abstract class PortalTestCase extends TestCase
{
    use CreatesClubs;
    use RefreshDatabase;

    protected User $owner;
    protected Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableClubs();
        Storage::fake('club_private');
        Storage::fake('public');

        $this->owner = User::factory()->create(['username' => 'club_owner', 'email' => 'owner@example.test']);
        $this->club  = $this->makeClub(['name' => 'Portal FC', 'slug' => 'portal-fc'], $this->owner);
    }

    /** A user with the given role in the club. */
    protected function userWithRole(ClubRole $role, ?Club $club = null, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $this->addMember($club ?? $this->club, $user, $role);

        return $user;
    }

    /** Act as a user whose portal sign-in (password + code) is complete in this session. */
    protected function portalAs(User $user): static
    {
        $now = now()->getTimestamp();

        return $this->actingAs($user)->withSession([
            ClubPortalSession::VERIFIED_AT   => $now,
            ClubPortalSession::VERIFIED_USER => $user->id,
            ClubPortalSession::LAST_SEEN     => $now,
        ]);
    }

    protected function url(string $suffix = '', ?Club $club = null): string
    {
        return '/club/' . ($club ?? $this->club)->slug . $suffix;
    }

    /** A real JPEG GD can decode; the seed changes the pixels (unique sha256). */
    protected function jpeg(int $seed = 1, int $w = 640, int $h = 480): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, $seed % 256, (int) ($seed / 256) % 256, 90));
        imagefilledrectangle($img, 10, 10, 40, 40, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'portalphoto') . '.jpg';
        file_put_contents($path, $jpeg);

        return new UploadedFile($path, 'photo-' . $seed . '.jpg', 'image/jpeg', null, true);
    }
}
