<?php

namespace App\Services;

use App\Models\User;
use App\Support\AppLog;
use Carbon\Carbon;
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
 * reset() never throws: it returns an outcome category the controllers turn
 * into a friendly page/JSON. Everything that must happen together — the new
 * password, revoking API tokens and web sessions, consuming the reset token —
 * runs in ONE database transaction, so a failure half-way leaves the old
 * password and a still-usable link (the user simply retries) instead of a
 * changed password behind a 500.
 *
 * Logging is by category only: whether an account matched, whether the mail
 * left, whether the reset succeeded — never the email, the token or the
 * password (AppLog::sanitize drops those keys as a second line of defence).
 */
class PasswordResetFlow
{
    public const INVALID_LINK_MESSAGE = 'This password reset link is invalid or has expired.';

    public const COMPLETED       = 'completed';
    public const INVALID_TOKEN   = 'invalid_token';
    public const EXPIRED_TOKEN   = 'expired_token';
    public const UNKNOWN_ACCOUNT = 'unknown_account';
    public const FAILED          = 'failed';

    /**
     * Send a reset link if an account exists. Silent on unknown addresses.
     * Returns the outcome category (sent | no_account | throttled | send_failed).
     */
    public function request(string $email, string $channel): string
    {
        $user = User::where('email', $email)->whereNull('anonymized_at')->first();

        if (!$user) {
            AppLog::event('password_reset.requested', ['channel' => $channel, 'outcome' => 'no_account']);

            return 'no_account';
        }

        try {
            $status = Password::sendResetLink(['email' => $email]);
        } catch (\Throwable $e) {
            AppLog::error('password_reset.requested', [
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

        AppLog::{$outcome === 'sent' ? 'event' : 'warn'}('password_reset.requested', [
            'channel' => $channel,
            'outcome' => $outcome,
            'user_id' => $user->id,
        ]);

        return $outcome;
    }

    /**
     * Validate the token, set the new password and revoke every existing
     * session/API token. Returns one of the outcome constants; never throws.
     *
     * @param array{email:string,password:string,password_confirmation:string,token:string} $credentials
     */
    public function reset(array $credentials, string $channel): string
    {
        $userId = null;

        try {
            $status = DB::transaction(function () use ($credentials, &$userId) {
                return Password::reset(
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
                    }
                );
            });
        } catch (\Throwable $e) {
            AppLog::error('password_reset.failed', [
                'channel'   => $channel,
                'reason'    => 'exception',
                'user_id'   => $userId,
                'exception' => class_basename($e),
            ]);
            report($e);

            return self::FAILED;
        }

        if ($status === Password::PASSWORD_RESET) {
            // Post-commit, best effort: nothing here may undo a completed reset.
            try {
                $user = User::find($userId);
                if ($user) {
                    event(new PasswordReset($user));
                }
            } catch (\Throwable $e) {
                report($e);
            }

            AppLog::event('password_reset.completed', ['channel' => $channel, 'user_id' => $userId]);

            return self::COMPLETED;
        }

        $reason = match ($status) {
            Password::INVALID_USER => self::UNKNOWN_ACCOUNT,
            default                => $this->classifyInvalidToken((string) ($credentials['email'] ?? ''), (string) ($credentials['token'] ?? '')),
        };

        AppLog::warn('password_reset.failed', ['channel' => $channel, 'reason' => $reason]);

        return $reason;
    }

    /** True when the outcome means "this link cannot be used": expired, invalid or unknown. */
    public static function isLinkProblem(string $outcome): bool
    {
        return in_array($outcome, [self::INVALID_TOKEN, self::EXPIRED_TOKEN, self::UNKNOWN_ACCOUNT], true);
    }

    /**
     * Laravel's broker reports INVALID_TOKEN for both "wrong" and "too old".
     * Tell them apart ONLY when the submitted token actually matches the stored
     * hash — so the holder of a real (but stale) link gets "expired" while a
     * guessed token learns nothing beyond "invalid".
     */
    private function classifyInvalidToken(string $email, string $token): string
    {
        try {
            $config = (array) config('auth.passwords.' . config('auth.defaults.passwords', 'users'));
            $table  = (string) ($config['table'] ?? 'password_reset_tokens');
            $expire = (int) ($config['expire'] ?? 60);

            $row = DB::table($table)->where('email', $email)->first();
            if ($row && $token !== '' && Hash::check($token, (string) $row->token)) {
                $createdAt = $row->created_at ? Carbon::parse($row->created_at) : null;
                if ($createdAt && $createdAt->addMinutes($expire)->isPast()) {
                    return self::EXPIRED_TOKEN;
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return self::INVALID_TOKEN;
    }
}
