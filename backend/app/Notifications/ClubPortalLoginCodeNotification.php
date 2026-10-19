<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails the 6-digit sign-in code for the BallPicker Club Portal (/club).
 *
 * Sent synchronously (no ShouldQueue): the code must arrive while the
 * person waits on the verify page. The plain code exists only on this
 * in-memory instance and in the outgoing mail — never in the DB (hash) or
 * a log line. Rendered in the recipient's preferred_language
 * (User implements HasLocalePreference).
 */
class ClubPortalLoginCodeNotification extends Notification
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
        return (new MailMessage)
            ->subject(__('club_portal.email.subject'))
            ->greeting(__('club_portal.email.greeting'))
            ->line(__('club_portal.email.intro'))
            ->line(__('club_portal.email.code', ['code' => $this->code]))
            ->line(__('club_portal.email.expires', ['minutes' => $this->expiryMinutes]))
            ->line(__('club_portal.email.ignore'));
    }
}
