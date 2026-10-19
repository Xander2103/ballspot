<?php

namespace App\Services\ClubPortal;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Session keys of the club portal (contracts §6). Everything portal-specific
 * lives under `club_portal.*` in the normal `web` session:
 *
 *  - club_portal.pending       [user_id, challenge_id, started_at] while the
 *                              emailed code is outstanding (NOT signed in yet)
 *  - club_portal.verified_at   unix time the code was accepted
 *  - club_portal.verified_user the user id that passed the code (a session
 *                              that later carries another web user — e.g.
 *                              an /admin sign-in — is not verified)
 *  - club_portal.last_seen     unix time of the last portal request (idle timeout)
 */
final class ClubPortalSession
{
    public const PENDING       = 'club_portal.pending';
    public const VERIFIED_AT   = 'club_portal.verified_at';
    public const VERIFIED_USER = 'club_portal.verified_user';
    public const LAST_SEEN     = 'club_portal.last_seen';

    public static function twoFactorRequired(): bool
    {
        return (bool) config('ballspot.clubs.portal.two_factor_required', true);
    }

    public static function idleMinutes(): int
    {
        return max(1, (int) config('ballspot.clubs.portal.idle_timeout_minutes', 60));
    }

    /** @return array{user_id:int, challenge_id:string, started_at:int}|null */
    public static function pending(Request $request): ?array
    {
        $p = $request->session()->get(self::PENDING);

        return is_array($p) && isset($p['user_id'], $p['challenge_id']) ? $p : null;
    }

    public static function putPending(Request $request, User $user, string $challengeId): void
    {
        $request->session()->put(self::PENDING, [
            'user_id'      => $user->id,
            'challenge_id' => $challengeId,
            'started_at'   => now()->getTimestamp(),
        ]);
    }

    public static function forgetPending(Request $request): void
    {
        $request->session()->forget(self::PENDING);
    }

    public static function markVerified(Request $request, User $user): void
    {
        $now = now()->getTimestamp();
        $request->session()->put(self::VERIFIED_AT, $now);
        $request->session()->put(self::VERIFIED_USER, $user->id);
        $request->session()->put(self::LAST_SEEN, $now);
    }

    public static function isVerified(Request $request, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        $session = $request->session();

        return $session->get(self::VERIFIED_AT) !== null
            && (int) $session->get(self::VERIFIED_USER) === (int) $user->id;
    }

    public static function isIdleExpired(Request $request): bool
    {
        $last = $request->session()->get(self::LAST_SEEN);

        return $last !== null && (now()->getTimestamp() - (int) $last) > self::idleMinutes() * 60;
    }

    public static function touch(Request $request): void
    {
        $request->session()->put(self::LAST_SEEN, now()->getTimestamp());
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget([self::PENDING, self::VERIFIED_AT, self::VERIFIED_USER, self::LAST_SEEN]);
    }
}
