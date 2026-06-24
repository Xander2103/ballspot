<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengeCategory;
use App\Models\Sport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentSafetyTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
    }

    private function challenge(Sport $sport, string $hiddenPath = 'challenges/hidden/test.jpg'): Challenge
    {
        return Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Test Challenge',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => $hiddenPath,
        ]);
    }

    // ──────────────────────────────────────────────
    // backup-content command
    // ──────────────────────────────────────────────

    public function test_backup_command_creates_manifest(): void
    {
        $this->sport();
        $this->challenge($this->sport());

        $exitCode = Artisan::call('ballspot:backup-content');
        $this->assertSame(0, $exitCode);

        $backupRoot = base_path('../backups/ballspot-content');
        $this->assertDirectoryExists($backupRoot);

        $folders = glob("{$backupRoot}/*", GLOB_ONLYDIR);
        $this->assertNotEmpty($folders, 'No backup folder was created');

        $latest       = end($folders);
        $manifestPath = "{$latest}/manifest.json";
        $this->assertFileExists($manifestPath);

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assertArrayHasKey('created_at', $manifest);
        $this->assertArrayHasKey('app_name', $manifest);
        $this->assertArrayHasKey('database_copied', $manifest);
        $this->assertArrayHasKey('storage_copied', $manifest);
        $this->assertArrayHasKey('challenge_count', $manifest);
        $this->assertArrayHasKey('daily_challenge_count', $manifest);
        $this->assertArrayHasKey('file_count', $manifest);
        $this->assertSame('BallSpot', $manifest['app_name']);

        $this->removeDir($backupRoot);
    }

    public function test_backup_command_exports_challenges_json(): void
    {
        $sport = $this->sport();
        $this->challenge($sport, 'challenges/hidden/backup-test.jpg');

        Artisan::call('ballspot:backup-content');

        $backupRoot = base_path('../backups/ballspot-content');
        $folders    = glob("{$backupRoot}/*", GLOB_ONLYDIR);
        $latest     = end($folders);

        $this->assertFileExists("{$latest}/challenges.json");

        $challenges = json_decode(file_get_contents("{$latest}/challenges.json"), true);
        $this->assertNotEmpty($challenges);
        $this->assertArrayHasKey('title', $challenges[0]);
        $this->assertArrayHasKey('ball_x_ratio', $challenges[0]);

        $this->removeDir($backupRoot);
    }

    // ──────────────────────────────────────────────
    // recover-challenges command  (uses Storage::fake)
    // ──────────────────────────────────────────────

    public function test_recover_dry_run_does_not_create_records(): void
    {
        Storage::fake('public');

        $this->sport();
        Storage::disk('public')->put('challenges/hidden/orphan.jpg', 'fake-image-data');

        $countBefore = Challenge::count();

        Artisan::call('ballspot:recover-challenges', ['--dry-run' => true]);

        $this->assertSame($countBefore, Challenge::count(), 'Dry run should not create records');
    }

    public function test_recover_creates_draft_for_orphaned_image(): void
    {
        Storage::fake('public');

        $sport = $this->sport();
        ChallengeCategory::firstOrCreate(
            ['sport_id' => $sport->id, 'slug' => 'general'],
            ['name' => 'General', 'sort_order' => 0, 'is_active' => true]
        );

        Storage::disk('public')->put('challenges/hidden/orphan2.jpg', 'fake-image-data');

        $countBefore = Challenge::count();

        Artisan::call('ballspot:recover-challenges');

        $this->assertGreaterThan($countBefore, Challenge::count(), 'A draft challenge should have been created');

        $draft = Challenge::where('hidden_image_path', 'challenges/hidden/orphan2.jpg')->first();
        $this->assertNotNull($draft);
        $this->assertSame('draft', $draft->status);
        $this->assertSame(0.5, (float) $draft->ball_x_ratio);
    }

    public function test_recover_does_not_create_record_for_referenced_image(): void
    {
        Storage::fake('public');

        $sport     = $this->sport();
        $imagePath = 'challenges/hidden/referenced.jpg';

        Storage::disk('public')->put($imagePath, 'fake-image-data');
        $this->challenge($sport, $imagePath);

        $countBefore = Challenge::count();

        Artisan::call('ballspot:recover-challenges');

        $this->assertSame($countBefore, Challenge::count(), 'Referenced image should not produce a duplicate record');
    }

    // ──────────────────────────────────────────────
    // Seeder idempotency
    // ──────────────────────────────────────────────

    public function test_challenge_seeder_does_not_duplicate_on_second_run(): void
    {
        // Seed the core data (sport, category, challenges) twice; expect no duplication
        Artisan::call('db:seed', ['--class' => 'SportSeeder']);
        Artisan::call('db:seed', ['--class' => 'ChallengeCategorySeeder']);
        Artisan::call('db:seed', ['--class' => 'ChallengeSeeder']);
        $countAfterFirst = Challenge::count();

        Artisan::call('db:seed', ['--class' => 'ChallengeSeeder']);
        $countAfterSecond = Challenge::count();

        $this->assertSame($countAfterFirst, $countAfterSecond, 'Re-seeding ChallengeSeeder must not duplicate challenges');
    }

    public function test_daily_challenge_seeder_is_idempotent(): void
    {
        // Run DailyChallengeSeeder twice; expect no unique constraint violation
        Artisan::call('db:seed', ['--class' => 'SportSeeder']);
        Artisan::call('db:seed', ['--class' => 'ChallengeCategorySeeder']);
        Artisan::call('db:seed', ['--class' => 'ChallengeSeeder']);

        Artisan::call('db:seed', ['--class' => 'DailyChallengeSeeder']);
        $countAfterFirst = \App\Models\DailyChallenge::count();

        Artisan::call('db:seed', ['--class' => 'DailyChallengeSeeder']);
        $countAfterSecond = \App\Models\DailyChallenge::count();

        $this->assertSame($countAfterFirst, $countAfterSecond, 'DailyChallengeSeeder must not duplicate today\'s challenge');
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
