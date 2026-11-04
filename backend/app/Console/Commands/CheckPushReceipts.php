<?php

namespace App\Console\Commands;

use App\Services\PushReceiptService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CheckPushReceipts extends Command
{
    protected $signature = 'ballspot:check-push-receipts
        {--now : Also check tickets younger than the receipt delay}
        {--limit=3000 : Maximum tickets to check in this run}';

    protected $description = 'Fetch Expo push receipts (APNs/FCM delivery outcome) for accepted push tickets';

    public function handle(PushReceiptService $service): int
    {
        // Code deployed before `migrate`: nothing recorded yet, nothing to do.
        if (! Schema::hasTable('push_receipts')) {
            $this->warn('push_receipts table missing — run php artisan migrate.');

            return self::SUCCESS;
        }

        $result = $service->checkPending(
            max(1, (int) $this->option('limit')),
            $this->option('now') ? 0 : null,
        );

        $errors = collect($result['errors'])->map(fn ($n, $code) => "{$code}={$n}")->implode(', ');

        $this->info(sprintf(
            'Receipts — checked: %d, ok: %d, error: %d%s, not ready: %d, expired: %d, dead tokens removed: %d, pruned: %d%s',
            $result['checked'], $result['ok'], $result['error'], $errors !== '' ? " ({$errors})" : '',
            $result['still_pending'], $result['expired'], $result['tokens_removed'], $result['pruned'],
            $result['request_failed'] ? ' — Expo request failed, will retry' : '',
        ));

        return self::SUCCESS;
    }
}
