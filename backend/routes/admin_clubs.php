<?php

/*
|--------------------------------------------------------------------------
| BallPicker Clubs — central admin (/admin/…)
|--------------------------------------------------------------------------
| Required from routes/web.php INSIDE the existing `admin` prefix/middleware
| group, so every route here is already /admin/… + session admin auth.
| Deliberately NOT behind feature:clubs: platform admins can prepare clubs
| before the app update ships.
|
| Every controller action still authorizes through the policies:
| $this->authorize('admin.manage', $club) or Gate::authorize('admin.manage')
| for indexes (Gate::before grants platform admins in the admin area).
| Domain failures throw ClubDomainException → back() with the `error` flash.
| Route names: admin.clubs.*, admin.club-competitions.*, admin.club-photos.*,
| admin.club-audit.*.
*/

use App\Http\Controllers\Admin\Clubs\ClubAuditController;
use App\Http\Controllers\Admin\Clubs\ClubCompetitionController;
use App\Http\Controllers\Admin\Clubs\ClubController;
use App\Http\Controllers\Admin\Clubs\ClubExportController;
use App\Http\Controllers\Admin\Clubs\ClubInviteCodeController;
use App\Http\Controllers\Admin\Clubs\ClubMemberController;
use App\Http\Controllers\Admin\Clubs\ClubPhotoController;
use App\Http\Controllers\Admin\Clubs\ClubReportController;
use Illuminate\Support\Facades\Route;

// -------------------------------------------------------------------------
// Admin clubs (B2 — Central Admin): index, create, detail tabs, edit,
// settings, suspend/archive/reactivate, delete-if-empty, members, invite
// codes + QR print, packs preset, audit tab, stats.
// -------------------------------------------------------------------------
// {club} binds by id (stable across renames); {member} and {inviteCode} are
// scoped to the club (another club's row → 404).
Route::prefix('clubs')->name('admin.clubs.')->whereNumber('club')->group(function () {
    Route::get('/', [ClubController::class, 'index'])->name('index');
    Route::get('create', [ClubController::class, 'create'])->name('create');
    Route::post('/', [ClubController::class, 'store'])->name('store');

    Route::get('{club}', [ClubController::class, 'show'])->name('show');
    Route::get('{club}/edit', [ClubController::class, 'edit'])->name('edit');
    Route::put('{club}', [ClubController::class, 'update'])->name('update');
    Route::put('{club}/settings', [ClubController::class, 'updateSettings'])->name('settings');
    Route::post('{club}/suspend', [ClubController::class, 'suspend'])->name('suspend');
    Route::post('{club}/archive', [ClubController::class, 'archive'])->name('archive');
    Route::post('{club}/reactivate', [ClubController::class, 'reactivate'])->name('reactivate');
    Route::delete('{club}', [ClubController::class, 'destroy'])->name('destroy');
    // "New club pack" → the existing pack form with the club preset.
    Route::get('{club}/packs/create', [ClubController::class, 'createPack'])->name('packs.create');

    Route::scopeBindings()->group(function () {
        Route::get('{club}/members', [ClubMemberController::class, 'index'])->name('members.index');
        Route::post('{club}/members', [ClubMemberController::class, 'store'])->name('members.store');
        Route::patch('{club}/members/{member}', [ClubMemberController::class, 'update'])->name('members.update');
        Route::post('{club}/members/{member}/remove', [ClubMemberController::class, 'remove'])->name('members.remove');
        Route::post('{club}/members/{member}/ban', [ClubMemberController::class, 'ban'])->name('members.ban');
        Route::post('{club}/members/{member}/unban', [ClubMemberController::class, 'unban'])->name('members.unban');
        Route::post('{club}/members/{member}/reactivate', [ClubMemberController::class, 'reactivate'])->name('members.reactivate');
        Route::post('{club}/transfer-ownership', [ClubMemberController::class, 'transferOwnership'])->name('transfer-ownership');

        Route::get('{club}/codes', [ClubInviteCodeController::class, 'index'])->name('codes.index');
        Route::post('{club}/codes', [ClubInviteCodeController::class, 'store'])->name('codes.store');
        Route::post('{club}/codes/{inviteCode}/revoke', [ClubInviteCodeController::class, 'revoke'])->name('codes.revoke');
        Route::post('{club}/codes/{inviteCode}/regenerate', [ClubInviteCodeController::class, 'regenerate'])->name('codes.regenerate');
        Route::get('{club}/codes/{inviteCode}/print', [ClubInviteCodeController::class, 'print'])->name('codes.print');
    });
});

