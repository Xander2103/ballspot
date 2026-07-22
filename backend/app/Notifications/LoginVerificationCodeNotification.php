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

        return (new MailMessage)
            ->subject('Your ' . $app . ' login code')
            ->greeting('Verify your login')
            ->line('Your login code is: ' . $this->code)
            ->line('This code expires in ' . $this->expiryMinutes . ' minutes.')
            ->line('If this was not you, you can ignore this email.');
    }
}
