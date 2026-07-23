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
        $appName = config('ballspot.app_name');
        $email   = urlencode($notifiable->getEmailForPasswordReset());

        // Prefer an explicit full reset-screen URL; otherwise derive it from the
        // frontend app base. The token + email are always carried as query
        // params so the app's reset-password screen can complete the reset.
        $base = config('ballspot.password_reset_url')
            ?: rtrim(config('ballspot.frontend_url'), '/') . '/reset-password';
        $glue = str_contains($base, '?') ? '&' : '?';
        $url  = "{$base}{$glue}token={$this->token}&email={$email}";

        return (new MailMessage)
            ->subject("Reset your {$appName} password")
            ->greeting('Reset your password')
            ->line("You are receiving this email because we received a password reset request for your {$appName} account.")
            ->action('Reset Password', $url)
            ->line('If you did not request a password reset, no further action is required. You can safely ignore this email.')
            ->line('This password reset link will expire soon.')
            ->salutation("Regards,\n{$appName}");
    }
}
