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

use App\Exceptions\ClubDomainException;
use App\Http\Controllers\Api\Clubs\ClubCompetitionAttemptController;
use App\Http\Controllers\Api\Clubs\ClubCompetitionController;
use App\Http\Controllers\Api\Clubs\ClubController;
use App\Http\Controllers\Api\Clubs\ClubLeaderboardController;
use App\Http\Controllers\Api\Clubs\ClubPackController;
use App\Http\Controllers\Api\Clubs\MyClubsController;
use App\Http\Middleware\EnsureFeatureEnabled;
use Illuminate\Support\Facades\Route;

// An unknown club public_id or a pack slug outside the club answers exactly
// like a club the caller is not a member of (404 club_not_found), so the API
// never confirms that a club or pack exists. Route-model binding can run
// before `feature:clubs`, so the flag is checked here too.
$clubMissing = static fn () => (new ClubDomainException(
    EnsureFeatureEnabled::enabled('clubs') ? ClubDomainException::CLUB_NOT_FOUND : ClubDomainException::FEATURE_DISABLED,
    404,
))->toJsonResponse();

Route::name('api.clubs.')->missing($clubMissing)->group(function () {
    // ---------------------------------------------------------------------
    // Member section (B1 — Member API): My Clubs, join, detail, leave,
    // members, packs + normal play, club leaderboard.
    // ---------------------------------------------------------------------
    Route::get('/clubs', [MyClubsController::class, 'index'])->name('index');
    Route::post('/clubs/join', [MyClubsController::class, 'join'])->middleware('throttle:club-join')->name('join');

    Route::scopeBindings()->prefix('/clubs/{club:public_id}')->group(function () {
        Route::get('/', [ClubController::class, 'show'])->name('show');
        Route::post('/leave', [ClubController::class, 'leave'])->name('leave');
        Route::get('/members', [ClubController::class, 'members'])->name('members');
        Route::get('/leaderboard', [ClubLeaderboardController::class, 'index'])->name('leaderboard');

        Route::get('/packs', [ClubPackController::class, 'index'])->name('packs.index');
        Route::get('/packs/{pack:slug}', [ClubPackController::class, 'show'])->name('packs.show');
        Route::post('/packs/{pack:slug}/start', [ClubPackController::class, 'start'])->middleware('throttle:gameplay')->name('packs.start');
        Route::get('/packs/{pack:slug}/attempt', [ClubPackController::class, 'attempt'])->name('packs.attempt');
    });

    // ---------------------------------------------------------------------
    // Competitions section (C1 — Competitions). {competition} binds by
    // public_id within the club, {pack} by slug within the competition's
    // linked packs; attempts are addressed by id + owner check (404).
    // ---------------------------------------------------------------------
    Route::scopeBindings()->prefix('/clubs/{club:public_id}/competitions')->name('competitions.')->group(function () {
        Route::get('/', [ClubCompetitionController::class, 'index'])->name('index');
        Route::get('/{competition:public_id}', [ClubCompetitionController::class, 'show'])->name('show');
        Route::post('/{competition:public_id}/packs/{pack:slug}/attempts', [ClubCompetitionController::class, 'start'])
            ->middleware('throttle:gameplay')->name('attempts.start');
        Route::get('/{competition:public_id}/leaderboard', [ClubCompetitionController::class, 'leaderboard'])->name('leaderboard');
        Route::get('/{competition:public_id}/me/attempts', [ClubCompetitionController::class, 'myAttempts'])->name('me.attempts');
    });

    Route::get('/club-competition-attempts/{attempt}', [ClubCompetitionAttemptController::class, 'show'])
        ->whereNumber('attempt')->name('competition-attempts.show');
    Route::post('/club-competition-attempts/{attempt}/guess', [ClubCompetitionAttemptController::class, 'guess'])
        ->whereNumber('attempt')->middleware('throttle:gameplay')->name('competition-attempts.guess');
    Route::get('/me/club-finishes', [ClubCompetitionController::class, 'myFinishes'])->name('me.finishes');
});
