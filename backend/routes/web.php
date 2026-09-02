<?php

use App\Http\Controllers\Admin\DailyChallengeAdminController;
use App\Http\Controllers\PublicController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Public legal / info pages
Route::get('/privacy', [PublicController::class, 'privacy'])->name('privacy');
Route::get('/terms',   [PublicController::class, 'terms'])->name('terms');
Route::get('/support', [PublicController::class, 'support'])->name('support');

// Admin auth (unguarded)
Route::get('/admin/login', [\App\Http\Controllers\Admin\AuthController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [\App\Http\Controllers\Admin\AuthController::class, 'login'])->name('admin.login.submit')->middleware('throttle:admin-login');
Route::post('/admin/logout', [\App\Http\Controllers\Admin\AuthController::class, 'logout'])->name('admin.logout');

// Admin protected area
Route::prefix('admin')->middleware('admin')->group(function () {
    // No show() on the controller — registering the route would make
    // GET /admin/challenges/{id} a 500 (BadMethodCallException).
    Route::resource('challenges', \App\Http\Controllers\Admin\ChallengeController::class)->except(['show']);
    Route::get('challenges/{challenge}/preview', [\App\Http\Controllers\Admin\ChallengeController::class, 'preview'])->name('admin.challenges.preview');
    Route::post('challenges/{challenge}/status', [\App\Http\Controllers\Admin\ChallengeController::class, 'updateStatus'])->name('admin.challenges.status');
    Route::post('challenges/{challenge}/set-as-daily', [\App\Http\Controllers\Admin\ChallengeController::class, 'setAsDaily'])->name('admin.challenges.set-as-daily');
    Route::resource('categories', \App\Http\Controllers\Admin\ChallengeCategoryController::class);
    Route::post('categories/{category}/toggle', [\App\Http\Controllers\Admin\ChallengeCategoryController::class, 'toggle'])
        ->name('admin.categories.toggle');
    // Curated subcategories (content organisation/filtering)
    Route::get('subcategories', [\App\Http\Controllers\Admin\ChallengeSubcategoryController::class, 'index'])->name('admin.subcategories.index');
    Route::get('subcategories/create', [\App\Http\Controllers\Admin\ChallengeSubcategoryController::class, 'create'])->name('admin.subcategories.create');
    Route::post('subcategories', [\App\Http\Controllers\Admin\ChallengeSubcategoryController::class, 'store'])->name('admin.subcategories.store');
    Route::get('subcategories/{subcategory}/edit', [\App\Http\Controllers\Admin\ChallengeSubcategoryController::class, 'edit'])->name('admin.subcategories.edit');
    Route::put('subcategories/{subcategory}', [\App\Http\Controllers\Admin\ChallengeSubcategoryController::class, 'update'])->name('admin.subcategories.update');
    Route::post('subcategories/{subcategory}/status', [\App\Http\Controllers\Admin\ChallengeSubcategoryController::class, 'status'])->name('admin.subcategories.status');

    // Challenge packs (content collections — no purchases)
    Route::get('packs', [\App\Http\Controllers\Admin\ChallengePackController::class, 'index'])->name('admin.packs.index');
    Route::get('packs/create', [\App\Http\Controllers\Admin\ChallengePackController::class, 'create'])->name('admin.packs.create');
    Route::post('packs', [\App\Http\Controllers\Admin\ChallengePackController::class, 'store'])->name('admin.packs.store');
    Route::get('packs/{pack}/edit', [\App\Http\Controllers\Admin\ChallengePackController::class, 'edit'])->name('admin.packs.edit');
    Route::put('packs/{pack}', [\App\Http\Controllers\Admin\ChallengePackController::class, 'update'])->name('admin.packs.update');
    Route::post('packs/{pack}/status', [\App\Http\Controllers\Admin\ChallengePackController::class, 'status'])->name('admin.packs.status');

    Route::get('competition', [\App\Http\Controllers\Admin\CompetitionController::class, 'index'])->name('admin.competition.index');

    Route::get('sports', [\App\Http\Controllers\Admin\SportController::class, 'index'])->name('admin.sports.index');
    Route::get('sports/create', [\App\Http\Controllers\Admin\SportController::class, 'create'])->name('admin.sports.create');
    Route::post('sports', [\App\Http\Controllers\Admin\SportController::class, 'store'])->name('admin.sports.store');
    Route::get('sports/{sport}/edit', [\App\Http\Controllers\Admin\SportController::class, 'edit'])->name('admin.sports.edit');
    Route::put('sports/{sport}', [\App\Http\Controllers\Admin\SportController::class, 'update'])->name('admin.sports.update');
    // No hard-delete of sports with content — hide/archive via status instead.
    Route::delete('sports/{sport}', [\App\Http\Controllers\Admin\SportController::class, 'destroy'])->name('admin.sports.destroy');
    Route::post('sports/{sport}/status', [\App\Http\Controllers\Admin\SportController::class, 'updateStatus'])
        ->name('admin.sports.status');

    // Gameplay settings (tournament challenge cooldown)
    Route::get('settings', [\App\Http\Controllers\Admin\SettingsController::class, 'index'])->name('admin.settings.index');
    Route::put('settings', [\App\Http\Controllers\Admin\SettingsController::class, 'update'])->name('admin.settings.update');

    // Read-only operational status (queue, daily, content pool, storage, log
    // counts). Never runs shell commands, never shows secrets.
    Route::get('diagnostics', [\App\Http\Controllers\Admin\DiagnosticsController::class, 'index'])->name('admin.diagnostics.index');

    Route::get('notifications', [\App\Http\Controllers\Admin\NotificationController::class, 'index'])->name('admin.notifications.index');
    // Throttled like /send: store() also fans out to every device when the
    // composer is submitted with send_now.
    Route::post('notifications', [\App\Http\Controllers\Admin\NotificationController::class, 'store'])->name('admin.notifications.store')->middleware('throttle:admin-send');
    Route::post('notifications/{adminNotification}/send', [\App\Http\Controllers\Admin\NotificationController::class, 'send'])->name('admin.notifications.send')->middleware('throttle:admin-send');

    Route::prefix('daily')->name('admin.daily.')->group(function () {
        Route::get('/', [DailyChallengeAdminController::class, 'index'])->name('index');
        Route::get('/create', [DailyChallengeAdminController::class, 'create'])->name('create');
        Route::post('/', [DailyChallengeAdminController::class, 'store'])->name('store');
        Route::patch('/{dailyChallenge}/status', [DailyChallengeAdminController::class, 'updateStatus'])->name('updateStatus');
    });
});
