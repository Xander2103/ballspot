<?php

namespace App\Services\ClubPortal;

/**
 * A refused club-portal sign-in code step. `reason` is a stable key that the
 * controller maps to lang/<locale>/club_portal.php `verify.errors.<reason>`:
 *
 *  - session   no pending sign-in (unknown/finished challenge) → sign in again
 *  - wrong     wrong code (attempt counted)
 *  - expired   code expired → resend or sign in again
 *  - locked    too many wrong codes → sign in again
 *  - cooldown  resend asked too soon (`retryAfter` seconds)
 *  - mail_limit too many codes emailed to this address this hour (`retryAfter`)
 *  - send_failed the email could not be sent
 */
final class ClubPortalCodeException extends \RuntimeException
{
    public const SESSION     = 'session';
    public const WRONG       = 'wrong';
    public const EXPIRED     = 'expired';
    public const LOCKED      = 'locked';
    public const COOLDOWN    = 'cooldown';
    public const MAIL_LIMIT  = 'mail_limit';
    public const SEND_FAILED = 'send_failed';

    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct('club_portal_code_' . $reason);
    }

    /** True when the pending sign-in is over and the user must enter the password again. */
    public function requiresNewLogin(): bool
    {
        return in_array($this->reason, [self::SESSION, self::LOCKED], true);
    }
}
