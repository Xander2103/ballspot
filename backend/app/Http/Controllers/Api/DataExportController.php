<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompetitionFinish;
use App\Models\DailyChallengeGuess;
use App\Models\Guess;
use App\Models\NotificationSetting;
use App\Models\PackAttempt;
use App\Models\PushToken;
use App\Models\TournamentFinish;
use App\Models\XpEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GDPR data export (Art. 15 / 20): everything BallPicker stores about the
 * authenticated user, as JSON. Never includes secrets: no password hash, no
 * API/reset/verification tokens, no raw push token values.
 */
class DataExportController extends Controller
{
    /** Each list is capped; newest rows first. */
    private const MAX_ROWS = 1000;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'exported_at' => now()->toISOString(),

            'account' => [
                'id'             => $user->id,
                'name'           => $user->name,
                'username'       => $user->username,
                'email'          => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
                'created_at'     => $user->created_at?->toISOString(),
                'selected_theme' => $user->selected_theme,
                'preferred_sport' => $user->preferredSport?->name,
                'avatar_url'     => $user->avatarUrl(),
            ],

            'notification_settings' => NotificationSetting::where('user_id', $user->id)
                ->first(['daily_reminder_enabled', 'tournament_reminder_enabled', 'admin_notifications_enabled', 'reminder_time', 'timezone']),

            // Metadata only — the raw token value is a device credential.
            'push_tokens' => PushToken::where('user_id', $user->id)
                ->orderByDesc('last_seen_at')
                ->limit(self::MAX_ROWS)
                ->get(['platform', 'created_at', 'last_seen_at']),

            'xp_events' => XpEvent::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(self::MAX_ROWS)
                ->get(['source_type', 'source_id', 'amount', 'reason', 'created_at']),

            'badges' => $user->badges()
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn ($badge) => [
                    'key'        => $badge->key ?? $badge->slug ?? null,
                    'name'       => $badge->name,
                    'awarded_at' => $badge->pivot?->created_at?->toISOString(),
                ]),

            'daily_guesses' => DailyChallengeGuess::where('user_id', $user->id)
                ->with('dailyChallenge:id,challenge_date')
                ->orderByDesc('submitted_at')
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn ($g) => [
                    'date'         => $g->dailyChallenge?->challenge_date,
                    'score'        => $g->score,
                    'submitted_at' => $g->submitted_at?->toISOString(),
                ]),

            'tournament_guesses_summary' => Guess::where('user_id', $user->id)
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(score), 0) as total_score, ROUND(AVG(score), 1) as average_score')
                ->first(),

            'tournament_finishes' => TournamentFinish::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(self::MAX_ROWS)
                ->get(['league_id', 'placement', 'total_score', 'rounds_played', 'created_at']),

            'competition_finishes' => CompetitionFinish::where('user_id', $user->id)
                ->orderByDesc('period_start')
                ->limit(self::MAX_ROWS)
                ->get(['period_type', 'period_label', 'period_start', 'period_end', 'placement', 'total_score', 'total_players', 'xp_awarded']),

            'pack_completions' => PackAttempt::where('user_id', $user->id)
                ->where('status', PackAttempt::STATUS_COMPLETED)
                ->with('pack:id,name,slug')
                ->orderByDesc('completed_at')
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn ($a) => [
                    'pack'         => $a->pack?->name,
                    'total_score'  => $a->total_score,
                    'completed_at' => $a->completed_at?->toISOString(),
                ]),
        ]);
    }
}
