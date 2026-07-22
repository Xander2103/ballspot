<?php
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\LoginVerificationController;
use App\Http\Controllers\Api\BadgeController;
use App\Http\Controllers\Api\DailyChallengeController;
use App\Http\Controllers\Api\LeagueController;
use App\Http\Controllers\Api\RoundController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PreferenceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\AvatarController;
use App\Http\Controllers\Api\SportController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn() => response()->json(['status' => 'ok', 'timestamp' => now()]));

Route::post('/register', [AuthController::class, 'register']);

// Email two-factor login (step 1: credentials -> emailed code; step 2: verify -> token)
Route::post('/login',              [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/login/verify',       [LoginVerificationController::class, 'verify'])->middleware('throttle:login-verify');
Route::post('/login/resend-code',  [LoginVerificationController::class, 'resend'])->middleware('throttle:login-resend');

Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
Route::post('/reset-password',  [PasswordResetController::class, 'reset']);

Route::middleware('auth:sanctum')->group(function () {
    // Available to any authenticated user, verified or not — these are exactly
    // what an unverified user needs to verify, inspect, or leave their account.
    Route::post('/logout',       [AuthController::class, 'logout']);
    Route::get('/me',            [AuthController::class, 'me']);
    Route::delete('/account',    [AccountController::class, 'delete']);

    // Email verification (register step 2)
    Route::post('/email/verify',                    [EmailVerificationController::class, 'verify']);
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend']);

    // Sport list is non-sensitive onboarding reference data — needed during
    // sign-up before/while verifying, so it is NOT behind the verified gate.
    Route::get('/sports', [SportController::class, 'index']);

    // Full app access requires a verified email.
    Route::middleware('verified')->group(function () {
        Route::get('/profile/stats', [ProfileController::class, 'stats']);
        Route::get('/me/rank',       [ProfileController::class, 'rank']);
        Route::get('/me/xp-events',  [ProfileController::class, 'xpEvents']);

        // User preferences (sport / theme) + avatar
        Route::get('/me/preferences',   [PreferenceController::class, 'show']);
        Route::patch('/me/preferences', [PreferenceController::class, 'update']);
        Route::post('/me/avatar',       [AvatarController::class, 'store']);
        Route::delete('/me/avatar',     [AvatarController::class, 'destroy']);

        Route::get('/badges',    [BadgeController::class, 'index']);
        Route::get('/me/badges', [BadgeController::class, 'mine']);

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
});
