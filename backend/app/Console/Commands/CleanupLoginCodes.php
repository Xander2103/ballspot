<?php

namespace App\Console\Commands;

use App\Services\LoginVerificationService;
use Illuminate\Console\Command;

class CleanupLoginCodes extends Command
{
    protected $signature = 'ballspot:cleanup-login-codes';

    protected $description = 'Delete expired and consumed login verification codes';

    public function handle(LoginVerificationService $service): int
    {
        $deleted = $service->cleanupStale();
        $this->info("Removed {$deleted} stale login verification code(s).");

        return self::SUCCESS;
    }
}
