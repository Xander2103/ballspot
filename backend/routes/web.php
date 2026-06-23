<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Admin auth (unguarded)
Route::get('/admin/login', [\App\Http\Controllers\Admin\AuthController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [\App\Http\Controllers\Admin\AuthController::class, 'login'])->name('admin.login.submit');
Route::post('/admin/logout', [\App\Http\Controllers\Admin\AuthController::class, 'logout'])->name('admin.logout');

// Admin protected area
Route::prefix('admin')->middleware('admin')->group(function () {
    Route::resource('challenges', \App\Http\Controllers\Admin\ChallengeController::class);
    Route::resource('categories', \App\Http\Controllers\Admin\ChallengeCategoryController::class);
    Route::post('categories/{category}/toggle', [\App\Http\Controllers\Admin\ChallengeCategoryController::class, 'toggle'])
        ->name('admin.categories.toggle');
});
