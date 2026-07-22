<?php

namespace App\Services;

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Email verification at registration via a one-time 6-digit code.
 *
 * Mirrors the login-code security posture: only a hash is stored, codes expire
 * and are attempt-limited, resend is cooldown-limited, and failures are
 * generic. The authenticated (but unverified) user is the subject, so codes are
 * looked up by user_id rather than a public handle.
 */
class EmailVerificationService
{
    public const GENERIC_FAILURE = 'Invalid or expired verification code.';

    /**
     * Issue a verification code for a user. No-ops (returns false) if the email
     * is already verified. Respects the resend cooldown unless $force is set, so
     * repeated logins/registers cannot be used to spam email.
     */
    public function send(User $user, ?string $ip = null, ?string $userAgent = null, bool $force = false): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        $recent = EmailVerificationCode::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->latest('code_sent_at')
            ->first();

        if (!$force && $recent && $recent->code_sent_at->diffInSeconds(now()) < $this->cooldownSeconds()) {
            // A fresh code was sent very recently — do not send another.
            return false;
        }

        EmailVerificationCode::where('user_id', $user->id)->delete();

        $code          = $this->generateCode();
        $expiryMinutes = $this->expiryMinutes();

        EmailVerificationCode::create([
            'user_id'      => $user->id,
            'code_hash'    => Hash::make($code),
            'code_sent_at' => now(),
            'expires_at'   => now()->addMinutes($expiryMinutes),
            'attempts'     => 0,
        ]);

        $user->notify(new EmailVerificationCodeNotification($code, $expiryMinutes));

        return true;
    }

    /**
     * Verify a submitted code for the given user and mark the email verified.
     * Throws a generic ValidationException on any failure.
     */
    public function verify(User $user, string $code): void
    {
        if ($user->hasVerifiedEmail()) {
            return; // Idempotent — already verified.
        }

        $max    = $this->maxAttempts();
        $record = EmailVerificationCode::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->latest('code_sent_at')
            ->first();

        if (!$record || !$record->isUsable($max)) {
            throw $this->genericFailure();
        }

        if (!Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');
            throw $this->genericFailure();
        }

        $record->forceFill(['consumed_at' => now()])->save();
        $user->markEmailAsVerified();

        // Clean up any other codes for this user.
        EmailVerificationCode::where('user_id', $user->id)
            ->where('id', '!=', $record->id)
            ->delete();
    }

    /**
     * Resend a code (cooldown-limited). Throws if still verified or on cooldown.
     */
    public function resend(User $user, ?string $ip = null, ?string $userAgent = null): void
    {
        $recent = EmailVerificationCode::where('user_id', $user->id)
            ->latest('code_sent_at')
            ->first();

        if ($recent && $recent->code_sent_at->diffInSeconds(now()) < $this->cooldownSeconds()) {
            throw ValidationException::withMessages([
                'email' => ['Please wait a moment before requesting another code.'],
            ]);
        }

        $this->send($user, $ip, $userAgent, force: true);
    }

    public function cleanupStale(): int
    {
        return EmailVerificationCode::query()
            ->where('expires_at', '<', now())
            ->orWhereNotNull('consumed_at')
            ->delete();
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function genericFailure(): ValidationException
    {
        return ValidationException::withMessages(['code' => [self::GENERIC_FAILURE]]);
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
