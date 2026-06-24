<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class InspectBackup extends Command
{
    protected $signature = 'ballspot:inspect-backup {folder : Path to the backup folder (relative to project root or absolute)}';
    protected $description = 'Inspect a BallSpot content backup and print a summary';

    public function handle(): int
    {
        $folder = $this->argument('folder');

        // Resolve relative to project root
        if (!str_starts_with($folder, '/') && !preg_match('/^[A-Za-z]:/', $folder)) {
            $folder = base_path('../' . ltrim($folder, '/'));
        }

        if (!is_dir($folder)) {
            $this->error("Backup folder not found: {$folder}");
            $this->line('Run <fg=cyan>php artisan ballspot:backup-content</> to create a backup first.');
            return self::FAILURE;
        }

        $manifestPath = "{$folder}/manifest.json";
        if (!file_exists($manifestPath)) {
            $this->error("manifest.json not found in {$folder}");
            $this->warn("This may not be a valid BallSpot backup folder.");
            return self::FAILURE;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);

        $this->info("BallSpot Backup Inspection");
        $this->line("Folder: <fg=cyan>{$folder}</>");
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Created at',              $manifest['created_at'] ?? '—'],
                ['App',                     $manifest['app_name'] ?? '—'],
                ['Database copied',         ($manifest['database_copied'] ?? false) ? '<fg=green>Yes</>' : '<fg=yellow>No</>'],
                ['Storage copied',          ($manifest['storage_copied'] ?? false) ? '<fg=green>Yes</>' : '<fg=yellow>No</>'],
                ['Challenges',              $manifest['challenge_count'] ?? 0],
                ['Daily challenges',        $manifest['daily_challenge_count'] ?? 0],
                ['Storage files',           $manifest['file_count'] ?? 0],
            ]
        );

        $this->newLine();
        $this->line('<fg=yellow>Files in this backup:</>');

        $files = array_diff(scandir($folder), ['.', '..']);
        foreach ($files as $f) {
            $full = "{$folder}/{$f}";
            $size = is_file($full) ? ' (' . $this->humanSize(filesize($full)) . ')' : ' [dir]';
            $this->line("  {$f}{$size}");
        }

        if (!empty($manifest['notes'])) {
            $this->newLine();
            $this->line('<fg=yellow>Restore notes:</>');
            foreach ($manifest['notes'] as $note) {
                $this->line("  • {$note}");
            }
        }

        $this->newLine();
        $this->line('<fg=yellow>⚠ IMPORTANT: Do NOT run migrate:fresh without restoring the database backup first.</>');

        return self::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }
}
