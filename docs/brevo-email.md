# Brevo transactional email

BallPicker can send email through Brevo's HTTPS API
(`POST https://api.brevo.com/v3/smtp/email`) instead of SMTP. The transport is
`App\Mail\Transport\BrevoTransport`, selected with `MAIL_MAILER=brevo`.
SMTP stays fully configured next to it, so switching in either direction is a
one-line `.env` change.

Nothing changes until `MAIL_MAILER` changes: deploying this code is inert.

## 1. What goes through the mailer

| Email | Class | Triggered by | When the send fails |
|---|---|---|---|
| Email verification code | `EmailVerificationCodeNotification` | `EmailVerificationService` (register, resend, login of an unverified account) | Code row removed, `code_sent: false`, log `email_verification.send_failed` |
| Login (2FA) code | `LoginVerificationCodeNotification` | `LoginVerificationService` (login, resend) | Stable `503 two_factor_send_failed`; on resend the previous code stays valid |
| Password reset link | `ResetPasswordNotification` | `PasswordResetFlow` (API and web form) | Generic success answer (no enumeration), log `password_reset.requested {outcome: send_failed}` |
| Ops alert | `OpsAlertMail` | `ballspot:send-alert`, from `scripts/monitor-health.sh` | Command exits 1; the script falls back to sendmail/mail |

**None of these is queued.** No notification or mailable implements
`ShouldQueue`, so each email is sent inside the HTTP request (or the command).
The `database` queue and the `ballpicker-worker` program do not carry mail
today. Consequences:

- A provider outage is visible immediately as the failure states above; nothing
  is retried later, and nothing lands in `failed_jobs`.
- The API timeout is how long a user can wait on a login or registration, which
  is why it is short.
- The transport works unchanged if a mail is queued later: a failed send throws,
  so the job is retried or fails normally.

## 2. Configuration

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `MAIL_MAILER` | yes | `log` | `brevo` to use this transport, `smtp` for SMTP |
| `BREVO_API_KEY` | with `brevo` | none | The API key. Only ever in `.env`, never in the repo |
| `BREVO_TIMEOUT` | no | `10` | Whole request, seconds |
| `BREVO_CONNECT_TIMEOUT` | no | `5` | Connection phase, seconds |
| `MAIL_FROM_ADDRESS` | yes | | Must be a verified Brevo sender (`info@vanmalderstudio.be`) |
| `MAIL_FROM_NAME` | yes | | Display name; cut to Brevo's maximum of 70 characters |

Keep every existing `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME` and
`MAIL_PASSWORD` value: those are the rollback.

After any `.env` change, `config:cache` must run or the app keeps using the old
values. `ballspot:store-readiness-check` warns in production when
`MAIL_MAILER=brevo` is set without a key (it never prints the key).

## 3. Behaviour

**A send counts as successful only on HTTP 201 with a `messageId`.** Everything
else throws `Symfony\Component\Mailer\Exception\TransportException`, so the
existing failure handling in section 1 runs: any 3xx, 4xx or 5xx, other 2xx
codes, a 201 without a message id, a timeout, a connection failure, a missing
key, and a message with no To recipient (Brevo requires `to`, so BCC-only mail
is refused).

- **Timeouts:** values below 1 are raised to 1, because 0 means "no timeout".
- **No retries in the transport:** a retry would block the request longer, and
  a timeout does not prove Brevo did not accept the message. After a timeout the
  outcome is unknown; the app treats it as failed and the user can request a new
  email, so a rare duplicate is possible.
- **No redirects:** a 3xx is a failure, and the `api-key` header is never sent
  to another host.
- **Mapped:** sender, To, Cc, Bcc, Reply-To, subject, HTML, text, attachments,
  custom headers and tags (`TagHeader`).
- **Text-only mail:** Brevo's API reference calls `htmlContent` required, while
  its guide shows a text-only send. BallPicker's emails all have HTML, so this is
  not exercised against the real API.
