<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Content backup: SQLite database file (when in use), uploaded images and
 * JSON exports of challenges / dailies / sports / categories into a
 * timestamped folder. Shared by `ballspot:backup-content` and the admin
 * "Clear Daily History" action, so both produce the identical backup layout.
 *
 * Throws on anything that would leave an incomplete backup (destination not
 * creatable, JSON export not writable). Callers that are about to delete data
 * must treat an exception as "do not proceed".
 */
class ContentBackupService
{
    /** @var callable|null */
    private $logger = null;

    /** Receive one human-readable line per step (used by the artisan command). */
    public function onLine(?callable $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Run a full backup. Returns the manifest plus the absolute folder path.
     *
     * @param  string|null $reason  free-form tag written into the manifest
     * @return array{path:string, manifest:array}
     */
    public function run(?string $reason = null): array
    {
        $timestamp  = now()->format('Y-m-d-His');
        $backupRoot = rtrim($this->root(), '/\\') . DIRECTORY_SEPARATOR . $timestamp;

        // A second run within the same second must not merge into the first.
        $suffix = 0;
        while (file_exists($backupRoot)) {
            $backupRoot = rtrim($this->root(), '/\\') . DIRECTORY_SEPARATOR . $timestamp . '-' . (++$suffix);
        }

        if (!@mkdir($backupRoot, 0755, true) || !is_dir($backupRoot) || !is_writable($backupRoot)) {
            throw new \RuntimeException('Could not create the backup directory.');
        }

        $dbCopied      = $this->backupDatabase($backupRoot);
        $storageCopied = $this->backupStorage($backupRoot);
        $fileCount     = $storageCopied ? $this->countFiles("{$backupRoot}/storage") : 0;

        $challengeCount = $this->exportChallenges($backupRoot);
        $dailyCount     = $this->exportDailyChallenges($backupRoot);
        $this->exportSports($backupRoot);
        $this->exportCategories($backupRoot);

        $manifest = [
            'created_at'            => now()->toIso8601String(),
            'app_name'              => 'BallSpot',
            'reason'                => $reason ?? 'manual',
            'database_copied'       => $dbCopied,
            'storage_copied'        => $storageCopied,
            'challenge_count'       => $challengeCount,
            'daily_challenge_count' => $dailyCount,
            'file_count'            => $fileCount,
            'notes'                 => [
                'Restore DB: copy database/database.sqlite back to backend/database/',
                'Restore images: copy storage/ back to backend/storage/app/public/',
                'Restore dailies: daily_challenges.json holds every row (guesses are not exported)',
                'Run php artisan ballspot:recover-challenges for orphaned images',
                'NEVER run migrate:fresh without backing up first',
            ],
        ];

        $this->writeJson("{$backupRoot}/manifest.json", $manifest, false);

        return ['path' => $backupRoot, 'manifest' => $manifest];
    }

    /** Folder that holds the timestamped backups (config-driven for tests). */
    public function root(): string
    {
        return (string) (config('ballspot.backup.root') ?: base_path('../backups/ballspot-content'));
    }

    // ------------------------------------------------------------------

    private function backupDatabase(string $dest): bool
    {
        $sqlitePath = database_path('database.sqlite');
        if (!file_exists($sqlitePath)) {
            $this->line('  [DB] database.sqlite not found — skipping (MySQL or other driver in use)');
            return false;
        }
        $target = "{$dest}/database.sqlite";
        if (!copy($sqlitePath, $target)) {
            throw new \RuntimeException('Failed to copy database.sqlite into the backup.');
        }
        $this->line('  [DB] Copied database.sqlite (' . $this->humanSize((int) filesize($target)) . ')');
        return true;
    }

    private function backupStorage(string $dest): bool
    {
        $src = (string) (config('ballspot.backup.storage_path') ?: storage_path('app/public'));
        if (!is_dir($src)) {
            $this->line('  [Storage] storage/app/public not found — skipping');
            return false;
        }
        $this->copyDir($src, "{$dest}/storage");
        $this->line('  [Storage] Copied storage/app/public');
        return true;
    }

    private function exportChallenges(string $dest): int
    {
        $rows = DB::table('challenges')
            ->join('sports', 'challenges.sport_id', '=', 'sports.id')
            ->leftJoin('challenge_categories', 'challenges.challenge_category_id', '=', 'challenge_categories.id')
            ->select(
                'challenges.*',
                'sports.name as sport_name',
                'sports.slug as sport_slug',
                'challenge_categories.name as category_name',
                'challenge_categories.slug as category_slug'
            )
            ->orderBy('challenges.id')
            ->get()
            ->toArray();

        $this->writeJson("{$dest}/challenges.json", $rows);
        $this->line('  [Export] ' . count($rows) . ' challenges → challenges.json');

        return count($rows);
    }

    private function exportDailyChallenges(string $dest): int
    {
        if (!Schema::hasTable('daily_challenges')) {
            $this->line('  [Export] daily_challenges table not found — skipping');
            return 0;
        }
        $rows = DB::table('daily_challenges')->orderBy('challenge_date')->get()->toArray();

        $this->writeJson("{$dest}/daily_challenges.json", $rows);
        $this->line('  [Export] ' . count($rows) . ' daily challenges → daily_challenges.json');

        return count($rows);
    }

    private function exportSports(string $dest): void
    {
        $rows = DB::table('sports')->orderBy('id')->get()->toArray();
        $this->writeJson("{$dest}/sports.json", $rows);
        $this->line('  [Export] ' . count($rows) . ' sports → sports.json');
    }

    private function exportCategories(string $dest): void
    {
        if (!Schema::hasTable('challenge_categories')) {
            return;
        }
        $rows = DB::table('challenge_categories')->orderBy('sort_order')->get()->toArray();
        $this->writeJson("{$dest}/challenge_categories.json", $rows);
        $this->line('  [Export] ' . count($rows) . ' categories → challenge_categories.json');
    }

    private function writeJson(string $path, mixed $data, bool $unicode = true): void
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | ($unicode ? JSON_UNESCAPED_UNICODE : 0);
        if (@file_put_contents($path, json_encode($data, $flags)) === false) {
            throw new \RuntimeException('Failed to write ' . basename($path) . ' into the backup.');
        }
    }

    private function copyDir(string $src, string $dest): void
    {
        if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
            throw new \RuntimeException('Failed to create the storage folder inside the backup.');
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $rel    = substr($file->getPathname(), strlen($src) + 1);
            $target = $dest . DIRECTORY_SEPARATOR . $rel;
            $dir    = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                throw new \RuntimeException('Failed to create a folder inside the backup.');
            }
            if (!@copy($file->getPathname(), $target)) {
                throw new \RuntimeException('Failed to copy an uploaded file into the backup.');
            }
        }
    }

    public function countFiles(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }
        return iterator_count(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS))
        );
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    private function line(string $text): void
    {
        if ($this->logger) {
            ($this->logger)($text);
        }
    }
}
