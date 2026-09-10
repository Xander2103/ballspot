<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a one-time 6-digit code to verify a newly registered email address.
 *
 * The plain code lives only on this in-memory instance and in the outgoing
 * email — the database stores only a hash, and no API response returns it.
 * With MAIL_MAILER=log the message (including the code) is written to
 * storage/logs/laravel.log for local development.
 */
class EmailVerificationCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $code,
        public int $expiryMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('ballspot.app_name');

        // Rendered under the recipient's preferred_language (HasLocalePreference).
        return (new MailMessage)
            ->subject(__('emails.verify.subject', ['app' => $app]))
            ->greeting(__('emails.verify.greeting', ['app' => $app]))
            ->line(__('emails.verify.code', ['code' => $this->code]))
            ->line(__('emails.verify.expires', ['minutes' => $this->expiryMinutes]))
            ->line(__('emails.verify.ignore'));
    }
}
