<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\PushReceipt;
use App\Models\PushToken;
use App\Support\AppLog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers admin announcements to users' registered Expo push tokens via Expo's
 * stateless push HTTP API (https://exp.host/--/api/v2/push/send). No realtime
 * infrastructure — one batched HTTPS POST per ≤100 tokens.
 *
 * User control is always respected: a user who explicitly disabled admin
 * notifications is never delivered to, regardless of the announcement's target.
 */
class ExpoPushService
{
    private const BATCH_SIZE = 100;

    /**
     * Send an announcement now. Records the real outcome on the model (never a
     * fake success): draft when push is disabled, sent/failed otherwise, with a
     * send summary in metadata. Returns the refreshed notification.
     */
    public function sendAnnouncement(AdminNotification $notification): AdminNotification
    {
        // Idempotency guard: an announcement already delivered must never be
        // re-sent. A double-clicked Send, a replayed POST, or a second admin
        // hitting Send would otherwise blast every device a second time.
        if ($notification->status === AdminNotification::STATUS_SENT) {
            return $notification;
        }

        if (! config('ballspot.notifications.push_enabled')) {
            $notification->update([
                'status'   => AdminNotification::STATUS_DRAFT,
                'metadata' => ['reason' => 'push_disabled', 'recipients' => 0],
            ]);
            AppLog::warn('push.announcement_skipped', ['notification_id' => $notification->id, 'reason' => 'push_disabled']);

            return $notification->fresh();
        }

        $tokens = $this->recipientTokens($notification->target_type);

        if ($tokens->isEmpty()) {
            $notification->update([
                'status'   => AdminNotification::STATUS_SENT,
                'sent_at'  => now(),
                'metadata' => ['recipients' => 0, 'sent' => 0, 'failed' => 0],
            ]);

            return $notification->fresh();
        }

        $messages = $tokens->map(fn (string $token) => [
            'to'    => $token,
            'title' => $notification->title,
            'body'  => $notification->body,
            'sound' => 'default',
        ])->values()->all();

        $outcome = $this->sendMessages($messages, PushReceipt::SOURCE_ANNOUNCEMENT);

        $status = $outcome['failed'] > 0 && $outcome['sent'] === 0
            ? AdminNotification::STATUS_FAILED
            : AdminNotification::STATUS_SENT;

        $notification->update([
            'status'   => $status,
            'sent_at'  => now(),
            'metadata' => [
                'recipients' => $tokens->count(),
                'sent'       => $outcome['sent'],
                'failed'     => $outcome['failed'],
            ],
        ]);

        AppLog::event('push.announcement', [
            'notification_id' => $notification->id,
            'target'          => $notification->target_type,
            'status'          => $status,
            'recipients'      => $tokens->count(),
            'sent'            => $outcome['sent'],
            'failed'          => $outcome['failed'],
        ]);

        return $notification->fresh();
    }

    /**
     * Send raw Expo push messages in batches of ≤100. Parses per-message
     * tickets; tokens Expo reports as DeviceNotRegistered are deleted so we
     * stop sending to dead devices. Synchronous by design — this codebase has
     * no queue worker dependency.
     *
     * An "ok" ticket only means Expo accepted the message. Each ok ticket id is
     * stored in push_receipts (token row id + platform, never the token) so
     * ballspot:check-push-receipts can fetch what APNs/FCM actually said.
     *
     * @param  array<int, array{to: string, title: string, body: string, sound?: string, data?: array}>  $messages
     * @param  string  $source  PushReceipt::SOURCE_* — what triggered the send
     * @return array{sent: int, failed: int, invalid_tokens_removed: int, reasons: array<string, int>,
     *               tickets: array<int, array{status: string, id: ?string, error: ?string}>}
     */
    public function sendMessages(array $messages, string $source = 'unknown'): array
    {
        $sent = 0;
        $failed = 0;
        $invalid = [];
        $failureReasons = [];
        /** @var array<int, array{status: string, id: ?string, error: ?string}> $ticketsOut  in message order */
        $ticketsOut = [];
        /** @var array<string, string> $accepted  ticket id => token */
        $accepted = [];

        AppLog::event('push.attempt', ['source' => $source, 'message_count' => count($messages), 'batches' => (int) ceil(count($messages) / self::BATCH_SIZE)]);

        foreach (array_chunk($messages, self::BATCH_SIZE) as $chunk) {
            $batchFailure = null;
            try {
                $response = $this->client()->post(config('ballspot.notifications.expo_push_url'), $chunk);
            } catch (\Throwable $e) {
                // Exception class only — the message could echo request data.
                AppLog::warn('push.batch_failed', ['source' => $source, 'reason' => 'http_exception', 'exception' => get_class($e), 'count' => count($chunk)]);
                $batchFailure = 'http_exception';
            }

            if ($batchFailure === null && ! $response->successful()) {
                AppLog::warn('push.batch_failed', [
                    'source' => $source, 'reason' => 'http_status', 'status' => $response->status(),
                    'count'  => count($chunk), 'expo_errors' => $this->requestErrorCodes($response->json()),
                ]);
                $batchFailure = 'http_' . $response->status();
            }

            if ($batchFailure !== null) {
                $failed += count($chunk);
                $failureReasons[$batchFailure] = ($failureReasons[$batchFailure] ?? 0) + count($chunk);
                foreach ($chunk as $_) {
                    $ticketsOut[] = ['status' => 'error', 'id' => null, 'error' => $batchFailure];
                }
                continue;
            }

            // A 200 can still carry request-level errors next to (or instead
            // of) the tickets, e.g. an access-token problem.
            $requestErrors = $this->requestErrorCodes($response->json());
            if ($requestErrors !== []) {
                AppLog::warn('push.request_errors', ['source' => $source, 'expo_errors' => $requestErrors]);
            }

            // Expo returns { data: [ { status: "ok", id } | { status: "error", details }, ... ] }
            // with one ticket per message, in order.
            $tickets = $response->json('data', []);
            foreach (array_values($chunk) as $i => $message) {
                $ticket = is_array($tickets) ? ($tickets[$i] ?? null) : null;
                if (($ticket['status'] ?? null) === 'ok') {
                    $sent++;
                    $id = isset($ticket['id']) && is_string($ticket['id']) ? $ticket['id'] : null;
                    if ($id !== null) {
                        $accepted[$id] = $message['to'];
                    }
                    $ticketsOut[] = ['status' => 'ok', 'id' => $id, 'error' => null];
                    continue;
                }
                $failed++;
                $reason = (string) ($ticket['details']['error'] ?? ($ticket === null ? 'missing_ticket' : 'ticket_error'));
                $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
                $ticketsOut[] = ['status' => 'error', 'id' => null, 'error' => $reason];
                if ($reason === 'DeviceNotRegistered') {
                    $invalid[] = $message['to'];
                }
            }
        }

        $this->recordAcceptedTickets($accepted, $source);

        $removed = 0;
        if ($invalid !== []) {
            $removed = PushToken::whereIn('token', $invalid)->delete();
            Log::info('Pruned DeviceNotRegistered push tokens', ['count' => $removed]);
        }

        if ($failed > 0) {
            // Reason categories + counts only. Never the Expo tokens.
            AppLog::warn('push.failures', ['source' => $source, 'sent' => $sent, 'failed' => $failed, 'reasons' => $failureReasons, 'invalid_tokens_removed' => $removed]);
        } else {
            AppLog::event('push.sent', ['source' => $source, 'sent' => $sent, 'failed' => 0, 'invalid_tokens_removed' => $removed]);
        }

        return [
            'sent' => $sent, 'failed' => $failed, 'invalid_tokens_removed' => $removed,
            'reasons' => $failureReasons, 'tickets' => $ticketsOut,
        ];
    }

    /**
     * Expo HTTP client: JSON, a bounded timeout, the optional access token
     * (required once "enhanced push security" is switched on for the Expo
     * project), and a retry only for answers that mean "nothing was sent"
     * (429 rate limit, 503 unavailable). Connection errors are not retried:
     * the request may have gone through, and a retry would double-notify.
     */
    public function client(): PendingRequest
    {
        $request = Http::asJson()
            ->acceptJson()
            ->timeout((int) config('ballspot.notifications.expo_timeout_seconds', 20))
            ->retry(
                3,
                fn (int $attempt) => $attempt * 1000,
                fn ($e) => $e instanceof RequestException && in_array($e->response->status(), [429, 503], true),
                throw: false,
            );

        $accessToken = (string) config('ballspot.notifications.expo_access_token', '');

        return $accessToken !== '' ? $request->withToken($accessToken) : $request;
    }

    /**
     * Request-level error codes from an Expo answer ({ errors: [{ code }] }).
     * Codes only: the messages can echo request data.
     *
     * @return array<int, string>
     */
    public function requestErrorCodes(mixed $json): array
    {
        if (! is_array($json) || ! isset($json['errors']) || ! is_array($json['errors'])) {
            return [];
        }

        return array_values(array_map(
            fn ($e) => is_array($e) ? (string) ($e['code'] ?? 'unknown') : 'unknown',
            $json['errors'],
        ));
    }

    /**
     * Store accepted ticket ids for the receipt check. Best effort: a missing
     * table (deploy before migrate) or a DB hiccup must never turn a delivered
     * push into a failed send.
     *
     * @param  array<string, string>  $accepted  ticket id => token
     */
    private function recordAcceptedTickets(array $accepted, string $source): void
    {
        if ($accepted === []) {
            return;
        }

        try {
            $tokens = PushToken::whereIn('token', array_values($accepted))
                ->get(['id', 'token', 'platform'])
                ->keyBy('token');

            $now = now();
            $rows = [];
            foreach ($accepted as $ticketId => $token) {
                $row = $tokens->get($token);
                $rows[] = [
                    'ticket_id'     => $ticketId,
                    'push_token_id' => $row?->id,
                    'platform'      => $row?->platform,
                    'source'        => $source,
                    'status'        => PushReceipt::STATUS_PENDING,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                PushReceipt::insertOrIgnore($chunk);
            }
        } catch (\Throwable $e) {
            AppLog::warn('push.receipt_record_failed', ['source' => $source, 'exception' => get_class($e), 'count' => count($accepted)]);
        }
    }

    /**
     * Tokens to deliver to. Users who explicitly opted out of admin
     * notifications are always excluded. `opted_in` additionally requires an
     * enabled settings row; `all` includes users who never touched settings.
     *
     * @return Collection<int, string>
     */
    private function recipientTokens(string $targetType): Collection
    {
        // Never deliver to anonymized (deleted) accounts. Today deletion hard-
        // deletes their push tokens first, so this is defense-in-depth — but the
        // invariant lives in AccountController, and cascades never fire on an
        // anonymized (not deleted) user row, so guard it here explicitly too.
        $query = PushToken::query()
            ->whereHas('user', fn ($q) => $q->whereNull('anonymized_at'));

        if ($targetType === AdminNotification::TARGET_OPTED_IN) {
            $query->whereHas('user.notificationSetting', fn ($q) => $q->where('admin_notifications_enabled', true));
        } else {
            // TARGET_ALL — everyone except users who explicitly opted out.
            $query->whereDoesntHave('user.notificationSetting', fn ($q) => $q->where('admin_notifications_enabled', false));
        }

        return $query->pluck('token');
    }

}
