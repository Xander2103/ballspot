<?php

namespace App\Services;

use App\Models\PushReceipt;
use App\Models\PushToken;
use App\Support\AppLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fetches Expo push receipts for accepted tickets: the only place that says
 * whether APNs / FCM actually took the message. A ticket "ok" alone is not
 * delivery.
 *
 * Error handling per Expo's documented receipt errors:
 *  - DeviceNotRegistered: the app was uninstalled or the token is dead —
 *    the token row is removed so it is not sent to again.
 *  - InvalidCredentials / MismatchSenderId: a project configuration problem
 *    (APNs key / FCM V1 service account). Logged as an error; tokens are kept,
 *    since they become valid again once the credentials are fixed.
 *  - MessageTooBig / MessageRateExceeded / anything else: recorded and logged.
 *
 * Bounded: at most $limit tickets per run, ≤1000 ids per Expo request.
 */
class PushReceiptService
{
    public const IDS_PER_REQUEST = 1000;

    /** Receipt errors that point at server credentials, never at the device. */
    public const CREDENTIAL_ERRORS = ['InvalidCredentials', 'MismatchSenderId'];

    /** Expo keeps receipts for about 24 hours. */
    public const EXPIRE_AFTER_HOURS = 25;

    public function __construct(private ExpoPushService $push)
    {
    }

    /**
     * @return array{checked: int, ok: int, error: int, still_pending: int, expired: int,
     *               tokens_removed: int, errors: array<string, int>, pruned: int, request_failed: bool}
     */
    public function checkPending(int $limit = 3000, ?int $delayMinutes = null): array
    {
        $delayMinutes ??= (int) config('ballspot.notifications.receipt_delay_minutes', 15);

        $result = [
            'checked' => 0, 'ok' => 0, 'error' => 0, 'still_pending' => 0, 'expired' => 0,
            'tokens_removed' => 0, 'errors' => [], 'pruned' => 0, 'request_failed' => false,
        ];

        $result['expired'] = PushReceipt::where('status', PushReceipt::STATUS_PENDING)
            ->where('created_at', '<', now()->subHours(self::EXPIRE_AFTER_HOURS))
            ->update(['status' => PushReceipt::STATUS_EXPIRED, 'checked_at' => now()]);

        $pending = PushReceipt::where('status', PushReceipt::STATUS_PENDING)
            ->where('created_at', '<=', now()->subMinutes($delayMinutes))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($pending->chunk(self::IDS_PER_REQUEST) as $chunk) {
            $outcome = $this->checkChunk($chunk);
            foreach (['checked', 'ok', 'error', 'still_pending', 'tokens_removed'] as $key) {
                $result[$key] += $outcome[$key];
            }
            foreach ($outcome['errors'] as $code => $n) {
                $result['errors'][$code] = ($result['errors'][$code] ?? 0) + $n;
            }
            $result['request_failed'] = $result['request_failed'] || $outcome['request_failed'];
        }

        $result['pruned'] = PushReceipt::where('created_at', '<', now()->subDays(
            max(1, (int) config('ballspot.notifications.receipt_retention_days', 30))
        ))->delete();

        $credentialErrors = array_intersect_key($result['errors'], array_flip(self::CREDENTIAL_ERRORS));
        if ($credentialErrors !== []) {
            AppLog::error('push.receipts_credentials', ['errors' => $credentialErrors]);
        }

        if ($result['checked'] > 0 || $result['expired'] > 0) {
            $result['error'] > 0 || $result['request_failed']
                ? AppLog::warn('push.receipts', $result)
                : AppLog::event('push.receipts', $result);
        }

        return $result;
    }

    /**
     * Check specific receipts right now (the admin test command): no delay.
     *
     * @param  Collection<int, PushReceipt>  $receipts
     */
    public function checkNow(Collection $receipts): array
    {
        return $this->checkChunk($receipts->where('status', PushReceipt::STATUS_PENDING)->values());
    }

    /**
     * @param  Collection<int, PushReceipt>  $chunk
     * @return array{checked: int, ok: int, error: int, still_pending: int, tokens_removed: int,
     *               errors: array<string, int>, request_failed: bool}
     */
    private function checkChunk(Collection $chunk): array
    {
        $out = ['checked' => 0, 'ok' => 0, 'error' => 0, 'still_pending' => 0, 'tokens_removed' => 0, 'errors' => [], 'request_failed' => false];
        if ($chunk->isEmpty()) {
            return $out;
        }

        try {
            $response = $this->push->client()->post(
                config('ballspot.notifications.expo_receipts_url'),
                ['ids' => $chunk->pluck('ticket_id')->values()->all()],
            );
        } catch (\Throwable $e) {
            AppLog::warn('push.receipts_request_failed', ['reason' => 'http_exception', 'exception' => get_class($e), 'count' => $chunk->count()]);
            $out['request_failed'] = true;
            $out['still_pending'] = $chunk->count();

            return $out;
        }

        if (! $response->successful()) {
            AppLog::warn('push.receipts_request_failed', [
                'reason' => 'http_status', 'status' => $response->status(), 'count' => $chunk->count(),
                'expo_errors' => $this->push->requestErrorCodes($response->json()),
            ]);
            $out['request_failed'] = true;
            $out['still_pending'] = $chunk->count();

            return $out;
        }

        // { data: { "<ticket id>": { status: "ok" } | { status: "error", details: { error } } } }
        // A ticket id missing from data means "not ready yet": leave it pending.
        $receipts = $response->json('data', []);
        $receipts = is_array($receipts) ? $receipts : [];
        $deadTokenIds = [];
        $now = Carbon::now();

        foreach ($chunk as $row) {
            $receipt = $receipts[$row->ticket_id] ?? null;
            if (! is_array($receipt) || ! isset($receipt['status'])) {
                $out['still_pending']++;
                continue;
            }

            $out['checked']++;
            if ($receipt['status'] === 'ok') {
                $out['ok']++;
                $row->update(['status' => PushReceipt::STATUS_OK, 'error' => null, 'checked_at' => $now]);
                continue;
            }

            $code = substr((string) ($receipt['details']['error'] ?? 'receipt_error'), 0, 64);
            $out['error']++;
            $out['errors'][$code] = ($out['errors'][$code] ?? 0) + 1;
            $row->update(['status' => PushReceipt::STATUS_ERROR, 'error' => $code, 'checked_at' => $now]);

            if ($code === 'DeviceNotRegistered' && $row->push_token_id !== null) {
                $deadTokenIds[] = $row->push_token_id;
            }
        }

        if ($deadTokenIds !== []) {
            // push_receipts.push_token_id is nulled by the foreign key.
            $out['tokens_removed'] = PushToken::whereIn('id', array_unique($deadTokenIds))->delete();
        }

        return $out;
    }
}
