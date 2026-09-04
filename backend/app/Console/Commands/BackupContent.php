<?php

namespace App\Console\Commands;

use App\Services\ContentBackupService;
use Illuminate\Console\Command;

/**
 * Thin CLI wrapper around ContentBackupService — the admin "Clear Daily
 * History" action uses the same service, so both produce identical backups.
 */
class BackupContent extends Command
{
    protected $signature = 'ballspot:backup-content';
    protected $description = 'Back up database, uploaded images, and challenge metadata to a timestamped folder';

    public function handle(ContentBackupService $backup): int
    {
        $this->info('BallSpot Content Backup');
        $this->info('Destination: ' . $backup->root());
        $this->newLine();

        try {
            $result = $backup->onLine(fn (string $line) => $this->line($line))->run('manual');
        } catch (\Throwable $e) {
            $this->error('Backup failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $m = $result['manifest'];

        $this->newLine();
        $this->line('─────────────────────────────────────────');
        $this->info('Backup complete!');
        $this->table(
            ['Item', 'Result'],
            [
                ['Database',              $m['database_copied'] ? '<fg=green>✓ Copied</>' : '<fg=yellow>⚠ Not found / skipped</>'],
                ['Storage images',        $m['storage_copied'] ? "<fg=green>✓ Copied ({$m['file_count']} files)</>" : '<fg=yellow>⚠ Not found / skipped</>'],
                ['challenges.json',       "<fg=green>✓ {$m['challenge_count']} challenges</>"],
                ['daily_challenges.json', "<fg=green>✓ {$m['daily_challenge_count']} daily challenges</>"],
                ['sports.json',           '<fg=green>✓</>'],
                ['challenge_categories.json', '<fg=green>✓</>'],
                ['manifest.json',         '<fg=green>✓</>'],
            ]
        );
        $this->newLine();
        $this->line("Backup folder: <fg=cyan>{$result['path']}</>");
        $this->line('Inspect with: <fg=cyan>php artisan ballspot:inspect-backup backups/ballspot-content/' . basename($result['path']) . '</>');

        return self::SUCCESS;
    }
}
