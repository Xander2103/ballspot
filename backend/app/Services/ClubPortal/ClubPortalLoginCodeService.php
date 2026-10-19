<?php

namespace App\Services\ClubPortal;

use App\Models\ClubPortalLoginCode;
use App\Models\User;
use App\Notifications\ClubPortalLoginCodeNotification;
use App\Support\AppLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Mandatory emailed 6-digit code for every new club-portal session
 * (contracts §6, config ballspot.clubs.portal).
 *
 * Own table (club_portal_login_codes) instead of LoginVerificationService:
 * that service is bound to the app's token login (a verified id there mints
 * a Sanctum token on POST /api/login/verify) and start() deletes every
 * pending app code of the user. Same security posture otherwise:
 *  - only a bcrypt hash of the code is stored; codes are never logged;
 *  - codes expire (code_expiry_minutes) and lock after code_max_attempts
 *    wrong tries (attempts are counted in the DB, so replaying an old
 *    session cookie cannot reset them); a locked code needs a new password
 *    sign-in;
 *  - resend has a cooldown and every email (start + resend) counts against
 *    an IP-independent per-address hourly ceiling (code_emails_per_hour);
 *  - one pending code per user: issuing a new one deletes the older rows,
 *    a used code is deleted.
 */
class ClubPortalLoginCodeService
{
    /** Issue a code for a user whose password was just verified. */
    public function start(User $user, ?string $ip, ?string $userAgent): ClubPortalLoginCode
    {
        $this->assertMailAllowed($user);

        $code = $this->generateCode();

        $record = DB::transaction(function () use ($user, $code, $ip, $userAgent) {
            ClubPortalLoginCode::where('user_id', $user->id)->delete();

            return ClubPortalLoginCode::create([
                'challenge_id' => (string) Str::uuid(),
                'user_id'      => $user->id,
                'code_hash'    => Hash::make($code),
                'sent_at'      => now(),
                'expires_at'   => now()->addMinutes($this->expiryMinutes()),
                'attempts'     => 0,
                'sends'        => 1,
                'ip_address'   => $ip,
                'user_agent'   => $userAgent ? mb_substr($userAgent, 0, 255) : null,
            ]);
        });

        try {
            $user->notify(new ClubPortalLoginCodeNotification($code, $this->expiryMinutes()));
        } catch (\Throwable $e) {
            $record->delete();
            AppLog::error('club_portal.code_send_failed', ['user_id' => $user->id, 'exception' => class_basename($e)]);

            throw new ClubPortalCodeException(ClubPortalCodeException::SEND_FAILED);
        }

        $this->recordMail($user);
        AppLog::event('club_portal.code_sent', ['user_id' => $user->id]);

        return $record;
    }

