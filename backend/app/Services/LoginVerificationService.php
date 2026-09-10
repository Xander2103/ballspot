<?php

namespace App\Services;

use App\Models\LoginVerificationCode;
use App\Models\User;
use App\Notifications\LoginVerificationCodeNotification;
use App\Support\AppLog;
use App\Support\AuthError;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Optional email two-factor login: issue, verify, and resend one-time login
 * codes. Only used when the user opted in (users.two_factor_enabled) or the
 * global force_login_2fa flag is on — see AuthController::login.
 *
 * Security posture:
 *  - Only a HASH of the 6-digit code is stored; the plain code lives only in
 *    the outgoing email.
 *  - Codes expire (config) and are attempt-limited (config); a code that hits
 *    the attempt cap is locked and cannot be used even with the right value.
 *  - Issuing a new code purges the user's older pending codes.
 *  - Failures name their category (wrong / expired / locked / session) — the
 *    verification_id is an unguessable UUID that only the client who just
 *    passed the password check holds, so this leaks nothing to outsiders.
 *  - Logged as login.2fa_failed {reason} / login.2fa_completed — never the code.
 */
class LoginVerificationService
{
    /** Kept for callers/tests that reference the historical generic text. */
    public const GENERIC_FAILURE = 'Invalid or expired verification code.';

    /**
     * Issue a fresh login code for a user whose password was just verified.
     * Invalidates any prior pending codes so only one login is ever in flight.
     */
    public function start(User $user, ?string $ip, ?string $userAgent): LoginVerificationCode
    {
        LoginVerificationCode::where('user_id', $user->id)->delete();

        $code          = $this->generateCode();
        $expiryMinutes = $this->expiryMinutes();

        $record = LoginVerificationCode::create([
            'verification_id' => (string) Str::uuid(),
            'user_id'         => $user->id,
            'email'           => $user->email,
            'code_hash'       => Hash::make($code),
            'code_sent_at'    => now(),
            'expires_at'      => now()->addMinutes($expiryMinutes),
            'attempts'        => 0,
            'ip_address'      => $ip,
            'user_agent'      => $userAgent ? substr($userAgent, 0, 255) : null,
        ]);

        $user->notify(new LoginVerificationCodeNotification($code, $expiryMinutes));

        return $record;
    }

    /**
     * Verify a submitted code. Returns the User on success, throws a friendly
     * 422 (AuthError shape, `code` + `reason`) on any failure — incrementing
     * attempts on a wrong code.
     */
    public function verify(string $verificationId, string $code): User
    {
        $max    = $this->maxAttempts();
        $record = LoginVerificationCode::where('verification_id', $verificationId)->first();

        if (!$record || $record->isConsumed()) {
            $this->fail(null, 'session_invalid', AuthError::TWO_FACTOR_SESSION_INVALID);
        }
        if ($record->isLocked($max)) {
            $this->fail($record, 'locked', AuthError::TWO_FACTOR_LOCKED);
        }
        if ($record->isExpired()) {
            $this->fail($record, 'expired', AuthError::TWO_FACTOR_CODE_EXPIRED);
        }

        if (!Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');
            $this->fail($record, 'wrong_code', AuthError::TWO_FACTOR_CODE_INVALID);
        }

        // Success — consume this code and revoke any other pending codes.
        $record->forceFill(['consumed_at' => now()])->save();
        LoginVerificationCode::where('user_id', $record->user_id)
            ->where('id', '!=', $record->id)
            ->whereNull('consumed_at')
            ->delete();

        AppLog::event('login.2fa_completed', ['user_id' => $record->user_id]);

        return $record->user;
    }

    /**
     * Re-issue a code for an in-flight verification (same verification_id),
     * subject to a cooldown. Expired/consumed/unknown sessions must log in again.
     */
    public function resend(string $verificationId, ?string $ip, ?string $userAgent): void
    {
        $record = LoginVerificationCode::where('verification_id', $verificationId)->first();

        if (!$record || $record->isConsumed() || $record->isExpired()) {
            throw ValidationException::withMessages([
                'verification_id' => [__('messages.auth.login_again')],
            ]);
        }

        $cooldown = $this->resendCooldownSeconds();
        if ($record->code_sent_at->diffInSeconds(now()) < $cooldown) {
            throw ValidationException::withMessages([
                'verification_id' => [__('messages.auth.resend_cooldown')],
            ]);
        }

        $code          = $this->generateCode();
        $expiryMinutes = $this->expiryMinutes();

        // Reset the code + attempts on the SAME verification session.
        $record->forceFill([
            'code_hash'    => Hash::make($code),
            'code_sent_at' => now(),
            'expires_at'   => now()->addMinutes($expiryMinutes),
            'attempts'     => 0,
            'ip_address'   => $ip,
            'user_agent'   => $userAgent ? substr($userAgent, 0, 255) : null,
        ])->save();

        $record->user->notify(new LoginVerificationCodeNotification($code, $expiryMinutes));
    }

    /** Opportunistic/scheduled cleanup of expired or consumed codes. */
    public function cleanupStale(): int
    {
        return LoginVerificationCode::query()
            ->where('expires_at', '<', now())
            ->orWhereNotNull('consumed_at')
            ->delete();
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function fail(?LoginVerificationCode $record, string $reason, string $errorCode): never
    {
        AppLog::warn('login.2fa_failed', [
            'user_id'  => $record?->user_id,
            'reason'   => $reason,
            'attempts' => $record ? (int) $record->fresh()->attempts : null,
        ]);

        // Field-bound to `code` so existing clients that read errors.code[0]
        // still get the friendly sentence.
        AuthError::throw($errorCode, 422, 'code', ['reason' => $reason]);
    }

    private function expiryMinutes(): int
    {
        return (int) config('ballspot.auth.login_code_expiry_minutes', 10);
    }

    private function maxAttempts(): int
    {
        return (int) config('ballspot.auth.login_code_max_attempts', 5);
    }

    private function resendCooldownSeconds(): int
    {
        return (int) config('ballspot.auth.login_code_resend_cooldown_seconds', 60);
    }
}
