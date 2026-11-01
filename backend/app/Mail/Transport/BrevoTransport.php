<?php

namespace App\Mail\Transport;

use App\Support\AppLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Laravel mail transport for Brevo's transactional email HTTPS API.
 * Selected with MAIL_MAILER=brevo; setup and rollback: docs/brevo-email.md.
 *
 * Every BallPicker email is sent synchronously inside the HTTP request (none
 * of them is queued), which is why the timeouts are short and there is no
 * retry here: a slow provider must not hang a login, and a retry after a
 * timeout could deliver a code twice. The callers already turn an exception
 * into their "could not send" state, so any send that Brevo did not accept
 * MUST end in a TransportException.
 *
 * Logs and exception messages carry reason codes only: never the API key, a
 * recipient address, the subject or the body (these emails hold login codes
 * and reset tokens). Exception messages can reach failed_jobs and laravel.log,
 * so they do not even include Brevo's free-text error message.
 */
final class BrevoTransport extends AbstractTransport
{
    public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    /** Brevo's documented maximum for a sender/recipient display name. */
    private const MAX_NAME_LENGTH = 70;

    /** Set through dedicated API fields, or not accepted as custom headers. */
    private const NON_CUSTOM_HEADERS = [
        'from', 'sender', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'return-path',
        'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding',
    ];

    public function __construct(
        #[\SensitiveParameter]
        private readonly ?string $apiKey,
        private readonly int $timeoutSeconds = 10,
        private readonly int $connectTimeoutSeconds = 5,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (blank($this->apiKey)) {
            $this->fail('missing_api_key', 'BREVO_API_KEY is not configured.');
        }

        $email   = MessageConverter::toEmail($message->getOriginalMessage());
        $payload = $this->payload($email, $message->getEnvelope());

        try {
            // No redirects: a 3xx is a failure here, and following one would
            // hand the api-key header to wherever it points.
            $response = Http::withHeaders(['api-key' => $this->apiKey])
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->withoutRedirecting()
                ->post(self::ENDPOINT, $payload);
        } catch (ConnectionException $e) {
            // Could be a timeout after Brevo accepted it, so the outcome is
            // unknown; treat it as failed, the user can ask for a new email.
            $this->fail('connection_failed', 'Could not reach the Brevo API.', [
                'detail' => $this->clean($e->getMessage()),
            ]);
        } catch (\Throwable $e) {
            $this->fail('request_failed', 'The Brevo API request could not be made.', [
                'exception' => class_basename($e),
            ]);
        }

        $status   = $response->status();
        $apiError = $response->json('code');
        $apiError = is_string($apiError) ? $this->clean($apiError) : null;

        if ($status !== 201) {
            $apiMessage = $response->json('message');

            $this->fail(
                'api_rejected',
                sprintf('Brevo API rejected the message (HTTP %d%s).', $status, $apiError ? ", {$apiError}" : ''),
                [
                    'http_status' => $status,
                    'api_error'   => $apiError,
                    'api_message' => is_string($apiMessage) ? $this->clean($apiMessage) : null,
                ],
            );
        }

        // A 201 without a message id is not what Brevo documents for this
        // request (a proxy page, for instance), so it is not proof of a send.
        $messageId = $response->json('messageId');
        if (!is_string($messageId) || $messageId === '') {
            $this->fail('invalid_response', 'Brevo API returned no message id.', ['http_status' => $status]);
        }

        $message->setMessageId($messageId);

        AppLog::event('mail.brevo.sent', [
            'message_id'  => $messageId,
            'recipients'  => count($message->getEnvelope()->getRecipients()),
            'attachments' => count($payload['attachment'] ?? []),
        ]);
    }

    public function __toString(): string
    {
        return 'brevo+api://api.brevo.com';
    }

    private function payload(Email $email, Envelope $envelope): array
    {
        $to = $this->toRecipients($email, $envelope);
        if ($to === []) {
            // Brevo requires `to`; a BCC-only message cannot be expressed.
            $this->fail('no_to_recipient', 'Brevo requires at least one To recipient.');
        }

        $sender = $email->getFrom()[0] ?? $envelope->getSender();

        return array_filter([
            'sender'      => $this->address($sender),
            'to'          => array_map($this->address(...), $to),
            'cc'          => array_map($this->address(...), $email->getCc()),
            'bcc'         => array_map($this->address(...), $email->getBcc()),
            'replyTo'     => ($replyTo = $email->getReplyTo()[0] ?? null) ? $this->address($replyTo) : null,
            'subject'     => $email->getSubject(),
            'htmlContent' => $this->body($email->getHtmlBody()),
            'textContent' => $this->body($email->getTextBody()),
            'attachment'  => $this->attachments($email),
            'headers'     => $this->customHeaders($email),
            'tags'        => $this->tags($email),
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    /** @return Address[] The envelope's recipients that are not Cc or Bcc. */
    private function toRecipients(Email $email, Envelope $envelope): array
    {
        $copied = array_map(
            fn (Address $address) => strtolower($address->getAddress()),
            [...$email->getCc(), ...$email->getBcc()],
        );

        return array_values(array_filter(
            $envelope->getRecipients(),
            fn (Address $address) => !in_array(strtolower($address->getAddress()), $copied, true),
        ));
    }

    private function address(Address $address): array
    {
        $name = trim($address->getName());

        return $name === ''
            ? ['email' => $address->getAddress()]
            : ['email' => $address->getAddress(), 'name' => mb_substr($name, 0, self::MAX_NAME_LENGTH)];
    }

    /** @param resource|string|null $body */
    private function body(mixed $body): ?string
    {
        return is_resource($body) ? (string) stream_get_contents($body) : $body;
    }

    private function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $index => $part) {
            // Brevo documents no cid: support: the file still arrives, but an
            // <img src="cid:…"> in the HTML will not resolve.
            if ($part->getDisposition() === 'inline') {
                AppLog::warn('mail.brevo.inline_attachment_not_embedded');
            }

            $attachments[] = [
                'name'    => $part->getFilename() ?? sprintf('attachment-%d.%s', $index + 1, $part->getMediaSubtype()),
                'content' => base64_encode($part->getBody()),
            ];
        }

        return $attachments;
    }

    private function customHeaders(Email $email): array
    {
        $headers = [];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (!$header instanceof TagHeader && !in_array($name, self::NON_CUSTOM_HEADERS, true)) {
                $headers[$header->getName()] = $header->getBodyAsString();
            }
        }

        return $headers;
    }

    private function tags(Email $email): array
    {
        $tags = [];

        foreach ($email->getHeaders()->all() as $header) {
            if ($header instanceof TagHeader) {
                $tags[] = $header->getValue();
            }
        }

        return $tags;
    }

    /** Provider text may echo an address, and must never echo the key. */
    private function clean(string $text): string
    {
        $text = str_replace((string) $this->apiKey, '[redacted]', $text);

        return (string) preg_replace('/[^\s@<>"\'(),;:]+@[^\s@<>"\'(),;:]+/', '[email]', $text);
    }

    private function fail(string $reason, string $exceptionMessage, array $context = []): never
    {
        AppLog::error('mail.brevo.failed', ['reason' => $reason] + array_filter($context, fn ($v) => $v !== null));

        throw new TransportException($exceptionMessage);
    }
}
