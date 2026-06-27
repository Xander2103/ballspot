<?php
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DailyChallengeController;
use App\Http\Controllers\Api\LeagueController;
use App\Http\Controllers\Api\RoundController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn() => response()->json(['status' => 'ok', 'timestamp' => now()]));

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout',       [AuthController::class, 'logout']);
    Route::get('/me',            [AuthController::class, 'me']);
    Route::delete('/account',    [AccountController::class, 'delete']);
    Route::get('/profile/stats', [ProfileController::class, 'stats']);

    Route::get('/leagues',                        [LeagueController::class, 'index']);
    Route::post('/leagues',                       [LeagueController::class, 'store']);
    Route::post('/leagues/join',                  [LeagueController::class, 'join']);
    Route::get('/leagues/{league}',               [LeagueController::class, 'show']);
    Route::post('/leagues/{league}/start',        [LeagueController::class, 'start']);
    Route::delete('/leagues/{league}',            [LeagueController::class, 'destroy']);
    Route::delete('/leagues/{league}/members/{user}', [LeagueController::class, 'removeMember']);
    Route::get('/leagues/{league}/current-round', [LeagueController::class, 'currentRound']);
    Route::get('/leagues/{league}/leaderboard',   [LeaderboardController::class, 'index']);

    Route::post('/rounds/{round}/guess', [RoundController::class, 'submitGuess']);
    Route::get('/rounds/{round}/result', [RoundController::class, 'result']);

    Route::prefix('daily')->group(function () {
        Route::get('/today', [DailyChallengeController::class, 'today']);
        Route::get('/leaderboard/weekly', [DailyChallengeController::class, 'weeklyLeaderboard']);
        Route::get('/stats', [DailyChallengeController::class, 'stats']);
        Route::post('/{dailyChallenge}/guess', [DailyChallengeController::class, 'guess']);
        Route::get('/{dailyChallenge}/result', [DailyChallengeController::class, 'result']);
    });
});
