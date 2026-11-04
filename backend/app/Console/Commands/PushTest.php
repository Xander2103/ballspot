<?php

namespace App\Console\Commands;

use App\Models\PushReceipt;
use App\Models\PushToken;
use App\Models\User;
use App\Services\ExpoPushService;
use App\Services\PushReceiptService;
use App\Support\AppLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * Admin-only end-to-end push check for ONE device of ONE account (your own
 * test account). Never fans out: it refuses when the target is ambiguous and
 * always asks for confirmation, so `--no-interaction` can never send.
 *
 *   php artisan ballspot:push-test 42            # list that account's devices (dry run)
 *   php artisan ballspot:push-test 42 --send     # send one test push, show ticket + receipt
 *   php artisan ballspot:push-test 42 --send --token=7
 */
class PushTest extends Command
{
    protected $signature = 'ballspot:push-test
        {user : User ID of your own test account}
        {--token= : push_tokens.id of the device, required when the account has several}
        {--send : Actually send (without it nothing is sent)}
        {--wait=90 : Seconds to poll Expo for the delivery receipt}';

    protected $description = 'Send exactly one test push to one device of one test account and show the Expo ticket and receipt';

    public function handle(ExpoPushService $push, PushReceiptService $receipts): int
    {
        $user = User::find((int) $this->argument('user'));
        if (! $user) {
            $this->error('No user with that ID.');

            return self::FAILURE;
        }
        if ($user->anonymized_at !== null) {
            $this->error('That account is deleted (anonymized). Not sending.');

            return self::FAILURE;
        }

        $tokens = PushToken::where('user_id', $user->id)->orderBy('id')->get();

        $this->line("User #{$user->id} — registered devices: {$tokens->count()}");
        if ($tokens->isNotEmpty()) {
            $this->table(['token id', 'platform', 'token (masked)', 'last seen (UTC)'], $tokens->map(fn (PushToken $t) => [
                $t->id, $t->platform ?? '?', self::mask($t->token), optional($t->last_seen_at)->toDateTimeString() ?? '-',
            ])->all());
        }

        if ($tokens->isEmpty()) {
            $this->error('No push token registered for this account. On the phone: log in, allow notifications, open Home, then retry.');

            return self::FAILURE;
        }

        if (! $this->option('send')) {
            $this->info('Dry run — nothing sent. Add --send (and --token=<id> if several devices) to send one test push.');

            return self::SUCCESS;
        }

        if (! config('ballspot.notifications.push_enabled')) {
            $this->error('BALLPICKER_PUSH_ENABLED is false — push is switched off on this server.');

            return self::FAILURE;
        }

        $tokenOption = $this->option('token');
        if ($tokenOption === null && $tokens->count() > 1) {
            $this->error('This account has several devices. Pick one with --token=<token id>.');

            return self::FAILURE;
        }
        $target = $tokenOption === null ? $tokens->first() : $tokens->firstWhere('id', (int) $tokenOption);
        if (! $target) {
            $this->error('That token id does not belong to this account.');

            return self::FAILURE;
        }

        $platform = $target->platform ?? 'unknown';
        if (! $this->confirm("Send exactly ONE test notification to user #{$user->id}, device #{$target->id} ({$platform})? Only do this for your own test device.", false)) {
            $this->warn('Cancelled — nothing sent.');

            return self::FAILURE;
        }

        $sentAt = now()->format('H:i:s');
        $outcome = $push->sendMessages([[
            'to'    => $target->token,
            'title' => 'BallPicker test',
            'body'  => "Test notification ({$sentAt} UTC). If you can read this, push works.",
            'sound' => 'default',
            'data'  => ['type' => 'admin_test'],
        ]], PushReceipt::SOURCE_TEST);

        $ticket = $outcome['tickets'][0] ?? ['status' => 'error', 'id' => null, 'error' => 'no_ticket'];
        $this->line('Expo ticket: status=' . $ticket['status']
            . ($ticket['id'] ? ' id=' . $ticket['id'] : '')
            . ($ticket['error'] ? ' error=' . $ticket['error'] : ''));

        AppLog::event('push.test_sent', [
            'user_id' => $user->id, 'push_token_id' => $target->id, 'platform' => $platform,
            'ticket_status' => $ticket['status'], 'ticket_error' => $ticket['error'],
        ]);

        if ($ticket['status'] !== 'ok' || ! $ticket['id']) {
            $this->error('Expo did not accept the message. ' . self::hint($ticket['error']));

            return self::FAILURE;
        }

        $receiptStatus = $this->pollReceipt($receipts, $ticket['id'], max(0, (int) $this->option('wait')));

        $this->newLine();
        $this->line('A receipt "ok" means APNs/FCM accepted the message — it is still not proof the phone showed it.');
        $answer = $this->choice('Did the notification appear on the phone?', ['yes', 'no', 'not checked'], 2);
        AppLog::event('push.test_confirmed', ['user_id' => $user->id, 'push_token_id' => $target->id, 'platform' => $platform, 'receipt' => $receiptStatus, 'answer' => $answer]);

        return $receiptStatus === PushReceipt::STATUS_ERROR ? self::FAILURE : self::SUCCESS;
    }

    private function pollReceipt(PushReceiptService $receipts, string $ticketId, int $wait): string
    {
        if (! Schema::hasTable('push_receipts')) {
            $this->warn('push_receipts table missing — run migrate to see receipts.');

            return 'unknown';
        }

        $row = PushReceipt::where('ticket_id', $ticketId)->first();
        if (! $row) {
            return 'unknown';
        }

        $deadline = now()->addSeconds($wait);
        do {
            $receipts->checkNow(collect([$row]));
            $row->refresh();
            if ($row->status !== PushReceipt::STATUS_PENDING) {
                break;
            }
            if (now()->greaterThanOrEqualTo($deadline)) {
                break;
            }
            Sleep::for(5)->seconds();
        } while (true);

        if ($row->status === PushReceipt::STATUS_PENDING) {
            $this->warn("Receipt not ready yet. Check later: php artisan ballspot:check-push-receipts --now (ticket {$ticketId}).");
        } elseif ($row->status === PushReceipt::STATUS_OK) {
            $this->info('Expo receipt: ok (handed to ' . (($row->platform ?? '') === 'android' ? 'FCM' : 'APNs/FCM') . ').');
        } else {
            $this->error("Expo receipt: error={$row->error}. " . self::hint($row->error));
        }

        return $row->status;
    }

    /** First characters of the bracketed id and its last 4 only. */
    public static function mask(string $token): string
    {
        if (preg_match('/^(Expo(?:nent)?PushToken)\[(.*)\]$/', $token, $m)) {
            return $m[1] . '[' . substr($m[2], 0, 2) . '…' . substr($m[2], -4) . ']';
        }

        return '…' . substr($token, -4);
    }

    private static function hint(?string $error): string
    {
        return match ($error) {
            'DeviceNotRegistered' => 'The token is dead (app removed or reinstalled); it has been removed. Open the app again to re-register.',
            'InvalidCredentials'  => 'Push credentials for this platform are missing/invalid in EAS (iOS: APNs key; Android: FCM V1 service account).',
            'MismatchSenderId'    => 'The FCM V1 service account belongs to a different Firebase project than the app\'s google-services.json.',
            'MessageTooBig'       => 'Payload over 4 KB.',
            'MessageRateExceeded' => 'Too many messages to this device; wait and retry.',
            default               => 'See https://docs.expo.dev/push-notifications/sending-notifications/#individual-errors',
        };
    }
}
