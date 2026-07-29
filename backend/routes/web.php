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
    Route::resource('challenges', \App\Http\Controllers\Admin\ChallengeController::class);
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
    Route::post('sports/{sport}/status', [\App\Http\Controllers\Admin\SportController::class, 'updateStatus'])
        ->name('admin.sports.status');

    Route::get('notifications', [\App\Http\Controllers\Admin\NotificationController::class, 'index'])->name('admin.notifications.index');
    Route::post('notifications', [\App\Http\Controllers\Admin\NotificationController::class, 'store'])->name('admin.notifications.store');
    Route::post('notifications/{adminNotification}/send', [\App\Http\Controllers\Admin\NotificationController::class, 'send'])->name('admin.notifications.send')->middleware('throttle:admin-send');

    Route::prefix('daily')->name('admin.daily.')->group(function () {
        Route::get('/', [DailyChallengeAdminController::class, 'index'])->name('index');
        Route::get('/create', [DailyChallengeAdminController::class, 'create'])->name('create');
        Route::post('/', [DailyChallengeAdminController::class, 'store'])->name('store');
        Route::patch('/{dailyChallenge}/status', [DailyChallengeAdminController::class, 'updateStatus'])->name('updateStatus');
    });
});
