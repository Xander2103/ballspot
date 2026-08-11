<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\PushToken;
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

        $outcome = $this->sendMessages($messages);

        $notification->update([
            'status'   => $outcome['failed'] > 0 && $outcome['sent'] === 0
                ? AdminNotification::STATUS_FAILED
                : AdminNotification::STATUS_SENT,
            'sent_at'  => now(),
            'metadata' => [
                'recipients' => $tokens->count(),
                'sent'       => $outcome['sent'],
                'failed'     => $outcome['failed'],
            ],
        ]);

        return $notification->fresh();
    }

    /**
     * Send raw Expo push messages in batches of ≤100. Parses per-message
     * tickets; tokens Expo reports as DeviceNotRegistered are deleted so we
     * stop sending to dead devices. Synchronous by design — this codebase has
     * no queue worker dependency.
     *
     * @param  array<int, array{to: string, title: string, body: string, sound: string}>  $messages
     * @return array{sent: int, failed: int, invalid_tokens_removed: int}
     */
    public function sendMessages(array $messages): array
    {
        $sent = 0;
        $failed = 0;
        $invalid = [];

        foreach (array_chunk($messages, self::BATCH_SIZE) as $chunk) {
            try {
                $response = Http::asJson()
                    ->acceptJson()
                    ->post(config('ballspot.notifications.expo_push_url'), $chunk);
            } catch (\Throwable $e) {
                Log::warning('Expo push send failed', ['error' => $e->getMessage(), 'count' => count($chunk)]);
                $failed += count($chunk);
                continue;
            }

            if (! $response->successful()) {
                $failed += count($chunk);
                continue;
            }

            // Expo returns { data: [ { status: "ok" | "error", details? }, ... ] }
            // with one ticket per message, in order.
            $tickets = $response->json('data', []);
            foreach (array_values($chunk) as $i => $message) {
                $ticket = $tickets[$i] ?? null;
                if (($ticket['status'] ?? null) === 'ok') {
                    $sent++;
                    continue;
                }
                $failed++;
                if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    $invalid[] = $message['to'];
                }
            }
        }

        $removed = 0;
        if ($invalid !== []) {
            $removed = PushToken::whereIn('token', $invalid)->delete();
            Log::info('Pruned DeviceNotRegistered push tokens', ['count' => $removed]);
        }

        return ['sent' => $sent, 'failed' => $failed, 'invalid_tokens_removed' => $removed];
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
