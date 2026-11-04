<?php

namespace App\Console\Commands;

use App\Http\Requests\RegisterPushTokenRequest;
use App\Models\PushToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only push health report. Counts only: no user IDs, names, e-mails or
 * tokens are printed, so the output can be pasted into a ticket.
 */
class PushAudit extends Command
{
    protected $signature = 'ballspot:push-audit';

    protected $description = 'Read-only push notification health report (counts only, no personal data)';

    public function handle(): int
    {
        $n = config('ballspot.notifications');
        $this->info('Configuration');
        $this->table(['setting', 'value'], [
            ['BALLPICKER_PUSH_ENABLED', ($n['push_enabled'] ?? false) ? 'true' : 'false'],
            ['BALLPICKER_DAILY_REMINDER_PUSH_ENABLED', ($n['daily_reminder_push_enabled'] ?? false) ? 'true' : 'false'],
            ['EXPO_ACCESS_TOKEN', ($n['expo_access_token'] ?? '') !== '' && ($n['expo_access_token'] ?? null) !== null ? 'set' : 'not set'],
            ['app timezone', (string) config('app.timezone')],
            ['queue connection', (string) config('queue.default') . ' (push is sent synchronously)'],
        ]);

        $now = now();
        $tokens = PushToken::query()->get(['id', 'user_id', 'token', 'platform', 'last_seen_at']);
        $valid = $tokens->filter(fn ($t) => preg_match(RegisterPushTokenRequest::TOKEN_PATTERN, $t->token) === 1);

        $this->info('Push tokens');
        $rows = [];
        foreach ($tokens->groupBy(fn ($t) => $t->platform ?? '(none)') as $platform => $group) {
            $rows[] = [
                $platform, $group->count(),
                $group->filter(fn ($t) => preg_match(RegisterPushTokenRequest::TOKEN_PATTERN, $t->token) === 1)->count(),
                $group->filter(fn ($t) => $t->last_seen_at && $t->last_seen_at->gte($now->copy()->subDays(7)))->count(),
                $group->filter(fn ($t) => $t->last_seen_at && $t->last_seen_at->gte($now->copy()->subDays(30)))->count(),
                $group->pluck('user_id')->unique()->count(),
            ];
        }
        $rows[] = ['TOTAL', $tokens->count(), $valid->count(),
            $tokens->filter(fn ($t) => $t->last_seen_at && $t->last_seen_at->gte($now->copy()->subDays(7)))->count(),
            $tokens->filter(fn ($t) => $t->last_seen_at && $t->last_seen_at->gte($now->copy()->subDays(30)))->count(),
            $tokens->pluck('user_id')->unique()->count()];
        $this->table(['platform', 'tokens', 'valid format', 'seen 7d', 'seen 30d', 'accounts'], $rows);

        $perUser = $tokens->countBy('user_id');
        $anonymizedWithTokens = DB::table('push_tokens')->join('users', 'users.id', '=', 'push_tokens.user_id')
            ->whereNotNull('users.anonymized_at')->count();
        $this->table(['check', 'count'], [
            ['accounts with >1 device', $perUser->filter(fn ($c) => $c > 1)->count()],
            ['tokens on deleted (anonymized) accounts', $anonymizedWithTokens],
            ['users total (not deleted)', DB::table('users')->whereNull('anonymized_at')->count()],
        ]);

        $this->info('Notification settings (rows exist only for accounts that saved settings)');
        $s = DB::table('notification_settings');
        $withTokens = DB::table('notification_settings')->whereIn('user_id', DB::table('push_tokens')->select('user_id'));
        $this->table(['setting', 'all rows', 'accounts with a token'], [
            ['rows', (clone $s)->count(), (clone $withTokens)->count()],
            ['daily_reminder_enabled', (clone $s)->where('daily_reminder_enabled', true)->count(), (clone $withTokens)->where('daily_reminder_enabled', true)->count()],
            ['tournament_reminder_enabled', (clone $s)->where('tournament_reminder_enabled', true)->count(), (clone $withTokens)->where('tournament_reminder_enabled', true)->count()],
            ['admin_notifications_enabled', (clone $s)->where('admin_notifications_enabled', true)->count(), (clone $withTokens)->where('admin_notifications_enabled', true)->count()],
            ['admin_notifications disabled', (clone $s)->where('admin_notifications_enabled', false)->count(), (clone $withTokens)->where('admin_notifications_enabled', false)->count()],
            ['reminded today (server push)', (clone $s)->where('last_daily_reminder_date', $now->toDateString())->count(), '-'],
        ]);

        if (Schema::hasTable('admin_notifications')) {
            $this->info('Admin announcements');
            $this->table(['status', 'count'], DB::table('admin_notifications')->select('status', DB::raw('count(*) as c'))
                ->groupBy('status')->get()->map(fn ($r) => [$r->status, $r->c])->all());
            $last = DB::table('admin_notifications')->where('status', 'sent')->latest('sent_at')->first(['sent_at', 'metadata']);
            if ($last) {
                $meta = json_decode((string) $last->metadata, true) ?: [];
                $this->line(sprintf('Last sent: %s — recipients %s, ticket ok %s, ticket failed %s',
                    $last->sent_at, $meta['recipients'] ?? '?', $meta['sent'] ?? '?', $meta['failed'] ?? '?'));
            }
        }

        if (Schema::hasTable('push_receipts')) {
            $this->info('Expo receipts, last 7 days');
            $rows = DB::table('push_receipts')->where('created_at', '>=', $now->copy()->subDays(7))
                ->select('source', 'platform', 'status', 'error', DB::raw('count(*) as c'))
                ->groupBy('source', 'platform', 'status', 'error')->orderBy('source')->get()
                ->map(fn ($r) => [$r->source, $r->platform ?? '?', $r->status, $r->error ?? '', $r->c])->all();
            $rows === [] ? $this->line('(none recorded yet)') : $this->table(['source', 'platform', 'status', 'error', 'count'], $rows);
        } else {
            $this->warn('push_receipts table missing — receipts are not being recorded (run migrate).');
        }

        if (Schema::hasTable('failed_jobs')) {
            $this->line('failed_jobs: ' . DB::table('failed_jobs')->count());
        }

        return self::SUCCESS;
    }
}
