<?php

namespace App\Services;

use App\Models\LoginVerificationCode;
use App\Models\User;
use App\Notifications\LoginVerificationCodeNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Email two-factor login: issue, verify, and resend one-time login codes.
 *
 * Security posture:
 *  - Only a HASH of the 6-digit code is stored; the plain code lives only in
 *    the outgoing email.
 *  - Codes expire (config) and are attempt-limited (config); a code that hits
 *    the attempt cap is locked and cannot be used even with the right value.
 *  - Issuing a new code purges the user's older pending codes.
 *  - Verify/resend failures return a single generic message so nothing about a
 *    code's exact state (or a user's existence) is leaked.
 */
class LoginVerificationService
{
    /** Deliberately generic — do not distinguish wrong / expired / consumed / locked. */
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
     * Verify a submitted code. Returns the User on success, throws a generic
     * ValidationException on any failure (increments attempts on wrong code).
     */
    public function verify(string $verificationId, string $code): User
    {
        $max    = $this->maxAttempts();
        $record = LoginVerificationCode::where('verification_id', $verificationId)->first();

        if (!$record || !$record->isUsable($max)) {
            throw $this->genericFailure();
        }

        if (!Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');
            throw $this->genericFailure();
        }

        // Success — consume this code and revoke any other pending codes.
        $record->forceFill(['consumed_at' => now()])->save();
        LoginVerificationCode::where('user_id', $record->user_id)
            ->where('id', '!=', $record->id)
            ->whereNull('consumed_at')
            ->delete();

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
                'verification_id' => ['Please login again.'],
            ]);
        }

        $cooldown = $this->resendCooldownSeconds();
        if ($record->code_sent_at->diffInSeconds(now()) < $cooldown) {
            throw ValidationException::withMessages([
                'verification_id' => ['Please wait a moment before requesting another code.'],
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

    private function genericFailure(): ValidationException
    {
        return ValidationException::withMessages(['code' => [self::GENERIC_FAILURE]]);
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
