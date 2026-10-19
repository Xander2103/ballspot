<?php

/*
|--------------------------------------------------------------------------
| BallPicker Clubs — member API (/api/…)
|--------------------------------------------------------------------------
| Required from routes/api.php INSIDE the `auth:sanctum, active, verified`
| group and wrapped in `feature:clubs` (404 feature_disabled while
| ballspot.clubs.enabled is off). Route names: api.clubs.*.
|
| Conventions (docs/clubs-implementation-contracts.md §3.5, §5):
|  - {club} binds by public_id: `{club:public_id}`. Every member route calls
|    App\Services\Clubs\ClubAccess::requireMember($club, $user) first, so a
|    non-member always gets 404 `club_not_found` — never 403.
|  - Nested resources resolve WITHIN the club: {pack} by slug inside
|    $club->packs(), {competition} by public_id inside $club->competitions()
|    (scopeBindings), so another club's pack/competition is a 404.
|  - Writes that play or join use the named limiters: throttle:club-join for
|    POST /clubs/join, throttle:gameplay for starts/guesses.
|  - There is deliberately NO route that lists or searches clubs the caller
|    has not joined.
*/

use Illuminate\Support\Facades\Route;

Route::name('api.clubs.')->group(function () {
    // ---------------------------------------------------------------------
    // Member section (B1 — Member API): My Clubs, join, detail, leave,
    // members, packs + normal play, club leaderboard.
    //   GET  /clubs · POST /clubs/join · GET /clubs/{club} · POST /clubs/{club}/leave
    //   GET  /clubs/{club}/members · GET /clubs/{club}/packs · GET /clubs/{club}/packs/{slug}
    //   POST /clubs/{club}/packs/{slug}/start · GET /clubs/{club}/packs/{slug}/attempt
    //   GET  /clubs/{club}/leaderboard
    // ---------------------------------------------------------------------

    // ---------------------------------------------------------------------
    // Competitions section (C1 — Competitions):
    //   GET  /clubs/{club}/competitions · GET /clubs/{club}/competitions/{competition}
    //   POST /clubs/{club}/competitions/{competition}/packs/{slug}/attempts
    //   GET  /club-competition-attempts/{attempt} · POST /club-competition-attempts/{attempt}/guess
    //   GET  /clubs/{club}/competitions/{competition}/leaderboard
    //   GET  /clubs/{club}/competitions/{competition}/me/attempts · GET /me/club-finishes
    // ---------------------------------------------------------------------
});
