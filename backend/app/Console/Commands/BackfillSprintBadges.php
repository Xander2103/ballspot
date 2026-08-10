<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BadgeService;
use Illuminate\Console\Command;

/**
 * One-off, idempotent backfill for the v1.8.6 count-based trophies. Awards
 * only badges whose qualifying counts already hold (with their normal unlock
 * XP). Never removes or rewrites anything. Safe to re-run.
 */
class BackfillSprintBadges extends Command
{
    protected $signature = 'ballspot:backfill-sprint-badges
        {--dry-run : List users that would be evaluated without awarding}
        {--user= : Only evaluate this user id}';

    protected $description = 'Retroactively award v1.8.6 trophies to users whose stats already qualify';

    public function handle(BadgeService $badges): int
    {
        $dryRun  = (bool) $this->option('dry-run');
        $awarded = 0;
        $users   = 0;

        User::query()
            ->whereNull('anonymized_at')
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->chunkById(100, function ($chunk) use ($badges, $dryRun, &$awarded, &$users) {
                foreach ($chunk as $user) {
                    $users++;
                    if ($dryRun) {
                        continue;
                    }
                    $new = $badges->backfillCountBadges($user);
                    if ($new !== []) {
                        $this->line("  user {$user->id}: " . implode(', ', array_map(fn ($b) => $b->code, $new)));
                        $awarded += count($new);
                    }
                }
            });

        $this->info($dryRun
            ? "Dry run: {$users} users would be evaluated."
            : "Backfill complete. Users evaluated: {$users}, new badges awarded: {$awarded}.");

        return self::SUCCESS;
    }
}
