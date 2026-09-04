<?php

namespace App\Services;

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Support\AppLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Email verification at registration via a one-time 6-digit code.
 *
 * Mirrors the login-code security posture: only a hash is stored, codes expire
 * and are attempt-limited, resend is cooldown-limited, and failures are
 * generic. The authenticated (but unverified) user is the subject, so codes are
 * looked up by user_id rather than a public handle.
 *
 * Launch hardening (v1.9.5): sending a new code no longer invalidates the
 * previous ones. Email delivery is not instant or ordered — a user who taps
 * "resend" (or logs in again) and then opens the FIRST email must still get
 * in. The last MAX_LIVE_CODES unconsumed codes stay valid; the attempt lock is
 * shared across them (tracked on the newest record), so the brute-force budget
 * does not grow with the number of live codes.
 */
class EmailVerificationService
{
    public const GENERIC_FAILURE = 'Invalid or expired verification code.';
    public const EXPIRED_FAILURE = 'This code has expired. Please request a new one.';
    public const LOCKED_FAILURE  = 'Too many incorrect attempts. Please request a new code.';

    /** How many of the most recent unconsumed codes remain valid at once. */
    public const MAX_LIVE_CODES = 3;

    /**
     * Issue a verification code for a user. No-ops (returns false) if the email
     * is already verified. Respects the resend cooldown unless $force is set, so
     * repeated logins/registers cannot be used to spam email.
     *
     * Returns true only when a code was actually handed to the mailer. A mail
     * transport failure is logged (category only) and reported as false — the
     * caller decides whether that is fatal (registration: no, the account
     * exists and the user can tap "resend").
     */
    public function send(User $user, ?string $ip = null, ?string $userAgent = null, bool $force = false): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        $recent = $this->latestLive($user);

        if (!$force && $recent && $recent->code_sent_at->diffInSeconds(now()) < $this->cooldownSeconds()) {
            // A fresh code was sent very recently — do not send another.
            return false;
        }

        // Keep the newest live codes; drop consumed/expired ones and anything
        // beyond the live window so the table never grows per user.
        EmailVerificationCode::where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNotNull('consumed_at')->orWhere('expires_at', '<', now()))
            ->delete();
        $keep = EmailVerificationCode::where('user_id', $user->id)
            ->latest('code_sent_at')->latest('id')
            ->limit(self::MAX_LIVE_CODES - 1)
            ->pluck('id');
        EmailVerificationCode::where('user_id', $user->id)->whereNotIn('id', $keep)->delete();

        $code          = $this->generateCode();
        $expiryMinutes = $this->expiryMinutes();

        $record = EmailVerificationCode::create([
            'user_id'      => $user->id,
            'code_hash'    => Hash::make($code),
            'code_sent_at' => now(),
            'expires_at'   => now()->addMinutes($expiryMinutes),
            'attempts'     => 0,
        ]);

        try {
            $user->notify(new EmailVerificationCodeNotification($code, $expiryMinutes));
        } catch (\Throwable $e) {
            // The record stays (a later resend replaces it); the user sees a
            // clear "we could not send" state instead of a dead flow.
            $record->delete();
            AppLog::error('auth.verification_send_failed', [
                'user_id'   => $user->id,
                'exception' => class_basename($e),
            ]);

            return false;
        }

        AppLog::event('auth.verification_sent', [
            'user_id' => $user->id,
            'forced'  => $force,
        ]);

        return true;
    }

    /** True when the user holds at least one code that can still be entered. */
    public function hasUsableCode(User $user): bool
    {
        $latest = $this->latestLive($user);

        return $latest !== null && $latest->isUsable($this->maxAttempts());
    }

    /**
     * Verify a submitted code for the given user and mark the email verified.
     * Throws a ValidationException with a friendly, specific message on failure.
     */
    public function verify(User $user, string $code): void
    {
        if ($user->hasVerifiedEmail()) {
            return; // Idempotent — already verified.
        }

        $max  = $this->maxAttempts();
        $live = EmailVerificationCode::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->latest('code_sent_at')->latest('id')
            ->limit(self::MAX_LIVE_CODES)
            ->get();

        $latest = $live->first();

        if (!$latest) {
            throw $this->failure($user, 'no_code', self::GENERIC_FAILURE);
        }
        if ($latest->isLocked($max)) {
            throw $this->failure($user, 'locked', self::LOCKED_FAILURE);
        }
        if ($latest->isExpired()) {
            throw $this->failure($user, 'expired', self::EXPIRED_FAILURE);
        }

        $match = $live->first(fn (EmailVerificationCode $c) => !$c->isExpired() && Hash::check($code, $c->code_hash));

        if (!$match) {
            // The lock lives on the newest record so the total budget is
            // max_attempts regardless of how many codes are live.
            $latest->increment('attempts');
            throw $this->failure($user, 'wrong_code', self::GENERIC_FAILURE);
        }

        $match->forceFill(['consumed_at' => now()])->save();
        $user->markEmailAsVerified();

        // Clean up every other code for this user.
        EmailVerificationCode::where('user_id', $user->id)
            ->where('id', '!=', $match->id)
            ->delete();

        AppLog::event('auth.verification_completed', ['user_id' => $user->id]);
    }

    /**
     * Resend a code (cooldown-limited). Throws if on cooldown or if the mail
     * could not be handed to the transport.
     */
    public function resend(User $user, ?string $ip = null, ?string $userAgent = null): void
    {
        $recent = EmailVerificationCode::where('user_id', $user->id)
            ->latest('code_sent_at')->latest('id')
            ->first();

        if ($recent && $recent->code_sent_at->diffInSeconds(now()) < $this->cooldownSeconds()) {
            throw ValidationException::withMessages([
                'email' => ['Please wait a moment before requesting another code.'],
            ]);
        }

        if (!$this->send($user, $ip, $userAgent, force: true)) {
            throw ValidationException::withMessages([
                'email' => ['We could not send the email right now. Please try again in a moment.'],
            ]);
        }
    }

    public function cleanupStale(): int
    {
        return EmailVerificationCode::query()
            ->where('expires_at', '<', now())
            ->orWhereNotNull('consumed_at')
            ->delete();
    }

    private function latestLive(User $user): ?EmailVerificationCode
    {
        return EmailVerificationCode::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->latest('code_sent_at')->latest('id')
            ->first();
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function failure(User $user, string $reason, string $message): ValidationException
    {
        AppLog::warn('auth.verification_failed', ['user_id' => $user->id, 'reason' => $reason]);

        return ValidationException::withMessages(['code' => [$message]]);
    }

    private function expiryMinutes(): int
    {
        return (int) config('ballspot.auth.email_code_expiry_minutes', 60);
    }

    private function maxAttempts(): int
    {
        return (int) config('ballspot.auth.login_code_max_attempts', 5);
    }

    private function cooldownSeconds(): int
    {
        return (int) config('ballspot.auth.login_code_resend_cooldown_seconds', 60);
    }
}
