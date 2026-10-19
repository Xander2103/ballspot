<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionFinish;
use App\Models\ClubContentReport;
use App\Models\ClubMember;
use App\Models\ClubPackRewardClaim;
use App\Models\ClubPhotoSubmission;
use App\Models\CompetitionFinish;
use App\Models\DailyChallengeGuess;
use App\Models\FriendRequest;
use App\Models\Guess;
use App\Models\NotificationSetting;
use App\Models\PackAttempt;
use App\Models\PushToken;
use App\Models\TournamentFinish;
use App\Models\User;
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
                // Consent record (GDPR Art. 7(1)) — the subject is entitled to
                // see what they accepted and when.
                'terms_accepted_at' => $user->terms_accepted_at?->toISOString(),
                'terms_version'     => $user->terms_version,
                'selected_theme' => $user->selected_theme,
                'preferred_sport' => $user->preferredSport?->name,
                'avatar_url'     => $user->avatarUrl(),
                // The subject's own share code. Safe here (this endpoint is
                // self-scoped); never included for any other user below.
                'friend_code'    => $user->friend_code,
            ],

            'notification_settings' => NotificationSetting::where('user_id', $user->id)
                ->first(['daily_reminder_enabled', 'tournament_reminder_enabled', 'admin_notifications_enabled', 'club_updates_enabled', 'reminder_time', 'timezone']),

            // Metadata only — the raw token value is a device credential.
            'push_tokens' => PushToken::where('user_id', $user->id)
                ->orderByDesc('last_seen_at')
                ->limit(self::MAX_ROWS)
                ->get(['platform', 'created_at', 'last_seen_at']),

            // Who the subject is connected to. Only the counterpart's public
            // identity (username/display name) — never their email or their
            // friend_code, which are that other person's data, not the
            // subject's. `DataExportTest` asserts both exclusions.
            'friends' => $user->friends()
                ->orderBy('username')
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn ($f) => [
                    'username'  => $f->username,
                    'name'      => $f->name,
                    'friends_since' => $f->pivot?->created_at?->toISOString(),
                ]),

            // All statuses, not just pending: declined and accepted rows are
            // retained until account deletion, so an export limited to pending
            // would understate what is actually held about the subject.
            'friend_requests' => [
                'incoming' => FriendRequest::where('recipient_id', $user->id)
                    ->with('requester:id,username,name')
                    ->limit(self::MAX_ROWS)
                    ->get()
                    ->map(fn ($r) => [
                        'username'   => $r->requester?->username,
                        'name'       => $r->requester?->name,
                        'status'     => $r->status,
                        'created_at' => $r->created_at?->toISOString(),
                    ]),
                'outgoing' => FriendRequest::where('requester_id', $user->id)
                    ->with('recipient:id,username,name')
                    ->limit(self::MAX_ROWS)
                    ->get()
                    ->map(fn ($r) => [
                        'username'   => $r->recipient?->username,
                        'name'       => $r->recipient?->name,
                        'status'     => $r->status,
                        'created_at' => $r->created_at?->toISOString(),
                    ]),
            ],

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

            'clubs' => $this->clubs($user),
        ]);
    }

    /**
     * BallPicker Clubs (plan §11.7, AT-PRV-02): the subject's own memberships
     * (every status — rows are kept as history), competition attempts, club
     * trophies, reward claims and own photo submissions (metadata only, never
     * the private file path). Clubs are identified by name + public id only:
     * never another member's identity or email, never an invite code, never
     * the club's contact details or admin notes.
     */
    private function clubs(User $user): array
    {
        $value = fn ($v) => $v instanceof \BackedEnum ? $v->value : $v;

        $attempts = ClubCompetitionAttempt::where('user_id', $user->id)
            ->with(['competition:id,public_id,name,club_id', 'competition.club:id,public_id,name', 'pack:id,name'])
            ->orderByDesc('started_at')
            ->limit(self::MAX_ROWS)
            ->get();

        return [
            'memberships' => ClubMember::where('user_id', $user->id)
                ->with('club:id,public_id,name')
                ->orderByDesc('joined_at')
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn (ClubMember $m) => [
                    'club'         => $m->club ? ['id' => $m->club->public_id, 'name' => $m->club->name] : null,
                    'role'         => $value($m->role),
                    'status'       => $value($m->status),
                    'joined_at'    => $m->joined_at?->toISOString(),
                    'left_at'      => $m->left_at?->toISOString(),
                    'last_seen_at' => $m->last_seen_at?->toISOString(),
                ])->values(),

            'competition_attempts_summary' => [
                'count'       => ClubCompetitionAttempt::where('user_id', $user->id)->count(),
                'completed'   => ClubCompetitionAttempt::where('user_id', $user->id)->where('status', 'completed')->count(),
                'total_score' => (int) ClubCompetitionAttempt::where('user_id', $user->id)->where('status', '!=', 'voided')->sum('total_score'),
            ],

            'competition_attempts' => $attempts->map(fn (ClubCompetitionAttempt $a) => [
                'club'           => $a->competition?->club?->name,
                'competition'    => $a->competition ? ['id' => $a->competition->public_id, 'name' => $a->competition->name] : null,
                'pack'           => $a->pack?->name,
                'attempt_number' => (int) $a->attempt_number,
                'status'         => $value($a->status),
                'total_score'    => (int) $a->total_score,
                'guesses_count'  => (int) $a->guesses_count,
                'started_at'     => $a->started_at?->toISOString(),
                'completed_at'   => $a->completed_at?->toISOString(),
            ])->values(),

            'competition_finishes' => ClubCompetitionFinish::where('user_id', $user->id)
                ->with('competition:id,public_id,name')
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn (ClubCompetitionFinish $f) => [
                    'competition'   => $f->competition?->name,
                    'placement'     => (int) $f->placement,
                    'total_score'   => (int) $f->total_score,
                    'total_players' => (int) $f->total_players,
                ])->values(),

            'pack_reward_claims' => ClubPackRewardClaim::where('user_id', $user->id)
                ->with('pack:id,name')
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn (ClubPackRewardClaim $c) => [
                    'pack'        => $c->pack?->name,
                    'source_type' => $c->source_type,
                    'claimed_at'  => $c->claimed_at?->toISOString(),
                ])->values(),

            'photo_submissions' => ClubPhotoSubmission::where('uploaded_by_user_id', $user->id)
                ->with('club:id,public_id,name')
                ->orderByDesc('created_at')
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn (ClubPhotoSubmission $p) => [
                    'club'             => $p->club?->name,
                    'status'           => $value($p->status),
                    'mime'             => $p->mime,
                    'width'            => $p->width,
                    'height'           => $p->height,
                    'bytes'            => $p->bytes,
                    'club_note'        => $p->club_note,
                    'rejection_reason' => $p->rejection_reason,
                    'created_at'       => $p->created_at?->toISOString(),
                ])->values(),

            // Wave F: content reports the subject filed (never the admin's
            // note or reviewer, never reports filed BY others about them).
            'content_reports' => ClubContentReport::where('reporter_user_id', $user->id)
                ->with('club:id,public_id,name')
                ->orderByDesc('created_at')
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn (ClubContentReport $r) => [
                    'club'         => $r->club ? ['id' => $r->club->public_id, 'name' => $r->club->name] : null,
                    'subject_type' => $r->subject_type,
                    'subject_ref'  => $r->subject_ref,
                    'reason'       => $r->reason,
                    'details'      => $r->details,
                    'status'       => $r->status,
                    'created_at'   => $r->created_at?->toISOString(),
                    'reviewed_at'  => $r->reviewed_at?->toISOString(),
                ])->values(),
        ];
    }
}