- **Attachments** are sent base64-encoded. Brevo accepts only certain file types
  (its documentation lists them; `.pdf`, `.txt`, `.csv`, `.png`, `.jpg`, `.ics`,
  `.zip` and others) and rejects the rest, which surfaces as a normal failure.
  BallPicker sends no attachments today.
- **Inline images (`cid:`):** Brevo's API documentation describes no support for
  them. Such a file is sent as a normal attachment and
  `mail.brevo.inline_attachment_not_embedded` is logged. BallPicker's emails do
  not embed images.

**Logging.** Events go to `storage/logs/ballpicker-events-YYYY-MM-DD.log`, and
failures also reach `laravel.log`. Recipient addresses in Brevo's error text are
replaced with `[email]`. The API key, addresses, subjects and bodies are never
logged.

| Event | Fields |
|---|---|
| `mail.brevo.sent` | `message_id`, `recipients` (count), `attachments` (count) |
| `mail.brevo.failed` | `reason`, plus `http_status`, `api_error`, `api_message`, `detail` or `exception` where relevant |

| `reason` | Meaning | Check |
|---|---|---|
| `missing_api_key` | No key in the cached config | `.env`, then `config:cache` |
| `api_rejected` | Brevo answered something other than 201 | `http_status` and `api_error`, see below |
| `invalid_response` | 201 without a message id | Proxy or provider incident |
| `connection_failed` | Timeout, DNS or TLS failure | Outbound HTTPS from the server, Brevo status page |
| `request_failed` | The request could not be built | `exception`; a bug, not an outage |
| `no_to_recipient` | Message had no To address | The calling code |

| HTTP | Meaning | Check |
|---|---|---|
| 400 | Invalid request. Sender or domain problems also appear here | `api_message`; sender and domain status in Brevo |
| 401 | Invalid key, or possibly the server IP is not authorized | The key; Brevo's Authorized IPs security setting |
| 402 | Activation or credits needed | Brevo plan and credits |
| 429 | Rate limit | `x-sib-ratelimit-*` response headers; Brevo plan |

## 4. Deployment

Deploying the code is safe at any time, because SMTP remains active. Use the
normal `./deploy.sh` (`docs/ops-runbook.md` §5.3). It runs `config:cache`,
`queue:restart` and the php-fpm reload.

Run every artisan command as `www-data` (`docs/ops-runbook.md` §5.1). A file
created by another user is what caused the earlier outages.

## 5. Switching production to Brevo

The full release order, including the commits, is in
`docs/release-brevo-landing-2026-11.md`. This section is the mail part on its own.

**Prerequisites**

- [ ] `vanmalderstudio.be` is authenticated in Brevo and `info@vanmalderstudio.be`
      is a verified sender. Public DNS already has the Brevo code, both DKIM
      CNAMEs and a DMARC record (`p=none`), checked 2026-11-01.
- [ ] The existing SPF record stays as it is. It covers the ZXCS rollback route.
      Brevo mail reaches DMARC through DKIM, so SPF needs no Brevo entry.
- [ ] Log retention at Brevo is set to 30 days. **The default is to keep logs and
      email previews indefinitely, and the privacy page promises 30 days.**
- [ ] The Brevo account uses two-factor login. Its log previews show the content
      of emails, so a live reset link is visible there until it expires (60 min).
- [ ] Click tracking: no self-service switch could be found in Brevo's public
      documentation. Ask Brevo support whether it can be turned off for this
      account, so reset links are not rewritten.
- [ ] The plan's daily limit covers registrations, resets and alerts.
- [ ] If Brevo's Authorized IPs setting is on, it allows `168.119.248.14`.

**Step 1: add the key, SMTP stays active.** `.env` is owned by `deploy`, so edit
it directly. `sudoedit` refuses to work in a directory you can write to.

```bash
cd /var/www/ballpicker/backend
sudo cp -p .env /root/ballpicker.env.$(date +%F-%H%M).bak
nano .env        # add one line: BREVO_API_KEY=…   Change nothing else.
grep -c '^BREVO_API_KEY=.' .env        # expected: 1 (shows no value)
grep -E '^MAIL_(MAILER|HOST|PORT|FROM_ADDRESS|FROM_NAME)=' .env   # MAIL_MAILER=smtp, FROM info@vanmalderstudio.be
sudo -u www-data -H php artisan config:cache
```

