<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * API-friendly password reset notification.
 *
 * The default Laravel notification builds a URL from the `password.reset`
 * web route, which this API-only app does not define. Instead we build a
 * link from config (a deep link / web URL) that carries the token + email
 * so the mobile app can complete the reset. With MAIL_MAILER=log the full
 * message (including the token) is written to storage/logs/laravel.log for
 * local development.
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base  = rtrim(config('ballspot.web_url'), '/');
        $email = urlencode($notifiable->getEmailForPasswordReset());
        $url   = "{$base}/reset-password?token={$this->token}&email={$email}";

        return (new MailMessage)
            ->subject('Reset your BallSpot password')
            ->greeting('Reset your password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $url)
            ->line('If you did not request a password reset, no further action is required. You can safely ignore this email.')
            ->line('This password reset link will expire soon.');
    }
}
