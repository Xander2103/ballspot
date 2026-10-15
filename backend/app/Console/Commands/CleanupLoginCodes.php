<?php

namespace App\Console\Commands;

use App\Models\PushToken;
use App\Services\EmailVerificationService;
use App\Services\LoginVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupLoginCodes extends Command
{
    /** Push tokens unseen for this many days are considered dead devices. */
    public const PUSH_TOKEN_RETENTION_DAYS = 90;

    protected $signature = 'ballspot:cleanup-login-codes';

    protected $description = 'Delete expired/consumed login + email verification codes, stale push tokens and expired cache rows';

    public function handle(LoginVerificationService $login, EmailVerificationService $email): int
    {
        $loginDeleted = $login->cleanupStale();
        $emailDeleted = $email->cleanupStale();

        // Data minimization: drop push tokens for devices that have not
        // checked in for a long time (uninstalled / abandoned devices).
        $pushDeleted = PushToken::where('last_seen_at', '<', now()->subDays(self::PUSH_TOKEN_RETENTION_DAYS))
            ->delete();

        // Expired rows in the database cache store (rate-limit counters,
        // scheduler locks) once CACHE_STORE=database: Laravel only removes an
        // expired row when that same key is read again, so one-off keys (a
        // per-IP register attempt) would otherwise accumulate forever. Only
        // rows whose expiration has passed — a live counter is never touched.
        $cacheDeleted = $this->pruneExpiredCacheRows();

        $this->info(
            "Removed {$loginDeleted} stale login code(s), {$emailDeleted} stale email verification code(s), " .
            "{$pushDeleted} stale push token(s) and {$cacheDeleted} expired cache row(s)."
        );

        return self::SUCCESS;
    }

    private function pruneExpiredCacheRows(): int
    {
        $deleted = 0;
        foreach (['cache', 'cache_locks'] as $table) {
            if (Schema::hasTable($table)) {
                $deleted += (int) DB::table($table)->where('expiration', '<', now()->timestamp)->delete();
            }
        }

        return $deleted;
    }
}
