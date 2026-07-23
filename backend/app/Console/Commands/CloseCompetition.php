<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use App\Services\CompetitionCloseService;
use App\Services\CompetitionPeriodService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Close a COMPLETED competition period and award virtual top-3 recognition
 * (finish records + XP + badges). Defaults to the most recently completed
 * period — in July, `--type=monthly` closes June — and refuses the current
 * open period unless --force.
 *
 * Examples:
 *   php artisan ballspot:close-competition --dry-run
 *   php artisan ballspot:close-competition --period=2026-06
 *   php artisan ballspot:close-competition --type=weekly --period=2026-25 --dry-run
 */
class CloseCompetition extends Command
{
    protected $signature = 'ballspot:close-competition
        {--dry-run : Preview only — write nothing}
        {--period= : Period to close (monthly: YYYY-MM, weekly: YYYY-WW)}
        {--type= : monthly|weekly (default: configured competition period)}
        {--force : Allow closing a period that has not ended yet}
        {--announce : Save a draft admin announcement after a successful close (never auto-sends)}';

    protected $description = 'Close a completed competition period and award virtual top-3 trophies, XP and badges';

    public function __construct(
        private CompetitionCloseService $closeService,
        private CompetitionPeriodService $periodService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $type = $this->option('type') ?: $this->periodService->type();
        if (!in_array($type, [CompetitionPeriodService::WEEKLY, CompetitionPeriodService::MONTHLY], true)) {
            $this->error("Invalid --type '{$type}' (expected monthly or weekly).");
            return self::FAILURE;
        }

        $period = $this->resolvePeriod($type, $this->option('period'));
        if ($period === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $this->closeService->close($period, $dryRun, (bool) $this->option('force'));

        $this->line('Period:  ' . $period['period_label'] . ' (' . $period['period_type'] . ')');
        $this->line('Window:  ' . $period['period_start'] . ' → ' . $period['period_end']);
        $this->line('Players: ' . $result['total_players']);

        if (!empty($result['finishes'])) {
            $this->newLine();
            $this->table(
                ['#', 'User', 'Score', 'XP', 'Badges'],
                array_map(fn ($f) => [
                    $f['placement'],
                    $f['username'] ?? '(user #' . $f['user_id'] . ')',
                    $f['total_score'],
                    $f['xp'],
                    implode(', ', $f['badges']) ?: '—',
                ], $result['finishes']),
            );
        }

        $this->newLine();
        match ($result['status']) {
            CompetitionCloseService::STATUS_DRY_RUN        => $this->warn($result['message']),
            CompetitionCloseService::STATUS_ALREADY_CLOSED => $this->warn($result['message']),
            CompetitionCloseService::STATUS_REFUSED_OPEN   => $this->error($result['message']),
            default                                        => $this->info($result['message']),
        };

        if ($result['status'] === CompetitionCloseService::STATUS_REFUSED_OPEN) {
            return self::FAILURE;
        }

        if ($result['status'] === CompetitionCloseService::STATUS_CLOSED && $this->option('announce')) {
            $this->createAnnouncementDraft($period);
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the target period: --period=YYYY-MM (monthly) / YYYY-WW (weekly),
     * or the most recently completed period when omitted.
     */
    private function resolvePeriod(string $type, ?string $option): ?array
    {
        if ($option === null || $option === '') {
            return $this->periodService->previousPeriod($type);
        }

        if (!preg_match('/^(\d{4})-(\d{1,2})$/', $option, $m)) {
            $this->error("Invalid --period '{$option}' (expected YYYY-MM for monthly or YYYY-WW for weekly).");
            return null;
        }

        [$year, $part] = [(int) $m[1], (int) $m[2]];
        $tz = $this->periodService->timezone();

        if ($type === CompetitionPeriodService::WEEKLY) {
            if ($part < 1 || $part > 53) {
                $this->error("Invalid ISO week '{$option}' (expected 1–53).");
                return null;
            }
            $anchor = Carbon::now($tz)->setISODate($year, $part)->startOfDay();
        } else {
            if ($part < 1 || $part > 12) {
                $this->error("Invalid month '{$option}' (expected 1–12).");
                return null;
            }
            $anchor = Carbon::create($year, $part, 1, 12, 0, 0, $tz);
        }

        return $this->periodService->describe($type, $anchor);
    }

    /**
     * Draft-only announcement — saved for the admin panel, never auto-sent
     * (sending stays a deliberate human action in /admin/notifications).
     */
    private function createAnnouncementDraft(array $period): void
    {
        $word = $period['period_type'] === CompetitionPeriodService::WEEKLY ? 'Weekly' : 'Monthly';

        AdminNotification::create([
            'title'       => "{$word} results are in",
            'body'        => "The {$period['period_label']} competition has ended — check the leaderboard and Trophy Room.",
            'target_type' => AdminNotification::TARGET_OPTED_IN,
            'status'      => AdminNotification::STATUS_DRAFT,
            'metadata'    => ['source' => 'competition_close', 'period_label' => $period['period_label']],
        ]);

        $this->info('Draft announcement saved (not sent) — review it in /admin/notifications.');
    }
}
