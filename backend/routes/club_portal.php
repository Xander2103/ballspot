<?php

/*
|--------------------------------------------------------------------------
| BallPicker Clubs — club self-service portal (/club/…)
|--------------------------------------------------------------------------
| Required from routes/web.php inside Route::prefix('club') with the web
| middleware group and `feature:clubs` (plain 404 while the feature is off).
| Route names: club-portal.*. See docs/club-portal.md.
|
| Auth: /club/login (email + password, throttle `club-portal-login` +
| FailedLoginLimiter) → /club/verify (emailed 6-digit code, throttle
| `club-portal-code`) → signed-in portal session (club_portal.verified_at).
|
| Club pages: /club/{club:slug}/… behind `club.portal` (auth + verified
| portal session + active owner/admin membership; Gate `portal.access` on
| active clubs; suspended = read-only; archived/outsiders 404). Nested
| bindings are scoped to the club. An unknown slug answers like an
| existing one for guests (redirect to login), so slugs cannot be probed.
*/

use App\Http\Controllers\ClubPortal\AuditController;
use App\Http\Controllers\ClubPortal\ClubPortalAuthController;
use App\Http\Controllers\ClubPortal\CompetitionController;
use App\Http\Controllers\ClubPortal\DashboardController;
use App\Http\Controllers\ClubPortal\HomeController;
use App\Http\Controllers\ClubPortal\InviteCodeController;
use App\Http\Controllers\ClubPortal\LeaderboardController;
use App\Http\Controllers\ClubPortal\MemberController;
use App\Http\Controllers\ClubPortal\PhotoController;
use App\Http\Controllers\ClubPortal\SettingsController;
use App\Services\ClubPortal\ClubPortalSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::name('club-portal.')->group(function () {
    // ---------------------------------------------------------------------
    // Portal auth (unguarded): login, emailed code, logout.
    // ---------------------------------------------------------------------
    Route::get('login', [ClubPortalAuthController::class, 'showLogin'])->name('login');
    Route::post('login', [ClubPortalAuthController::class, 'login'])->name('login.submit')->middleware('throttle:club-portal-login');
    Route::get('verify', [ClubPortalAuthController::class, 'showVerify'])->name('verify');
    Route::post('verify', [ClubPortalAuthController::class, 'verify'])->name('verify.submit')->middleware('throttle:club-portal-code');
    Route::post('verify/resend', [ClubPortalAuthController::class, 'resend'])->name('verify.resend')->middleware('throttle:club-portal-code');
    Route::post('logout', [ClubPortalAuthController::class, 'logout'])->name('logout');

    // ---------------------------------------------------------------------
    // Portal (club.portal middleware): club switcher, dashboard, members,
    // invite codes, photo submissions, settings, audit, competitions.
    // ---------------------------------------------------------------------
    Route::middleware('club.portal')->group(function () {
        Route::get('/', HomeController::class)->name('home');

        // Unknown club / out-of-club child: the same answer as a club the
        // caller may not see — guests and unverified sessions go to the
        // login, signed-in portal users get a 404.
        $missing = function (Request $request) {
            if ($request->user() === null || !ClubPortalSession::isVerified($request, $request->user())) {
                return redirect()->guest(route('club-portal.login'));
            }
            abort(404);
        };

        Route::prefix('{club:slug}')
            ->where(['club' => '(?!(?:login|verify|logout)$)[a-z0-9][a-z0-9\-]*'])
            ->missing($missing)
            ->scopeBindings()
            ->group(function () {
                Route::get('/', DashboardController::class)->name('dashboard');

                Route::get('members', [MemberController::class, 'index'])->name('members.index');
                Route::post('members/{member}/remove', [MemberController::class, 'remove'])->whereNumber('member')->name('members.remove');
                Route::post('members/{member}/ban', [MemberController::class, 'ban'])->whereNumber('member')->name('members.ban');
                Route::post('members/{member}/unban', [MemberController::class, 'unban'])->whereNumber('member')->name('members.unban');
                Route::patch('members/{member}/role', [MemberController::class, 'role'])->whereNumber('member')->name('members.role');
                Route::post('transfer-ownership', [MemberController::class, 'transfer'])->name('members.transfer');

                Route::get('codes', [InviteCodeController::class, 'index'])->name('codes.index');
                Route::post('codes', [InviteCodeController::class, 'store'])->name('codes.store');
                Route::post('codes/{inviteCode}/revoke', [InviteCodeController::class, 'revoke'])->whereNumber('inviteCode')->name('codes.revoke');
                Route::post('codes/{inviteCode}/regenerate', [InviteCodeController::class, 'regenerate'])->whereNumber('inviteCode')->name('codes.regenerate');
                Route::get('codes/{inviteCode}/print', [InviteCodeController::class, 'print'])->whereNumber('inviteCode')->name('codes.print');

                Route::get('photos', [PhotoController::class, 'index'])->name('photos.index');
                Route::post('photos', [PhotoController::class, 'store'])->name('photos.store')->middleware('throttle:uploads');
                Route::post('photos/{photoSubmission}/withdraw', [PhotoController::class, 'withdraw'])->whereNumber('photoSubmission')->name('photos.withdraw');

                Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
                Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

                Route::get('audit', AuditController::class)->name('audit.index');

                // Leaderboard + CSV downloads (Wave F; usernames only, audited).
                Route::get('leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard.index');
                Route::get('leaderboard.csv', [LeaderboardController::class, 'csv'])->name('leaderboard.csv');

                // Competitions: no void / rebuild / finalize / attempt void here (platform-only).
                Route::get('competitions', [CompetitionController::class, 'index'])->name('competitions.index');
                Route::get('competitions/create', [CompetitionController::class, 'create'])->name('competitions.create');
                Route::post('competitions', [CompetitionController::class, 'store'])->name('competitions.store');
                Route::prefix('competitions/{competition:public_id}')->where(['competition' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}'])->group(function () {
                    Route::get('/', [CompetitionController::class, 'show'])->name('competitions.show');
                    Route::put('/', [CompetitionController::class, 'update'])->name('competitions.update');
                    Route::post('publish', [CompetitionController::class, 'publish'])->name('competitions.publish');
                    Route::post('cancel', [CompetitionController::class, 'cancel'])->name('competitions.cancel');
                    Route::post('extend', [CompetitionController::class, 'extend'])->name('competitions.extend');
                    Route::get('standings.csv', [LeaderboardController::class, 'competitionCsv'])->name('competitions.standings-csv');
                });
            });
    });
});
