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

        $sent = 0;
        $failed = 0;

        foreach ($tokens->chunk(self::BATCH_SIZE) as $batch) {
            [$ok, $bad] = $this->sendBatch($notification, $batch->all());
            $sent += $ok;
            $failed += $bad;
        }

        $notification->update([
            'status'   => $failed > 0 && $sent === 0
                ? AdminNotification::STATUS_FAILED
                : AdminNotification::STATUS_SENT,
            'sent_at'  => now(),
            'metadata' => ['recipients' => $tokens->count(), 'sent' => $sent, 'failed' => $failed],
        ]);

        return $notification->fresh();
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
        $query = PushToken::query();

        if ($targetType === AdminNotification::TARGET_OPTED_IN) {
            $query->whereHas('user.notificationSetting', fn ($q) => $q->where('admin_notifications_enabled', true));
        } else {
            // TARGET_ALL — everyone except users who explicitly opted out.
            $query->whereDoesntHave('user.notificationSetting', fn ($q) => $q->where('admin_notifications_enabled', false));
        }

        return $query->pluck('token');
    }

    /**
     * POST one batch of ≤100 messages. Returns [okCount, failedCount]. Invalid
     * tokens are counted as failures and never crash the send.
     *
     * @param  array<int, string>  $tokens
     * @return array{0:int,1:int}
     */
    private function sendBatch(AdminNotification $notification, array $tokens): array
    {
        $messages = array_map(fn (string $token) => [
            'to'    => $token,
            'title' => $notification->title,
            'body'  => $notification->body,
            'sound' => 'default',
        ], $tokens);

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->post(config('ballspot.notifications.expo_push_url'), $messages);
        } catch (\Throwable $e) {
            Log::warning('Expo push send failed', ['error' => $e->getMessage()]);

            return [0, count($tokens)];
        }

        if (! $response->successful()) {
            return [0, count($tokens)];
        }

        // Expo returns { data: [ { status: "ok" | "error", ... }, ... ] }.
        $tickets = $response->json('data', []);
        $ok = 0;
        foreach ($tickets as $ticket) {
            if (($ticket['status'] ?? null) === 'ok') {
                $ok++;
            }
        }

        return [$ok, count($tokens) - $ok];
    }
}
