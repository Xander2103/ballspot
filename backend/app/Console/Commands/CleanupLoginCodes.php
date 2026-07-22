<?php

namespace App\Console\Commands;

use App\Services\EmailVerificationService;
use App\Services\LoginVerificationService;
use Illuminate\Console\Command;

class CleanupLoginCodes extends Command
{
    protected $signature = 'ballspot:cleanup-login-codes';

    protected $description = 'Delete expired and consumed login + email verification codes';

    public function handle(LoginVerificationService $login, EmailVerificationService $email): int
    {
        $loginDeleted = $login->cleanupStale();
        $emailDeleted = $email->cleanupStale();

        $this->info("Removed {$loginDeleted} stale login code(s) and {$emailDeleted} stale email verification code(s).");

        return self::SUCCESS;
    }
}
