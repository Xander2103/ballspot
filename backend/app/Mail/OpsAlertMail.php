<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * Plain operational alert (monitor DOWN / STILL DOWN / RECOVERED). Text only,
 * composed by scripts/monitor-health.sh via `ballspot:send-alert`; it carries
 * the health URL, HTTP code, failing check names and a timestamp — never a
 * secret or a stack trace.
 */
class OpsAlertMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $body,
    ) {}

    public function build(): static
    {
        return $this->subject($this->subjectLine)
            ->html('<pre style="font-family:monospace;white-space:pre-wrap">' . e($this->body) . '</pre>');
    }
}
