<?php

namespace App\Services;

use App\Models\User;
use App\Support\AppLog;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One implementation of "forgot password" + "reset password" shared by the
 * JSON API (mobile app) and the web fallback page (the link in the email).
 *
 * Logging is by category only: whether an account matched, whether the mail
 * left, whether the reset succeeded — never the email, the token or the
 * password (AppLog::sanitize drops those keys as a second line of defence).
 */
class PasswordResetFlow
{
    public const INVALID_LINK_MESSAGE = 'This password reset link is invalid or has expired.';

    /**
     * Send a reset link if an account exists. Silent on unknown addresses.
     * Returns the outcome category (sent | no_account | throttled | send_failed).
     */
    public function request(string $email, string $channel): string
    {
        $user = User::where('email', $email)->whereNull('anonymized_at')->first();

        if (!$user) {
            AppLog::event('password.reset_requested', ['channel' => $channel, 'outcome' => 'no_account']);

            return 'no_account';
        }

        try {
            $status = Password::sendResetLink(['email' => $email]);
        } catch (\Throwable $e) {
            AppLog::error('password.reset_requested', [
                'channel'   => $channel,
                'outcome'   => 'send_failed',
                'user_id'   => $user->id,
                'exception' => class_basename($e),
            ]);
            report($e);

            return 'send_failed';
        }

        $outcome = match ($status) {
            Password::RESET_LINK_SENT   => 'sent',
            Password::RESET_THROTTLED   => 'throttled',
            default                     => 'send_failed',
        };

        AppLog::{$outcome === 'sent' ? 'event' : 'warn'}('password.reset_requested', [
            'channel' => $channel,
            'outcome' => $outcome,
            'user_id' => $user->id,
        ]);

        return $outcome;
    }

    /**
     * Validate the token, set the new password and revoke every existing
     * session/API token. Returns true on success.
     *
     * @param array{email:string,password:string,password_confirmation:string,token:string} $credentials
     */
    public function reset(array $credentials, string $channel): bool
    {
        $userId = null;

        $status = Password::reset(
            $credentials,
            function (User $user, string $password) use (&$userId) {
                $userId = $user->id;

                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Revoke all existing Sanctum API tokens after a reset...
                $user->tokens()->delete();

                // ...and the database-backed web sessions behind the admin
                // panel, which Sanctum revocation does not touch.
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            AppLog::event('password.reset_completed', ['channel' => $channel, 'user_id' => $userId]);

            return true;
        }

        AppLog::warn('password.reset_failed', [
            'channel' => $channel,
            'reason'  => $status === Password::INVALID_USER ? 'unknown_account' : 'invalid_token',
        ]);

        return false;
    }
}
