<?php
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeagueController;
use App\Http\Controllers\Api\RoundController;
use App\Http\Controllers\Api\LeaderboardController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn() => response()->json(['status' => 'ok', 'timestamp' => now()]));

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/leagues', [LeagueController::class, 'index']);
    Route::post('/leagues', [LeagueController::class, 'store']);
    Route::post('/leagues/join', [LeagueController::class, 'join']);
    Route::get('/leagues/{league}', [LeagueController::class, 'show']);
    Route::get('/leagues/{league}/current-round', [LeagueController::class, 'currentRound']);
    Route::get('/leagues/{league}/leaderboard', [LeaderboardController::class, 'index']);

    Route::post('/rounds/{round}/guess', [RoundController::class, 'submitGuess']);
    Route::get('/rounds/{round}/result', [RoundController::class, 'result']);
});
