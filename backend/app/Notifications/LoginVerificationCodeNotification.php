<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a one-time 6-digit login code.
 *
 * The plain code lives only on this in-memory notification instance and in the
 * outgoing email — it is never persisted in plain form (the DB stores a hash)
 * and never returned by the API. With MAIL_MAILER=log the message (including
 * the code) is written to storage/logs/laravel.log for local development.
 */
class LoginVerificationCodeNotification extends Notification
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
            ->subject(__('emails.login_code.subject', ['app' => $app]))
            ->greeting(__('emails.login_code.greeting'))
            ->line(__('emails.login_code.code', ['code' => $this->code]))
            ->line(__('emails.login_code.expires', ['minutes' => $this->expiryMinutes]))
            ->line(__('emails.login_code.ignore'));
    }
}
