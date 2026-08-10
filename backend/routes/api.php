<?php
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\LoginVerificationController;
use App\Http\Controllers\Api\BadgeController;
use App\Http\Controllers\Api\ChallengePackController;
use App\Http\Controllers\Api\PackPlayController;
use App\Http\Controllers\Api\DailyChallengeController;
use App\Http\Controllers\Api\FriendController;
use App\Http\Controllers\Api\LeagueController;
use App\Http\Controllers\Api\RoundController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\NotificationSettingsController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PreferenceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicProfileController;
use App\Http\Controllers\Api\RankController;
use App\Http\Controllers\Api\AvatarController;
use App\Http\Controllers\Api\SportController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn() => response()->json(['status' => 'ok', 'timestamp' => now()]));

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');

// Email two-factor login (step 1: credentials -> emailed code; step 2: verify -> token)
Route::post('/login',              [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/login/verify',       [LoginVerificationController::class, 'verify'])->middleware('throttle:login-verify');
Route::post('/login/resend-code',  [LoginVerificationController::class, 'resend'])->middleware('throttle:login-resend');

Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:forgot-password');
Route::post('/reset-password',  [PasswordResetController::class, 'reset'])->middleware('throttle:reset-password');

Route::middleware('auth:sanctum')->group(function () {
    // Available to any authenticated user, verified or not — these are exactly
    // what an unverified user needs to verify, inspect, or leave their account.
    Route::post('/logout',       [AuthController::class, 'logout']);
    Route::get('/me',            [AuthController::class, 'me']);
    Route::delete('/account',    [AccountController::class, 'delete']);
    // GDPR export — deliberately NOT behind the verified gate: an unverified
    // user has the same right to their data.
    Route::get('/me/export',     [\App\Http\Controllers\Api\DataExportController::class, 'show'])->middleware('throttle:export');

    // Email verification (register step 2)
    Route::post('/email/verify',                    [EmailVerificationController::class, 'verify'])->middleware('throttle:email-verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:email-resend');

    // Sport list is non-sensitive onboarding reference data — needed during
    // sign-up before/while verifying, so it is NOT behind the verified gate.
    Route::get('/sports', [SportController::class, 'index']);
    // Static rank ladder (config-driven, no user data) — available pre-verification
    // so the "All ranks" overview works during onboarding.
    Route::get('/ranks', [RankController::class, 'index']);

    // Full app access requires a verified email.
    Route::middleware('verified')->group(function () {
        Route::get('/profile/stats', [ProfileController::class, 'stats']);
        Route::get('/me/rank',       [ProfileController::class, 'rank']);
        Route::get('/me/xp-events',  [ProfileController::class, 'xpEvents']);
        Route::get('/me/tournament-finishes', [ProfileController::class, 'tournamentFinishes']);
        Route::get('/me/competition-finishes', [ProfileController::class, 'competitionFinishes']);

        // User preferences (sport / theme) + avatar
        Route::get('/me/preferences',   [PreferenceController::class, 'show']);
        Route::patch('/me/preferences', [PreferenceController::class, 'update']);

        // Notification settings (synced across devices) + Expo push registration
        Route::get('/me/notification-settings',  [NotificationSettingsController::class, 'show']);
        Route::put('/me/notification-settings',  [NotificationSettingsController::class, 'update']);
        Route::post('/me/push-tokens',           [PushTokenController::class, 'store'])->middleware('throttle:push-tokens');
        Route::delete('/me/push-tokens',         [PushTokenController::class, 'destroy'])->middleware('throttle:push-tokens');
        Route::post('/me/avatar',       [AvatarController::class, 'store'])->middleware('throttle:uploads');
        Route::delete('/me/avatar',     [AvatarController::class, 'destroy']);

        // Friends — first version: codes, requests, list. No chat, no realtime.
        // /friends/requests stays above any wildcard /friends/{...} route.
        Route::get('/me/friend-code',    [FriendController::class, 'friendCode']);
        Route::get('/friends',           [FriendController::class, 'index']);
        Route::get('/friends/suggestions', [FriendController::class, 'suggestions']);
        Route::get('/friends/requests',  [FriendController::class, 'requests']);
        Route::post('/friends/requests', [FriendController::class, 'store'])->middleware('throttle:friends');
        Route::post('/friends/requests/{friendRequest}/accept', [FriendController::class, 'accept'])->middleware('throttle:friends');
        Route::post('/friends/requests/{friendRequest}/reject', [FriendController::class, 'reject'])->middleware('throttle:friends');
        Route::delete('/friends/{user}', [FriendController::class, 'destroy'])->middleware('throttle:friends');

        // Read-only public view of another player (explicit field allow-list).
        Route::get('/users/{user}/public-profile', [PublicProfileController::class, 'show'])
            ->middleware('throttle:profile-lookup');

        Route::get('/badges',    [BadgeController::class, 'index']);
        Route::get('/me/badges', [BadgeController::class, 'mine']);

        // Challenge packs — discovery only (content, not paid). Active+public.
        Route::get('/packs',         [ChallengePackController::class, 'index']);
        Route::get('/packs/{slug}',  [ChallengePackController::class, 'show']);

        // Pack play mode (attempts, sequential guesses, completion).
        Route::post('/packs/{slug}/start',   [PackPlayController::class, 'start']);
        Route::get('/packs/{slug}/attempt',  [PackPlayController::class, 'attempt']);
        Route::post('/pack-attempts/{attempt}/guess', [PackPlayController::class, 'guess'])->middleware('throttle:gameplay');
        Route::get('/me/pack-completions',   [ProfileController::class, 'packCompletions']);

        Route::get('/leagues',                        [LeagueController::class, 'index']);
        Route::post('/leagues',                       [LeagueController::class, 'store']);
        Route::post('/leagues/join',                  [LeagueController::class, 'join']);
        Route::get('/leagues/{league}',               [LeagueController::class, 'show']);
        Route::post('/leagues/{league}/start',        [LeagueController::class, 'start']);
        Route::delete('/leagues/{league}',            [LeagueController::class, 'destroy']);
        // Per-user "remove from my list" for finished tournaments — deletes nothing.
        Route::post('/leagues/{league}/hide',         [LeagueController::class, 'hide']);
        Route::delete('/leagues/{league}/members/{user}', [LeagueController::class, 'removeMember']);
        Route::get('/leagues/{league}/current-round', [LeagueController::class, 'currentRound']);
        Route::get('/leagues/{league}/leaderboard',   [LeaderboardController::class, 'index']);

        Route::post('/rounds/{round}/guess', [RoundController::class, 'submitGuess'])->middleware('throttle:gameplay');
        Route::get('/rounds/{round}/result', [RoundController::class, 'result']);

        Route::prefix('daily')->group(function () {
            Route::get('/today', [DailyChallengeController::class, 'today']);
            Route::get('/leaderboard/weekly', [DailyChallengeController::class, 'weeklyLeaderboard']);
            Route::get('/stats', [DailyChallengeController::class, 'stats']);
            Route::post('/{dailyChallenge}/guess', [DailyChallengeController::class, 'guess'])->middleware('throttle:gameplay');
            Route::get('/{dailyChallenge}/result', [DailyChallengeController::class, 'result']);
        });
    });
});
