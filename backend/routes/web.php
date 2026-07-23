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
Route::post('/admin/login', [\App\Http\Controllers\Admin\AuthController::class, 'login'])->name('admin.login.submit');
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
    Route::get('sports', [\App\Http\Controllers\Admin\SportController::class, 'index'])->name('admin.sports.index');
    Route::post('sports/{sport}/status', [\App\Http\Controllers\Admin\SportController::class, 'updateStatus'])
        ->name('admin.sports.status');

    Route::get('notifications', [\App\Http\Controllers\Admin\NotificationController::class, 'index'])->name('admin.notifications.index');
    Route::post('notifications', [\App\Http\Controllers\Admin\NotificationController::class, 'store'])->name('admin.notifications.store');
    Route::post('notifications/{adminNotification}/send', [\App\Http\Controllers\Admin\NotificationController::class, 'send'])->name('admin.notifications.send');

    Route::prefix('daily')->name('admin.daily.')->group(function () {
        Route::get('/', [DailyChallengeAdminController::class, 'index'])->name('index');
        Route::get('/create', [DailyChallengeAdminController::class, 'create'])->name('create');
        Route::post('/', [DailyChallengeAdminController::class, 'store'])->name('store');
        Route::patch('/{dailyChallenge}/status', [DailyChallengeAdminController::class, 'updateStatus'])->name('updateStatus');
    });
});
