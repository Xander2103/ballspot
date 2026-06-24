<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackupContent extends Command
{
    protected $signature = 'ballspot:backup-content';
    protected $description = 'Back up database, uploaded images, and challenge metadata to a timestamped folder';

    public function handle(): int
    {
        $timestamp  = now()->format('Y-m-d-His');
        $backupRoot = base_path("../backups/ballspot-content/{$timestamp}");

        $this->info("BallSpot Content Backup");
        $this->info("Destination: {$backupRoot}");
        $this->newLine();

        if (!mkdir($backupRoot, 0755, true)) {
            $this->error("Could not create backup directory: {$backupRoot}");
            return self::FAILURE;
        }

        $dbCopied      = $this->backupDatabase($backupRoot);
        $storageCopied = $this->backupStorage($backupRoot);
        $fileCount     = $storageCopied ? $this->countFiles("{$backupRoot}/storage") : 0;

        $challengeCount      = $this->exportChallenges($backupRoot);
        $dailyCount          = $this->exportDailyChallenges($backupRoot);
        $this->exportSports($backupRoot);
        $this->exportCategories($backupRoot);

        $manifest = [
            'created_at'            => now()->toIso8601String(),
            'app_name'              => 'BallSpot',
            'database_copied'       => $dbCopied,
            'storage_copied'        => $storageCopied,
            'challenge_count'       => $challengeCount,
            'daily_challenge_count' => $dailyCount,
            'file_count'            => $fileCount,
            'notes'                 => [
                'Restore DB: copy database/database.sqlite back to backend/database/',
                'Restore images: copy storage/ back to backend/storage/app/public/',
                'Run php artisan ballspot:recover-challenges for orphaned images',
                'NEVER run migrate:fresh without backing up first',
            ],
        ];

        file_put_contents(
            "{$backupRoot}/manifest.json",
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->newLine();
        $this->line('─────────────────────────────────────────');
        $this->info('Backup complete!');
        $this->table(
            ['Item', 'Result'],
            [
                ['Database',            $dbCopied      ? '<fg=green>✓ Copied</>' : '<fg=yellow>⚠ Not found / skipped</>'],
                ['Storage images',      $storageCopied ? "<fg=green>✓ Copied ({$fileCount} files)</>" : '<fg=yellow>⚠ Not found / skipped</>'],
                ['challenges.json',     "<fg=green>✓ {$challengeCount} challenges</>"],
                ['daily_challenges.json', "<fg=green>✓ {$dailyCount} daily challenges</>"],
                ['sports.json',         '<fg=green>✓</>'],
                ['challenge_categories.json', '<fg=green>✓</>'],
                ['manifest.json',       '<fg=green>✓</>'],
            ]
        );
        $this->newLine();
        $this->line("Backup folder: <fg=cyan>{$backupRoot}</>");
        $this->line('Inspect with: <fg=cyan>php artisan ballspot:inspect-backup backups/ballspot-content/' . $timestamp . '</>');

        return self::SUCCESS;
    }

    private function backupDatabase(string $dest): bool
    {
        $sqlitePath = database_path('database.sqlite');
        if (!file_exists($sqlitePath)) {
            $this->warn('  [DB] database.sqlite not found — skipping (MySQL or other driver in use)');
            return false;
        }
        $target = "{$dest}/database.sqlite";
        if (!copy($sqlitePath, $target)) {
            $this->error("  [DB] Failed to copy database.sqlite");
            return false;
        }
        $this->line("  [DB] Copied database.sqlite (" . $this->humanSize(filesize($target)) . ')');
        return true;
    }

    private function backupStorage(string $dest): bool
    {
        $src = storage_path('app/public');
        if (!is_dir($src)) {
            $this->warn("  [Storage] storage/app/public not found — skipping");
            return false;
        }
        $destStorage = "{$dest}/storage";
        $this->copyDir($src, $destStorage);
        $this->line("  [Storage] Copied storage/app/public");
        return true;
    }

    private function exportChallenges(string $dest): int
    {
        try {
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

            file_put_contents(
                "{$dest}/challenges.json",
                json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            $count = count($rows);
            $this->line("  [Export] {$count} challenges → challenges.json");
            return $count;
        } catch (\Throwable $e) {
            $this->warn("  [Export] Could not export challenges: " . $e->getMessage());
            return 0;
        }
    }

    private function exportDailyChallenges(string $dest): int
    {
        try {
            if (!Schema::hasTable('daily_challenges')) {
                $this->line("  [Export] daily_challenges table not found — skipping");
                return 0;
            }
            $rows = DB::table('daily_challenges')
                ->orderBy('challenge_date')
                ->get()
                ->toArray();

            file_put_contents(
                "{$dest}/daily_challenges.json",
                json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            $count = count($rows);
            $this->line("  [Export] {$count} daily challenges → daily_challenges.json");
            return $count;
        } catch (\Throwable $e) {
            $this->warn("  [Export] Could not export daily_challenges: " . $e->getMessage());
            return 0;
        }
    }

    private function exportSports(string $dest): void
    {
        try {
            $rows = DB::table('sports')->orderBy('id')->get()->toArray();
            file_put_contents(
                "{$dest}/sports.json",
                json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            $this->line("  [Export] " . count($rows) . " sports → sports.json");
        } catch (\Throwable $e) {
            $this->warn("  [Export] Could not export sports: " . $e->getMessage());
        }
    }

    private function exportCategories(string $dest): void
    {
        try {
            if (!Schema::hasTable('challenge_categories')) return;
            $rows = DB::table('challenge_categories')->orderBy('sort_order')->get()->toArray();
            file_put_contents(
                "{$dest}/challenge_categories.json",
                json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            $this->line("  [Export] " . count($rows) . " categories → challenge_categories.json");
        } catch (\Throwable $e) {
            $this->warn("  [Export] Could not export categories: " . $e->getMessage());
        }
    }

    private function copyDir(string $src, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $rel    = substr($file->getPathname(), strlen($src) + 1);
            $target = $dest . DIRECTORY_SEPARATOR . $rel;
            $dir    = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            copy($file->getPathname(), $target);
        }
    }

    private function countFiles(string $path): int
    {
        if (!is_dir($path)) return 0;
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
}