**Step 2: send one real test email through Brevo, SMTP still active.** Use a
Gmail address of your own, because Gmail shows the authentication results.

```bash
sudo -u www-data -H php artisan ballspot:send-alert --mailer=brevo --to=YOUR-ADDRESS \
  --subject="BallPicker Brevo test" --body="One test email through the Brevo API."
grep 'mail.brevo' storage/logs/ballpicker-events-$(date +%F).log | tail -3
```

Expected: `Alert sent.` and one `mail.brevo.sent` line with a `message_id`.

Check delivery, using Gmail's menu > "Show original":

| Check | Required |
|---|---|
| The email arrived in the inbox, not spam | yes |
| `DKIM: PASS` with domain `vanmalderstudio.be` | yes |
| `DMARC: PASS` | yes |
| `SPF: PASS` (the domain will be Brevo's, not aligned, which is normal) | yes |
| A tracking pixel in the HTML source (a 1x1 `<img>` at the end) | note it: see below |
| The same `message_id` in Brevo > Transactional > Logs, status delivered | yes |

**Stop if DKIM or DMARC fails**, and stay on SMTP. If a pixel is present, ask
Brevo support to remove it: the page says we use no analytics trackers, and only
the delivery wording in the Brevo entry covers opens.

**Step 3: switch.**

```bash
nano .env        # MAIL_MAILER=brevo
sudo -u www-data -H php artisan config:cache
sudo -u www-data -H php artisan queue:restart
sudo supervisorctl restart ballpicker-worker
sudo systemctl reload php8.3-fpm
sudo -u www-data -H php artisan ballspot:store-readiness-check | grep -E 'MAIL_MAILER|BREVO'
```

Expected: `MAIL_MAILER=brevo` and `BREVO_API_KEY is set`. The worker restart is a
precaution, since it sends no mail today.

**Step 4: verify the real flows** with a test account in a mailbox you control:

1. Forgot password: the link arrives, and the reset works. Check the link in the
   source: it must point at `ballpicker.vanmalderstudio.be`. If it is rewritten to
   a Brevo address, the token passes through Brevo: roll back unless you accept that.
2. Register: the verification code arrives and works.
3. If a 2FA test account exists: the login code arrives and works.
4. `grep -c 'mail.brevo.sent' storage/logs/ballpicker-events-$(date +%F).log` goes
   up, and `grep 'mail.brevo.failed' storage/logs/ballpicker-events-$(date +%F).log`
   prints nothing.

Then watch those logs and Brevo's delivered, bounced and blocked figures for 24 hours.

## 6. Rollback to SMTP

Roll back on repeated `mail.brevo.failed` lines, DMARC failures, reports of missing
mail or rewritten reset links. It takes under a minute and needs no code deploy.

```bash
cd /var/www/ballpicker/backend
nano .env        # MAIL_MAILER=smtp   (the other MAIL_* values were never touched)
sudo -u www-data -H php artisan config:cache
sudo -u www-data -H php artisan queue:restart
sudo supervisorctl restart ballpicker-worker
sudo systemctl reload php8.3-fpm
sudo -u www-data -H php artisan ballspot:store-readiness-check | grep MAIL_MAILER
```

Expected: `MAIL_MAILER=smtp`. Then test one reset email. `BREVO_API_KEY` can stay
in `.env`, unused. Revoke it in Brevo only if it may have leaked.

**Set `MAIL_MAILER=smtp` before any code rollback.** Older code has no `brevo`
transport, so `./deploy.sh --ref=<old commit>` with `MAIL_MAILER=brevo` still set
makes every email fail.

If you stay on SMTP for more than a short time, the privacy page overstates
Brevo's role: revert the privacy commit too.

## 7. Tests

`backend/tests/Feature/BrevoMailTransportTest.php` mocks every HTTP response and
refuses stray requests, so it cannot send a real email:

```bash
cd backend && php artisan test tests/Feature/BrevoMailTransportTest.php
```
