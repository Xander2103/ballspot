<?php

namespace App\Http\Controllers\ClubPortal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clubs\Portal\PortalLoginRequest;
use App\Http\Requests\Clubs\Portal\PortalVerifyCodeRequest;
use App\Models\User;
use App\Services\ClubPortal\ClubPortalAccess;
use App\Services\ClubPortal\ClubPortalCodeException;
use App\Services\ClubPortal\ClubPortalLoginCodeService;
use App\Services\ClubPortal\ClubPortalSession;
use App\Support\AppLog;
use App\Support\FailedLoginLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Club portal sign-in (contracts §6): email + password on the `web` guard,
 * then the mandatory emailed 6-digit code before the session is signed in.
 *
 *  - Throttled by the `club-portal-login` limiter (per IP and per email+IP)
 *    plus the IP-independent FailedLoginLimiter (failures only, scope
 *    `club-portal`).
 *  - Only an active owner/admin of an active or suspended club passes. Any
 *    other account — wrong password, unknown address, unverified email,
 *    plain members, platform admins without such a membership — gets the
 *    same generic refusal, so the form never confirms who runs a club.
 *  - The password step does NOT sign the user in: it stores a pending
 *    challenge in a regenerated session. Only an accepted code calls
 *    Auth::login() (session regenerated again) and marks the portal session
 *    verified. No "remember me".
 *  - Logged as categories only (club_portal.login / login_failed {reason}),
 *    never the email, password or code.
 */
class ClubPortalAuthController extends Controller
{
    public const LIMITER_SCOPE = 'club-portal';

    public function __construct(
        private ClubPortalLoginCodeService $codes,
        private ClubPortalAccess $access,
    ) {}

    public function showLogin(Request $request)
    {
        if (ClubPortalSession::isVerified($request, $request->user())) {
            return redirect()->route('club-portal.home');
        }

        return view('club-portal.auth.login');
    }

    public function login(PortalLoginRequest $request): RedirectResponse
    {
        $email = (string) $request->input('email');
        FailedLoginLimiter::assertNotLocked(self::LIMITER_SCOPE, $email);

        $user  = User::findByEmail($email);
        $valid = $user !== null
            && $user->anonymized_at === null
            && Auth::guard('web')->validate(['email' => $user->email, 'password' => (string) $request->input('password')]);

        $reason = match (true) {
            $user === null                    => 'unknown_account',
            !$valid                           => 'wrong_password',
            !$user->hasVerifiedEmail()        => 'unverified',
            !$this->access->isEligible($user) => 'not_club_admin',
            default                           => null,
        };

        if ($reason !== null) {
            FailedLoginLimiter::recordFailure(self::LIMITER_SCOPE, $email);
            AppLog::warn('club_portal.login_failed', ['reason' => $reason, 'user_id' => $user?->id]);

            return back()->withErrors(['email' => __('club_portal.auth.failed')])->onlyInput('email');
        }

        FailedLoginLimiter::clear(self::LIMITER_SCOPE, $email);

        // Session fixation: a fresh id before anything is tied to this sign-in.
        $intended = $request->session()->get('url.intended');
        $this->dropPending($request);
        $request->session()->regenerate();
        if ($intended) {
            $request->session()->put('url.intended', $intended);
        }

        if (!ClubPortalSession::twoFactorRequired()) {
            return $this->complete($request, $user);
        }

        try {
            $record = $this->codes->start($user, $request->ip(), $request->userAgent());
        } catch (ClubPortalCodeException $e) {
            return back()->withErrors(['email' => $this->codeMessage($e)])->onlyInput('email');
        }

        ClubPortalSession::putPending($request, $user, $record->challenge_id);
        AppLog::event('club_portal.login_password_ok', ['user_id' => $user->id]);

        return redirect()->route('club-portal.verify');
    }

