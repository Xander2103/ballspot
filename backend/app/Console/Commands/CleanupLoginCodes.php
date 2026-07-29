<?php

namespace App\Console\Commands;

use App\Models\PushToken;
use App\Services\EmailVerificationService;
use App\Services\LoginVerificationService;
use Illuminate\Console\Command;

class CleanupLoginCodes extends Command
{
    /** Push tokens unseen for this many days are considered dead devices. */
    public const PUSH_TOKEN_RETENTION_DAYS = 90;

    protected $signature = 'ballspot:cleanup-login-codes';

    protected $description = 'Delete expired/consumed login + email verification codes and stale push tokens';

    public function handle(LoginVerificationService $login, EmailVerificationService $email): int
    {
        $loginDeleted = $login->cleanupStale();
        $emailDeleted = $email->cleanupStale();

        // Data minimization: drop push tokens for devices that have not
        // checked in for a long time (uninstalled / abandoned devices).
        $pushDeleted = PushToken::where('last_seen_at', '<', now()->subDays(self::PUSH_TOKEN_RETENTION_DAYS))
            ->delete();

        $this->info(
            "Removed {$loginDeleted} stale login code(s), {$emailDeleted} stale email verification code(s) " .
            "and {$pushDeleted} stale push token(s)."
        );

        return self::SUCCESS;
    }
}