    /**
     * Check a submitted code for the pending challenge of this user. On
     * success the row is deleted and the User returned.
     */
    public function verify(string $challengeId, int $userId, string $code): User
    {
        $record = $this->find($challengeId, $userId);

        if ($record->isLocked($this->maxAttempts())) {
            $this->fail($record, ClubPortalCodeException::LOCKED);
        }
        if ($record->isExpired()) {
            $this->fail($record, ClubPortalCodeException::EXPIRED);
        }

        // Reserve one attempt atomically BEFORE comparing: N parallel guesses
        // can only ever consume the remaining budget (a check-then-increment
        // let every request that read the row before the first increment
        // compare a code). 0 rows = budget spent (or the row is gone).
        $reserved = ClubPortalLoginCode::whereKey($record->id)
            ->where('attempts', '<', $this->maxAttempts())
            ->update(['attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if ($reserved === 0) {
            $record = ClubPortalLoginCode::find($record->id);
            $record === null
                ? $this->fail(null, ClubPortalCodeException::SESSION, $userId)
                : $this->fail($record, ClubPortalCodeException::LOCKED);
        }

        if (!preg_match('/^\d{6}$/', $code) || !Hash::check($code, $record->code_hash)) {
            $record = ClubPortalLoginCode::find($record->id);
            if ($record === null) {
                // A parallel request with the right code already used it.
                $this->fail(null, ClubPortalCodeException::SESSION, $userId);
            }

            $this->fail($record, $record->isLocked($this->maxAttempts())
                ? ClubPortalCodeException::LOCKED
                : ClubPortalCodeException::WRONG);
        }

        // Single use: a concurrent second submit of the same code finds no row.
        $deleted = ClubPortalLoginCode::whereKey($record->id)->delete();
        if ($deleted === 0) {
            $this->fail(null, ClubPortalCodeException::SESSION);
        }

        $user = User::find($userId);
        if ($user === null) {
            $this->fail(null, ClubPortalCodeException::SESSION);
        }

        AppLog::event('club_portal.code_verified', ['user_id' => $userId]);

        return $user;
    }

    /** New code for the same pending challenge (cooldown + hourly mail ceiling). */
    public function resend(string $challengeId, int $userId, ?string $ip, ?string $userAgent): void
    {
        $record = $this->find($challengeId, $userId);

        if ($record->isLocked($this->maxAttempts())) {
            $this->fail($record, ClubPortalCodeException::LOCKED);
        }

        $elapsed = (int) $record->sent_at->diffInSeconds(now(), true);
        if ($elapsed < $this->cooldownSeconds()) {
            throw new ClubPortalCodeException(ClubPortalCodeException::COOLDOWN, $this->cooldownSeconds() - $elapsed);
        }

        $user = $record->user;
        if ($user === null) {
            $this->fail(null, ClubPortalCodeException::SESSION);
        }
        $this->assertMailAllowed($user);

        $code     = $this->generateCode();
        $previous = $record->only(['code_hash', 'sent_at', 'expires_at', 'attempts', 'sends', 'ip_address', 'user_agent']);

        // A resend replaces the code; wrong attempts carry over, so resending
        // never buys more guesses than code_max_attempts per password sign-in.
        $record->forceFill([
            'code_hash'  => Hash::make($code),
            'sent_at'    => now(),
            'expires_at' => now()->addMinutes($this->expiryMinutes()),
            'sends'      => $record->sends + 1,
            'ip_address' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
        ])->save();

        try {
            $user->notify(new ClubPortalLoginCodeNotification($code, $this->expiryMinutes()));
        } catch (\Throwable $e) {
            $record->forceFill($previous)->save();
            AppLog::error('club_portal.code_send_failed', ['user_id' => $user->id, 'exception' => class_basename($e), 'resend' => true]);

            throw new ClubPortalCodeException(ClubPortalCodeException::SEND_FAILED);
        }

        $this->recordMail($user);
        AppLog::event('club_portal.code_resent', ['user_id' => $user->id]);
    }

    /** Drop the pending code of a challenge (sign-in abandoned / restarted). */
    public function discard(string $challengeId): void
    {
        ClubPortalLoginCode::where('challenge_id', $challengeId)->delete();
    }

    /** Seconds until resend is allowed for the pending challenge (0 = now), null when unknown. */
    public function resendAvailableIn(string $challengeId, int $userId): ?int
    {
        $record = ClubPortalLoginCode::where('challenge_id', $challengeId)->where('user_id', $userId)->first();
        if ($record === null) {
            return null;
        }

        return max(0, $this->cooldownSeconds() - (int) $record->sent_at->diffInSeconds(now(), true));
    }

    public function expiryMinutes(): int
    {
        return max(1, (int) config('ballspot.clubs.portal.code_expiry_minutes', 10));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('ballspot.clubs.portal.code_max_attempts', 5));
    }

    public function cooldownSeconds(): int
    {
        return max(0, (int) config('ballspot.clubs.portal.code_resend_cooldown_seconds', 60));
    }

    // ------------------------------------------------------------------

    private function find(string $challengeId, int $userId): ClubPortalLoginCode
    {
        $record = $challengeId === '' ? null : ClubPortalLoginCode::where('challenge_id', $challengeId)->where('user_id', $userId)->first();
        if ($record === null) {
            $this->fail(null, ClubPortalCodeException::SESSION, $userId);
        }

        return $record;
    }

    private function fail(?ClubPortalLoginCode $record, string $reason, ?int $userId = null): never
    {
        AppLog::warn('club_portal.code_failed', [
            'user_id'  => $record?->user_id ?? $userId,
            'reason'   => $reason,
            'attempts' => $record?->attempts,
        ]);

        throw new ClubPortalCodeException($reason);
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function mailKey(User $user): string
    {
        return 'club-portal-code-mail|' . sha1(mb_strtolower(trim((string) $user->email)));
    }

    private function assertMailAllowed(User $user): void
    {
        $max = max(1, (int) config('ballspot.clubs.portal.code_emails_per_hour', 6));
        $key = $this->mailKey($user);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));
            AppLog::warn('club_portal.code_mail_limited', ['user_id' => $user->id, 'retry_after' => $retryAfter]);

            throw new ClubPortalCodeException(ClubPortalCodeException::MAIL_LIMIT, $retryAfter);
        }
    }

    private function recordMail(User $user): void
    {
        RateLimiter::hit($this->mailKey($user), 3600);
    }
}
