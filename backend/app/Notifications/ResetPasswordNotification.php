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
        // lang= lets the web fallback page render in the recipient's language
        // (the app ignores it — it already knows the account's preference).
        $lang = \App\Support\Locale::normalize($notifiable->preferredLocale()) ?? \App\Support\Locale::default();
        $url  = "{$base}{$glue}token={$this->token}&email={$email}&lang={$lang}";

        // Rendered under the recipient's preferred_language (HasLocalePreference).
        return (new MailMessage)
            ->subject(__('emails.reset.subject', ['app' => $appName]))
            ->greeting(__('emails.reset.greeting'))
            ->line(__('emails.reset.intro', ['app' => $appName]))
            ->action(__('emails.reset.action'), $url)
            ->line(__('emails.reset.ignore'))
            ->line(__('emails.reset.expires'))
            ->salutation(__('emails.reset.regards') . ",\n{$appName}");
    }
}
