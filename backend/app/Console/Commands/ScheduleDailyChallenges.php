<?php

namespace App\Console\Commands;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ScheduleDailyChallenges extends Command
{
    protected $signature = 'ballspot:schedule-daily-challenges
                            {--days=14 : Number of days to schedule}
                            {--start= : Start date YYYY-MM-DD, defaults to today}
                            {--dry-run : Print planned schedule without writing}
                            {--force : Replace existing daily challenges}';

    protected $description = 'Schedule daily challenges for the next N days using eligible active challenges';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');
        $force  = (bool) $this->option('force');

        $startRaw = $this->option('start');
        if ($startRaw !== null) {
            try {
                $start = Carbon::createFromFormat('Y-m-d', $startRaw);
            } catch (\Exception $e) {
                $start = null;
            }
            if (!$start || $start->format('Y-m-d') !== $startRaw) {
                $this->error("Invalid --start date \"{$startRaw}\". Expected format: YYYY-MM-DD (e.g. 2026-07-01).");
                return self::FAILURE;
            }
        } else {
            $start = Carbon::today();
        }

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be written.');
            $this->newLine();
        }

        // --- Pool selection ---
        $allEligible = Challenge::where('status', 'active')->get()->filter->isReadyForDaily();
        $realContent = $allEligible->reject->isDemoContent();
        $demoContent = $allEligible->filter->isDemoContent();

        if ($allEligible->isEmpty()) {
            $this->error('No eligible challenges found. Active challenges need a hidden image and ball position.');
            return self::FAILURE;
        }

        $usingDemo = false;
        if ($realContent->isEmpty()) {
            $this->warn('Warning: No real content found — falling back to demo challenges.');
            $usingDemo = true;
            $pool = $demoContent;
        } else {
            $pool = $realContent;
        }

        // --- LRU sort ---
        $recentUseDates = DailyChallenge::whereIn('challenge_id', $pool->pluck('id'))
            ->selectRaw('challenge_id, MAX(challenge_date) as last_used')
            ->groupBy('challenge_id')
            ->pluck('last_used', 'challenge_id');

        $sortedPool = $pool->sortBy(function ($challenge) use ($recentUseDates) {
            return $recentUseDates->get($challenge->id) ?? '0000-00-00';
        })->values();

        // --- Date loop ---
        $usedInRun    = [];
        $createdCount = 0;
        $replacedCount = 0;
        $skippedCount  = 0;
        $plannedCount  = 0;

        for ($i = 0; $i < $days; $i++) {
            $date     = $start->copy()->addDays($i)->toDateString();
            $existing = DailyChallenge::whereDate('challenge_date', $date)->first();

            if ($existing && !$force) {
                $this->line("  <fg=yellow>SKIP</>  {$date} — already scheduled");
                $skippedCount++;
                continue;
            }

            // Pick LRU challenge not yet used in this run
            $available = $sortedPool->filter(fn($c) => !in_array($c->id, $usedInRun, true));
            if ($available->isEmpty()) {
                $usedInRun = [];
                $available = $sortedPool;
            }
            $chosen = $available->first();
            $usedInRun[] = $chosen->id;

            // Rotate chosen to end of pool so next call picks a different one
            $sortedPool = $sortedPool->reject(fn($c) => $c->id === $chosen->id)->values();
            $sortedPool->push($chosen);

            if ($dryRun) {
                $flag = $existing ? ' [REPLACE]' : '';
                $this->line("  <fg=green>PLAN</>  {$date} → {$chosen->title} (id={$chosen->id}){$flag}");
                $plannedCount++;
                continue;
            }

            if ($existing) {
                $existing->update(['challenge_id' => $chosen->id, 'status' => 'scheduled']);
                $this->line("  <fg=cyan>REPLACE</> {$date} → {$chosen->title}");
                $replacedCount++;
            } else {
                DailyChallenge::create([
                    'challenge_id'   => $chosen->id,
                    'challenge_date' => $date,
                    'status'         => 'scheduled',
                ]);
                $this->line("  <fg=green>CREATE</> {$date} → {$chosen->title}");
                $createdCount++;
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->info("Dry run complete — {$plannedCount} dates would be written, {$skippedCount} skipped.");
            return self::SUCCESS;
        }

        $this->info("Done. Created: {$createdCount}, Replaced: {$replacedCount}, Skipped: {$skippedCount}.");

        if ($usingDemo) {
            $this->warn('Scheduled with demo content. Add real challenges to replace them.');
        }

        return self::SUCCESS;
    }
}
