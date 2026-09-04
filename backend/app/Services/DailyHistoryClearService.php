<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Support\AppLog;
use Illuminate\Support\Facades\DB;

/**
 * Pre-launch "Clear Daily History" — the web twin of
 * `ballspot:reset-test-daily-history --force --confirm-prelaunch`.
 *
 * Deletes ONLY daily_challenge_guesses and daily_challenges, in one
 * transaction, after a full content backup succeeded. Never challenges,
 * images, usage_pool, tournament rounds/guesses, users, badges or packs.
 * Any row in daily_challenges marks a challenge as "Used as Daily" and keeps
 * it out of tournaments; clearing makes test-scheduled photos reusable.
 *
 * The confirmation PIN is a deliberate-action guard on top of admin auth,
 * not a secret: it is compared in constant time and never logged or rendered.
 */
class DailyHistoryClearService
{
    public const CONFIRMATION_PIN = '1281';
    public const ACKNOWLEDGEMENT  = 'I understand this clears Daily history';

    public function pinMatches(?string $pin): bool
    {
        return is_string($pin) && hash_equals(self::CONFIRMATION_PIN, trim($pin));
    }

    /** Counts shown on the diagnostics page before the action. */
    public function counts(): array
    {
        return [
            'daily_challenges'        => DailyChallenge::count(),
            'daily_challenge_guesses' => DailyChallengeGuess::count(),
            'affected_challenges'     => Challenge::dailyUsed()->count(),
        ];
    }

    /**
     * Back up, then delete. Throws DailyHistoryClearException with a stage so
     * the caller can log/report without leaking paths or exception text.
     *
     * @return array{deleted_daily_challenges:int, deleted_daily_challenge_guesses:int, affected_challenges:int, backup_path:string}
     */
    public function clear(ContentBackupService $backup, int $adminId): array
    {
        $before = $this->counts();

        try {
            $result = $backup->run('daily_history_clear');
        } catch (\Throwable $e) {
            AppLog::error('daily_history_clear.failed', [
                'stage'     => 'backup_failed',
                'admin_id'  => $adminId,
                'exception' => class_basename($e),
            ] + $before);
            report($e);
            throw new DailyHistoryClearException('backup_failed', 'The content backup could not be written, so nothing was deleted. Check disk space and the backups folder, then try again.', $e);
        }

        try {
            [$deletedGuesses, $deletedDailies] = $this->deleteRows();
        } catch (\Throwable $e) {
            AppLog::error('daily_history_clear.failed', [
                'stage'     => 'delete_failed',
                'admin_id'  => $adminId,
                'exception' => class_basename($e),
            ] + $before);
            report($e);
            throw new DailyHistoryClearException('delete_failed', 'Deleting the Daily history failed and was rolled back. Nothing was changed; a backup was written first.', $e);
        }

        AppLog::warn('daily_history_clear.completed', [
            'admin_id'                        => $adminId,
            'deleted_daily_challenges'        => $deletedDailies,
            'deleted_daily_challenge_guesses' => $deletedGuesses,
            'affected_challenges'             => $before['affected_challenges'],
            'backup_created'                  => true,
            'backup_folder'                   => basename($result['path']),
        ]);

        return [
            'deleted_daily_challenges'        => $deletedDailies,
            'deleted_daily_challenge_guesses' => $deletedGuesses,
            'affected_challenges'             => $before['affected_challenges'],
            'backup_path'                     => $result['path'],
        ];
    }

    /**
     * The only destructive step, isolated so tests can fail it. Guesses first
     * (FK), then dailies — one transaction, counts returned.
     *
     * @return array{0:int,1:int}
     */
    public function deleteRows(): array
    {
        return DB::transaction(fn () => [
            DailyChallengeGuess::query()->delete(),
            DailyChallenge::query()->delete(),
        ]);
    }
}