// -------------------------------------------------------------------------
// Admin competitions (C1 — Competitions): per-club editor + publish/cancel/
// void/extend/rebuild, attempt void, cross-club list admin.club-competitions.*.
// -------------------------------------------------------------------------
Route::get('club-competitions', [ClubCompetitionController::class, 'index'])->name('admin.club-competitions.index');

// Club-scoped editor. {club} by id; {competition} by id within the club and
// {attempt} within the competition (scoped bindings: another club's
// competition or another competition's attempt → 404).
Route::prefix('clubs/{club}/competitions')->name('admin.clubs.competitions.')
    ->whereNumber(['club', 'competition', 'attempt'])->scopeBindings()
    ->controller(ClubCompetitionController::class)->group(function () {
        Route::get('/', 'clubIndex')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{competition}', 'show')->name('show');
        Route::put('{competition}', 'update')->name('update');
        Route::post('{competition}/publish', 'publish')->name('publish');
        Route::post('{competition}/cancel', 'cancel')->name('cancel');
        Route::post('{competition}/extend', 'extend')->name('extend');
        Route::post('{competition}/void', 'void')->name('void');
        Route::post('{competition}/rebuild', 'rebuild')->name('rebuild');
        Route::post('{competition}/finalize', 'finalize')->name('finalize');
        Route::post('{competition}/attempts/{attempt}/void', 'voidAttempt')->name('attempts.void');
    });

// -------------------------------------------------------------------------
// Admin photos (D1 — Photos): global review queue admin.club-photos.*,
// upload on behalf of a club, assign / prepare / convert / reject.
// -------------------------------------------------------------------------
// {submission} binds a ClubPhotoSubmission by id; actions authorize
// admin.manage on its club. Workflow rules: ClubPhotoReviewService.
Route::prefix('club-photos')->name('admin.club-photos.')->whereNumber('submission')->group(function () {
    Route::get('/', [ClubPhotoController::class, 'index'])->name('index');
    Route::get('{submission}/prepare', [ClubPhotoController::class, 'prepare'])->name('prepare');
    Route::get('{submission}/original', [ClubPhotoController::class, 'download'])->name('download');
    Route::post('{submission}/assign', [ClubPhotoController::class, 'assign'])->name('assign');
    Route::post('{submission}/in-review', [ClubPhotoController::class, 'markInReview'])->name('in-review');
    Route::post('{submission}/start-preparation', [ClubPhotoController::class, 'startPreparation'])->name('start-preparation');
    Route::post('{submission}/reject', [ClubPhotoController::class, 'reject'])->name('reject');
    Route::post('{submission}/convert', [ClubPhotoController::class, 'convert'])->name('convert');
});
// Upload on behalf of a club (multi-file), from the club detail Photos tab.
Route::post('clubs/{club}/photos', [ClubPhotoController::class, 'store'])->whereNumber('club')->name('admin.clubs.photos.store');

// -------------------------------------------------------------------------
// Global audit log (B2): admin.club-audit.index.
// -------------------------------------------------------------------------
Route::get('club-audit', [ClubAuditController::class, 'index'])->name('admin.club-audit.index');

// -------------------------------------------------------------------------
// Wave F: member content reports (admin.club-reports.*) and CSV downloads of
// the club leaderboard / a competition's standings (usernames only, audited).
// -------------------------------------------------------------------------
Route::get('club-reports', [ClubReportController::class, 'index'])->name('admin.club-reports.index');
Route::post('club-reports/{report}/resolve', [ClubReportController::class, 'resolve'])->whereNumber('report')->name('admin.club-reports.resolve');

Route::get('clubs/{club}/leaderboard.csv', [ClubExportController::class, 'leaderboard'])->whereNumber('club')->name('admin.clubs.leaderboard.csv');
Route::get('clubs/{club}/competitions/{competition}/standings.csv', [ClubExportController::class, 'competition'])
    ->whereNumber(['club', 'competition'])->scopeBindings()->name('admin.clubs.competitions.standings-csv');
