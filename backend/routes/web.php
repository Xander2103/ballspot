<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin')->group(function () {
    Route::resource('challenges', \App\Http\Controllers\Admin\ChallengeController::class);
});
