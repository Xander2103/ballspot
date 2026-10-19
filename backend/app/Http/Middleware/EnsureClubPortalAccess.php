<?php

namespace App\Http\Middleware;

use App\Enums\ClubRole;
use App\Models\Club;
use App\Services\ClubPortal\ClubPortalAccess;
use App\Services\ClubPortal\ClubPortalSession;
use App\Services\Clubs\ClubMembershipService;
use App\Support\AppLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * `club.portal` — the gate in front of every signed-in club portal page:
 *
 *  1. signed in on the `web` guard (guest → /club/login);
 *  2. the portal sign-in was completed in THIS session for THIS user —
 *     password + the emailed code (ClubPortalSession; otherwise →
 *     /club/login with `portal_two_factor_required`). With the code
 *     switched off (clubs.portal.two_factor_required = false) the password
 *     sign-in on /club/login still has to happen here;
 *  3. the portal idle timeout (clubs.portal.idle_timeout_minutes): an idle
 *     session is signed out completely;
 *  4. for routes with a {club}: an active owner/admin membership. Outsiders,
 *     platform admins without a membership and archived clubs → 404 (the
 *     portal never confirms a club exists); a plain member → 403. Active
 *     clubs also pass Gate `portal.access`. A suspended club is read-only:
 *     any write is refused with `club_unavailable` before the controller.
 *
 * Shares $portalUser, $portalClubs, $portalClub and $portalRole with the views.
 */
class EnsureClubPortalAccess
{
    public function __construct(
        private ClubMembershipService $memberships,
        private ClubPortalAccess $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('club-portal.login'));
        }

        // A web session from elsewhere (e.g. /admin) is not a portal session:
        // the portal sign-in (password + emailed code) must happen here.
        if (!ClubPortalSession::isVerified($request, $user)) {
            $request->session()->put('url.intended', $request->isMethodSafe() ? $request->fullUrl() : route('club-portal.home'));

            return redirect()->route('club-portal.login')->with('error', ClubPortalSession::twoFactorRequired()
                ? __('clubs.errors.portal_two_factor_required')
                : __('club_portal.auth.sign_in_required'));
        }

        if (ClubPortalSession::isIdleExpired($request) || $user->anonymized_at !== null) {
            AppLog::event('club_portal.idle_logout', ['user_id' => $user->id]);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('club-portal.login')->with('warning', __('club_portal.auth.idle_expired'));
        }
        ClubPortalSession::touch($request);

        $clubs = $this->access->clubsFor($user);
        View::share('portalUser', $user);
        View::share('portalClubs', $clubs);

        $club = $request->route('club');
        if ($club instanceof Club) {
            if ($club->isArchived()) {
                abort(404);
            }

            $member = $this->memberships->membership($club, $user);
            if ($member === null) {
                abort(404);
            }
            if (!$member->role->canManage()) {
                abort(403);
            }
            if ($club->isActive() && !Gate::forUser($user)->allows('portal.access', $club)) {
                abort(403);
            }

            if ($club->isSuspended() && !$request->isMethodSafe()) {
                return back()->with('error', __('clubs.errors.club_unavailable'));
            }

            View::share('portalClub', $club);
            View::share('portalRole', $member->role);
            View::share('portalIsOwner', $member->role === ClubRole::Owner);
            $request->attributes->set('portal_member', $member);
        }

        $response = $next($request);

        // Portal pages carry member names and invite codes: never cache them.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
