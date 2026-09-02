<?php

namespace App\Console\Commands;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Support\AppLog;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ScheduleDailyChallenges extends Command
{
    protected $signature = 'ballspot:schedule-daily-challenges
                            {--days=14 : Number of days to schedule}
                            {--start= : Start date YYYY-MM-DD, defaults to today}
                            {--dry-run : Print planned schedule without writing}
                            {--force : Replace existing daily challenges}
                            {--sport= : Only use challenges from this sport slug (e.g. football, tennis). Omit to use all active challenges.}
                            {--allow-coming-soon : Allow scheduling for a coming_soon/hidden sport (admin content prep).}
                            {--allow-reuse : Emergency fallback. Reuse challenges that were already a daily, rotating least-recently-used first.}';

    protected $description = 'Schedule daily challenges for the next N days using eligible active challenges that have never been a daily';

    public function handle(): int
    {
        $days       = (int) $this->option('days');
        $dryRun     = (bool) $this->option('dry-run');
        $force      = (bool) $this->option('force');
        $allowReuse = (bool) $this->option('allow-reuse');

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

        // --- Sport filter (optional) ---
        // When --sport is omitted we keep the original behaviour (all active
        // challenges) so existing schedules are unaffected. When provided, only
        // that sport's challenges are used, enabling per-sport daily rotations.
        $sportSlug = $this->option('sport');
        $sportId   = null;
        if ($sportSlug !== null && $sportSlug !== '') {
            $sport = Sport::where('slug', $sportSlug)->first();
            if (!$sport) {
                $this->error("Unknown sport \"{$sportSlug}\". Use a valid sport slug (e.g. football, tennis).");
                return self::FAILURE;
            }
            $sportId = $sport->id;
            $this->line("Scheduling for sport: <fg=cyan>{$sport->name}</> ({$sport->slug})");

            // Only playable (active) sports schedule by default. Preparing daily
            // content for a coming_soon/hidden sport requires an explicit opt-in.
            if ($sport->status !== Sport::STATUS_ACTIVE && !$this->option('allow-coming-soon')) {
                $this->warn("Sport \"{$sport->slug}\" is {$sport->status}, not active. Re-run with --allow-coming-soon to prepare content anyway.");
                return self::SUCCESS;
            }
        }

        // --- Pool selection ---
        // v1.8.9 fairness: only daily|general pool challenges may become a daily.
        $poolQuery = Challenge::dailyPool();
        if ($sportId !== null) {
            $poolQuery->where('sport_id', $sportId);
        }
        $allEligible = $poolQuery->get()->filter->isDailyEligible();

        if ($allEligible->isEmpty()) {
            $this->error('No eligible challenges found. Active challenges in the daily or general pool need a hidden image and ball position.');
            AppLog::error('daily.schedule_failed', ['reason' => 'no_eligible_challenges', 'sport_id' => $sportId, 'eligible_count' => 0, 'days' => $days]);
            return self::FAILURE;
        }

        // A challenge is a daily at most once. --allow-reuse is the manual
        // escape hatch that restores the old least-recently-used rotation.
        if (!$allowReuse) {
            $everUsed    = DailyChallenge::distinct()->pluck('challenge_id')->map(fn ($id) => (int) $id)->all();
            $allEligible = $allEligible->reject(fn ($c) => in_array((int) $c->id, $everUsed, true));

            if ($allEligible->isEmpty()) {
                $this->warn('All ready challenges have already been used as a daily challenge. Add new content, or re-run with --allow-reuse.');
                AppLog::warn('daily.schedule_skipped', ['reason' => 'all_used', 'sport_id' => $sportId, 'eligible_count' => 0, 'days' => $days]);
                return self::SUCCESS;
            }
        }

        $realContent = $allEligible->reject->isDemoContent();
        $demoContent = $allEligible->filter->isDemoContent();

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
        $exhausted     = false;

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
                // Strict mode stops here rather than handing out a second turn.
                if (!$allowReuse) {
                    $exhausted = true;
                    break;
                }
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
                AppLog::event('daily.scheduled', ['challenge_id' => $chosen->id, 'date' => $date, 'sport_id' => $chosen->sport_id, 'status' => 'scheduled', 'replaced' => true]);
            } else {
                DailyChallenge::create([
                    'challenge_id'   => $chosen->id,
                    'challenge_date' => $date,
                    'status'         => 'scheduled',
                ]);
                $this->line("  <fg=green>CREATE</> {$date} → {$chosen->title}");
                $createdCount++;
                AppLog::event('daily.scheduled', ['challenge_id' => $chosen->id, 'date' => $date, 'sport_id' => $chosen->sport_id, 'status' => 'scheduled', 'replaced' => false]);
            }
        }

        $this->newLine();

        if ($exhausted) {
            $scheduled = $dryRun ? $plannedCount : $createdCount + $replacedCount;
            $this->warn("Pool exhausted: scheduled {$scheduled} of {$days} requested days.");
            $this->line('Every ready challenge has now been used as a daily. Add new challenges, or re-run with --allow-reuse.');
            $this->newLine();
            AppLog::warn('daily.pool_exhausted', ['reason' => 'pool_exhausted', 'sport_id' => $sportId, 'eligible_count' => $pool->count(), 'scheduled' => $scheduled, 'requested_days' => $days, 'dry_run' => $dryRun]);
        }

        if ($dryRun) {
            $this->info("Dry run complete — {$plannedCount} dates would be written, {$skippedCount} skipped.");
            return self::SUCCESS;
        }

        $this->info("Done. Created: {$createdCount}, Replaced: {$replacedCount}, Skipped: {$skippedCount}.");
        AppLog::event('daily.schedule_run', ['created' => $createdCount, 'replaced' => $replacedCount, 'skipped' => $skippedCount, 'requested_days' => $days, 'sport_id' => $sportId, 'eligible_count' => $pool->count(), 'demo_content' => $usingDemo]);

        if ($usingDemo) {
            $this->warn('Scheduled with demo content. Add real challenges to replace them.');
        }

        return self::SUCCESS;
    }
}
