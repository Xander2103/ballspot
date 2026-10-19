<?php

namespace App\Services\Clubs;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\CompetitionState;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubCompetition;
use App\Models\ClubPhotoSubmission;
use App\Models\PackAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Admin statistics for one club (plan §8.3 overview). Aggregate queries with
 * conditional sums — a fixed number of queries whatever the club size:
 *
 *  - members:  total active, owners/admins, seen in the last 7/30 days
 *              (`last_seen_at`), joins / leaves in the last 30 days
 *  - plays:    normal-track pack attempts on the club's packs + competition
 *              attempts, started in the last 7/30 days; completions
 *  - content:  packs (active/total), live/upcoming competitions, open photos,
 *              usable invite codes
 *  - recent audit events
 */
class ClubStatsService
{
    public function overview(Club $club, int $recentEvents = 8): array
    {
        $now   = now();
        $d7    = $now->copy()->subDays(7);
        $d30   = $now->copy()->subDays(30);
        $active = ClubMemberStatus::Active->value;

        $m = DB::table('club_members')
            ->where('club_id', $club->id)
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active_total', [$active])
            ->selectRaw('SUM(CASE WHEN status = ? AND role = ? THEN 1 ELSE 0 END) AS owners', [$active, ClubRole::Owner->value])
            ->selectRaw('SUM(CASE WHEN status = ? AND role = ? THEN 1 ELSE 0 END) AS admins', [$active, ClubRole::Admin->value])
            ->selectRaw('SUM(CASE WHEN status = ? AND last_seen_at >= ? THEN 1 ELSE 0 END) AS seen_7d', [$active, $d7])
            ->selectRaw('SUM(CASE WHEN status = ? AND last_seen_at >= ? THEN 1 ELSE 0 END) AS seen_30d', [$active, $d30])
            ->selectRaw('SUM(CASE WHEN joined_at >= ? THEN 1 ELSE 0 END) AS joins_30d', [$d30])
            ->selectRaw('SUM(CASE WHEN status <> ? AND left_at >= ? THEN 1 ELSE 0 END) AS leaves_30d', [$active, $d30])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS banned', [ClubMemberStatus::Banned->value])
            ->first();

        $packIds = ChallengePack::query()->select('id')->where('club_id', $club->id);

        $p = PackAttempt::query()
            ->whereIn('challenge_pack_id', $packIds)
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS plays_7d', [$d7])
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS plays_30d', [$d30])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completions', [PackAttempt::STATUS_COMPLETED])
            ->selectRaw('SUM(CASE WHEN status = ? AND completed_at >= ? THEN 1 ELSE 0 END) AS completions_30d', [PackAttempt::STATUS_COMPLETED, $d30])
            ->selectRaw('COUNT(DISTINCT CASE WHEN created_at >= ? THEN user_id END) AS players_30d', [$d30])
            ->toBase()->first();

        $c = DB::table('club_competition_attempts')
            ->join('club_competitions', 'club_competitions.id', '=', 'club_competition_attempts.club_competition_id')
            ->where('club_competitions.club_id', $club->id)
            ->selectRaw('SUM(CASE WHEN club_competition_attempts.started_at >= ? THEN 1 ELSE 0 END) AS plays_7d', [$d7])
            ->selectRaw('SUM(CASE WHEN club_competition_attempts.started_at >= ? THEN 1 ELSE 0 END) AS plays_30d', [$d30])
            ->selectRaw("SUM(CASE WHEN club_competition_attempts.status = 'completed' THEN 1 ELSE 0 END) AS completions")
            ->first();

        $packs = ChallengePack::query()->where('club_id', $club->id)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active', [ChallengePack::STATUS_ACTIVE])
            ->toBase()->first();

        $live = ClubCompetition::query()->where('club_id', $club->id)->live($now)
            ->orderBy('ends_at')->limit(5)
            ->get(['id', 'public_id', 'club_id', 'name', 'state', 'starts_at', 'ends_at', 'participants_count']);

        $liveCount = $live->count() < 5 ? $live->count() : ClubCompetition::query()->where('club_id', $club->id)->live($now)->count();
        $upcoming = ClubCompetition::query()->where('club_id', $club->id)->upcoming($now)->count();
        $drafts   = ClubCompetition::query()->where('club_id', $club->id)->where('state', CompetitionState::Draft->value)->count();

        $photos = ClubPhotoSubmission::query()->where('club_id', $club->id)->open()
            ->selectRaw('COUNT(*) AS open_total')
            ->selectRaw('MIN(created_at) AS oldest')
            ->toBase()->first();

        // Report events (who reported whom) are platform-only: never in the club's activity feed.
        $events = ClubAuditEvent::query()->where('club_id', $club->id)->visibleToClub()
            ->with('actor:id,username,name')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($recentEvents)->get();

        return [
            'members' => [
                'total'      => (int) ($m->active_total ?? 0),
                'owners'     => (int) ($m->owners ?? 0),
                'admins'     => (int) ($m->admins ?? 0),
                'active_7d'  => (int) ($m->seen_7d ?? 0),
                'active_30d' => (int) ($m->seen_30d ?? 0),
                'joins_30d'  => (int) ($m->joins_30d ?? 0),
                'leaves_30d' => (int) ($m->leaves_30d ?? 0),
                'banned'     => (int) ($m->banned ?? 0),
                'capacity'   => $club->effectiveMaxMembers(),
            ],
            'plays' => [
                'plays_7d'                => (int) ($p->plays_7d ?? 0) + (int) ($c->plays_7d ?? 0),
                'plays_30d'               => (int) ($p->plays_30d ?? 0) + (int) ($c->plays_30d ?? 0),
                'pack_plays_30d'          => (int) ($p->plays_30d ?? 0),
                'competition_plays_30d'   => (int) ($c->plays_30d ?? 0),
                'pack_completions'        => (int) ($p->completions ?? 0),
                'pack_completions_30d'    => (int) ($p->completions_30d ?? 0),
                'competition_completions' => (int) ($c->completions ?? 0),
                'players_30d'             => (int) ($p->players_30d ?? 0),
            ],
            'packs' => [
                'total'  => (int) ($packs->total ?? 0),
                'active' => (int) ($packs->active ?? 0),
            ],
            'competitions' => [
                'live'          => $live,
                'live_count'    => $liveCount,
                'upcoming'      => $upcoming,
                'drafts'        => $drafts,
            ],
            'photos' => [
                'open'   => (int) ($photos->open_total ?? 0),
                'oldest' => $photos->oldest ?? null,
            ],
            'codes' => [
                'usable' => $club->inviteCodes()->usable()->count(),
            ],
            'recent_events' => $events,
        ];
    }
}
