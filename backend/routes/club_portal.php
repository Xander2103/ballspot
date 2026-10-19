<?php

/*
|--------------------------------------------------------------------------
| BallPicker Clubs — club self-service portal (/club/…)
|--------------------------------------------------------------------------
| Required from routes/web.php inside Route::prefix('club') with the web
| middleware group and `feature:clubs` (plain 404 while the feature is off).
| Route names: club-portal.*.
|
| D2 — Club portal owns this file: login (email + password) with its own
| `club-portal-login` throttle, the mandatory emailed 6-digit code per new
| portal session (session key club_portal.verified_at), the `club.portal`
| middleware (auth + verified 2FA + Gate `portal.access` on the selected
| club) and the club switcher.
*/

use Illuminate\Support\Facades\Route;

Route::name('club-portal.')->group(function () {
    // ---------------------------------------------------------------------
    // Portal auth (unguarded): login, 2FA code, logout.
    // ---------------------------------------------------------------------

    // ---------------------------------------------------------------------
    // Portal (club.portal middleware): club switcher, codes, members,
    // photo submissions, packs, competitions, standings, CSV export.
    // ---------------------------------------------------------------------
});