    public function showVerify(Request $request)
    {
        $pending = ClubPortalSession::pending($request);
        if ($pending === null) {
            return redirect()->route('club-portal.login');
        }

        $user = User::find($pending['user_id']);
        if ($user === null) {
            ClubPortalSession::forgetPending($request);

            return redirect()->route('club-portal.login');
        }

        return view('club-portal.auth.verify', [
            'maskedEmail'   => self::maskEmail((string) $user->email),
            'expiryMinutes' => $this->codes->expiryMinutes(),
            'resendIn'      => (int) ($this->codes->resendAvailableIn($pending['challenge_id'], $pending['user_id']) ?? 0),
        ]);
    }

    public function verify(PortalVerifyCodeRequest $request): RedirectResponse
    {
        $pending = ClubPortalSession::pending($request);
        if ($pending === null) {
            return redirect()->route('club-portal.login')->with('error', __('club_portal.verify.errors.session'));
        }

        try {
            $user = $this->codes->verify($pending['challenge_id'], (int) $pending['user_id'], (string) $request->input('code'));
        } catch (ClubPortalCodeException $e) {
            if ($e->requiresNewLogin()) {
                $this->dropPending($request);

                return redirect()->route('club-portal.login')->with('error', $this->codeMessage($e));
            }

            return back()->withErrors(['code' => $this->codeMessage($e)]);
        }

        // Re-check: the membership may have ended while the code was in the inbox.
        if ($user->anonymized_at !== null || !$this->access->isEligible($user)) {
            ClubPortalSession::forgetPending($request);
            AppLog::warn('club_portal.login_failed', ['reason' => 'not_club_admin', 'user_id' => $user->id]);

            return redirect()->route('club-portal.login')->withErrors(['email' => __('club_portal.auth.failed')]);
        }

        ClubPortalSession::forgetPending($request);

        return $this->complete($request, $user);
    }

    public function resend(Request $request): RedirectResponse
    {
        $pending = ClubPortalSession::pending($request);
        if ($pending === null) {
            return redirect()->route('club-portal.login')->with('error', __('club_portal.verify.errors.session'));
        }

        try {
            $this->codes->resend($pending['challenge_id'], (int) $pending['user_id'], $request->ip(), $request->userAgent());
        } catch (ClubPortalCodeException $e) {
            if ($e->requiresNewLogin()) {
                $this->dropPending($request);

                return redirect()->route('club-portal.login')->with('error', $this->codeMessage($e));
            }

            return redirect()->route('club-portal.verify')->with('error', $this->codeMessage($e));
        }

        return redirect()->route('club-portal.verify')->with('success', __('club_portal.verify.resent'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $id = $request->user()?->id;
        $this->dropPending($request);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($id !== null) {
            AppLog::event('club_portal.logout', ['user_id' => $id]);
        }

        return redirect()->route('club-portal.login')->with('success', __('club_portal.auth.logged_out'));
    }

    // ------------------------------------------------------------------

    private function complete(Request $request, User $user): RedirectResponse
    {
        $intended = $request->session()->pull('url.intended');

        Auth::guard('web')->login($user); // migrates the session id again
        $request->session()->regenerate();
        ClubPortalSession::markVerified($request, $user);
        AppLog::event('club_portal.login', ['user_id' => $user->id]);

        $home = route('club-portal.home');
        $target = is_string($intended) && str_starts_with($intended, url('/club/')) ? $intended : $home;

        return redirect()->to($target);
    }

    private function dropPending(Request $request): void
    {
        $pending = ClubPortalSession::pending($request);
        if ($pending !== null) {
            $this->codes->discard((string) $pending['challenge_id']);
        }
        ClubPortalSession::forget($request);
    }

    private function codeMessage(ClubPortalCodeException $e): string
    {
        $minutes = max(1, (int) ceil($e->retryAfter / 60));

        return __('club_portal.verify.errors.' . $e->reason, [
            'seconds' => max(1, $e->retryAfter),
            'minutes' => $minutes,
            'max'     => $this->codes->maxAttempts(),
        ]);
    }

    /** a•••@example.com — enough to recognise the inbox, not to harvest it. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return '•••';
        }

        return mb_substr($local, 0, 1) . '•••@' . $domain;
    }
}
