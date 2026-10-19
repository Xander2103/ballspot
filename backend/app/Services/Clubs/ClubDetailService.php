<?php

namespace App\Services\Clubs;

use App\Enums\ClubMemberStatus;
use App\Http\Resources\Clubs\ClubCardResource;
use App\Http\Resources\Clubs\ClubCompetitionCardResource;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionEntry;
use App\Models\ClubMember;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/clubs/{club} (contracts §5): ClubCard + identity, abilities and
 * the section previews (packs, competitions, leaderboard). Suspended clubs
 * are readable (D18); the app greys out play actions from `status`.
 */
class ClubDetailService
{
    public const UPCOMING_PREVIEW = 3;

    /** How stale last_seen_at may get before opening the club refreshes it (My Clubs "recent"). */
    private const SEEN_REFRESH_SECONDS = 300;

    public function __construct(
        private ClubPackService $packs,
        private ClubLeaderboardService $leaderboard,
    ) {}

    public function detail(Club $club, ClubMember $member, User $user): array
    {
        MyClubsQuery::loadCard($club);
        $this->touchSeen($member);

        $gate = Gate::forUser($user);

        return (new ClubCardResource($club, $member))->resolve() + [
            'description'          => $club->description,
            'region'               => $club->region,
            'members_count'        => (int) $club->members_count,
            'abilities'            => [
                'view_members' => $gate->allows('viewMembers', $club),
                'leave'        => $gate->allows('leave', $club),
                'manage'       => $gate->allows('manageMembers', $club),
            ],
            'packs_preview'        => $this->packs->preview($club, $user),
            'competitions_preview' => $this->competitionsPreview($club, $user),
            'leaderboard_preview'  => $this->leaderboard->preview($club, $user),
        ];
    }

    /**
     * Published competitions only — drafts and cancelled/voided ones are
     * never shown: every active one (bounded by limits.max_competitions_active),
     * the next upcoming ones, and the count of completed ones (ended + finalized).
     */
    public function competitionsPreview(Club $club, User $user): array
    {
        $now = now();

        $active = $club->competitions()->live($now)->withCount('competitionPacks')
            ->orderBy('ends_at')->orderBy('id')
            ->limit(max(1, (int) config('ballspot.clubs.limits.max_competitions_active', 10)))
            ->get();
        $upcoming = $club->competitions()->upcoming($now)->withCount('competitionPacks')
            ->orderBy('starts_at')->orderBy('id')
            ->limit(self::UPCOMING_PREVIEW)
            ->get();

        $standing = $this->standingsFor($club, $user, $active);

        return [
            'active'          => $active->map(fn (ClubCompetition $c) => (new ClubCompetitionCardResource($c, $standing[$c->id] ?? null))->resolve())->values()->all(),
            'upcoming'        => $upcoming->map(fn (ClubCompetition $c) => (new ClubCompetitionCardResource($c))->resolve())->values()->all(),
            'completed_count' => $club->competitions()->completed($now)->count(),
        ];
    }

    /**
     * The viewer's {rank, total_score, packs_completed} per competition from
     * the derived entries, ranked among ACTIVE members (D22) with the D17
     * order: total desc → score_reached_at asc → attempts_used asc.
     *
     * @return array<int, array{rank: int, total_score: int, packs_completed: int}>
     */
    private function standingsFor(Club $club, User $user, Collection $competitions): array
    {
        if ($competitions->isEmpty()) {
            return [];
        }

        $mine = ClubCompetitionEntry::where('user_id', $user->id)
            ->whereIn('club_competition_id', $competitions->pluck('id'))
            ->get();

        $activeMembers = fn ($q) => $q->select('user_id')->from('club_members')
            ->where('club_id', $club->id)
            ->where('status', ClubMemberStatus::Active->value);

        $out = [];
        foreach ($mine as $entry) {
            $total   = (int) $entry->total_score;
            $reached = $entry->getRawOriginal('score_reached_at');
            $used    = (int) $entry->attempts_used;

            $better = DB::table('club_competition_entries')
                ->where('club_competition_id', $entry->club_competition_id)
                ->whereIn('user_id', $activeMembers)
                ->where(function ($q) use ($total, $reached, $used) {
                    $q->where('total_score', '>', $total)
                        ->orWhere(function ($tie) use ($total, $reached, $used) {
                            $tie->where('total_score', $total);
                            if ($reached === null) {
                                $tie->whereNotNull('score_reached_at');
                            } else {
                                $tie->where(fn ($t) => $t->where('score_reached_at', '<', $reached)
                                    ->orWhere(fn ($tt) => $tt->where('score_reached_at', $reached)->where('attempts_used', '<', $used)));
                            }
                        });
                })
                ->count();

            $out[$entry->club_competition_id] = [
                'rank'            => $better + 1,
                'total_score'     => $total,
                'packs_completed' => (int) $entry->packs_completed,
            ];
        }

        return $out;
    }

    private function touchSeen(ClubMember $member): void
    {
        $seen = $member->last_seen_at;
        if ($seen !== null && $seen->gt(now()->subSeconds(self::SEEN_REFRESH_SECONDS))) {
            return;
        }

        $now = now();
        // Quiet write: no model events, no updated_at churn.
        DB::table('club_members')->where('id', $member->id)->update(['last_seen_at' => $now]);
        $member->setAttribute('last_seen_at', $now);
        $member->syncOriginalAttributes(['last_seen_at']);
    }
}
