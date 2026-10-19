<?php

namespace Tests\Feature\Clubs\Foundation;

use App\Services\ContentBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Plan §11.11: private club media (photos + club challenge images on the
 * `club_private` disk) is part of the content backup, next to the public
 * storage copy — otherwise a restore would lose every club challenge image.
 */
class ClubMediaBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/bp-club-backup-' . getmypid() . '-' . uniqid();
        File::ensureDirectoryExists("{$this->tmp}/public/challenges");
        File::ensureDirectoryExists("{$this->tmp}/private/7/photos");
        file_put_contents("{$this->tmp}/public/challenges/a.jpg", 'public');
        file_put_contents("{$this->tmp}/private/7/photos/b.jpg", 'private');

        config([
            'ballspot.backup.root'                => "{$this->tmp}/backups",
            'ballspot.backup.storage_path'        => "{$this->tmp}/public",
            'filesystems.disks.club_private.root' => "{$this->tmp}/private",
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_backup_copies_private_club_media_next_to_public_storage(): void
    {
        $result = app(ContentBackupService::class)->run('test');

        $this->assertFileExists($result['path'] . '/storage/challenges/a.jpg');
        $this->assertSame('private', file_get_contents($result['path'] . '/storage-private-clubs/7/photos/b.jpg'));
        $this->assertContains(
            'Restore club media: copy storage-private-clubs/ back to backend/storage/app/private/clubs/',
            $result['manifest']['notes'],
        );
    }

    public function test_backup_without_private_club_media_still_succeeds(): void
    {
        File::deleteDirectory("{$this->tmp}/private");

        $result = app(ContentBackupService::class)->run('test');

        $this->assertDirectoryDoesNotExist($result['path'] . '/storage-private-clubs');
        $this->assertTrue($result['manifest']['storage_copied']);
    }
}
