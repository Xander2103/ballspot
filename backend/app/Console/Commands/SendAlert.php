<?php

namespace App\Console\Commands;

use App\Mail\OpsAlertMail;
use App\Support\AppLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends an operational alert email through the app's configured mailer. Used
 * by scripts/monitor-health.sh (which falls back to sendmail/mail when PHP
 * itself is broken). Recipient: --to, else BALLPICKER_ALERT_EMAIL.
 *
 *   php artisan ballspot:send-alert --subject="[BallPicker] DOWN" --body="…"
 *   echo "…" | php artisan ballspot:send-alert --subject="…" --stdin
 */
class SendAlert extends Command
{
    protected $signature = 'ballspot:send-alert
                            {--subject= : Subject line}
                            {--body= : Message text}
                            {--stdin : Read the message text from standard input}
                            {--to= : Recipient (default: BALLPICKER_ALERT_EMAIL)}';

    protected $description = 'Email an operational alert to the configured ops address';

    public function handle(): int
    {
        $to      = (string) ($this->option('to') ?: config('ballspot.monitoring.alert_email'));
        $subject = trim((string) $this->option('subject'));
        $body    = $this->option('body');

        if ($body === null && $this->option('stdin')) {
            $body = defined('STDIN') ? (string) stream_get_contents(STDIN) : '';
        }
        $body = trim((string) $body);

        if ($subject === '') {
            $this->error('--subject is required.');

            return self::INVALID;
        }
        if ($body === '') {
            $this->error('A message is required (--body or --stdin).');

            return self::INVALID;
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('No valid recipient: set BALLPICKER_ALERT_EMAIL or pass --to.');

            return self::INVALID;
        }

        try {
            Mail::to($to)->send(new OpsAlertMail($subject, $body));
        } catch (\Throwable $e) {
            AppLog::error('ops.alert_failed', ['exception' => class_basename($e), 'error' => substr($e->getMessage(), 0, 300)]);
            $this->error('Alert not sent: ' . class_basename($e));

            return self::FAILURE;
        }

        AppLog::event('ops.alert_sent', ['subject' => substr($subject, 0, 120)]);
        $this->line('Alert sent.');

        return self::SUCCESS;
    }
}
