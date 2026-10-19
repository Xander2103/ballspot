<?php

namespace App\Services\Clubs;

use App\Enums\CompetitionState;
use App\Http\Resources\Clubs\ClubCompetitionCardResource;
use App\Http\Resources\Clubs\CompetitionAttemptResource;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionFinish;
use App\Models\ClubCompetitionPack;
use App\Models\ClubCompetitionPackResult;
use App\Models\User;

/**
 * Member-API reads of the competitions section (contracts §5): list by
 * phase (cursor), detail with the per-pack `state`, my attempts and my club
 * finishes. Drafts are never visible to members (404 like a non-member).
 */
class ClubCompetitionQuery
{
    public const PHASES = ['active', 'upcoming', 'completed'];

    public function __construct(
        private CompetitionEligibility $eligibility,
        private ClubStandingsService $standings,
    ) {}

    /** Members see every non-draft competition of their club. */
    public function assertVisible(Club $club, ClubCompetition $competition): void
    {
        if ((int) $competition->club_id !== (int) $club->id || $competition->state === CompetitionState::Draft) {
            throw \App\Exceptions\ClubDomainException::notFound();
        }
    }

    /**
     * GET /api/clubs/{club}/competitions?phase=…  (completed = ended + finalized).
     *
     * @return array{data: list<array>, meta: array{next_cursor: ?string, per_page: int, phase: string}}
     */
    public function page(Club $club, User $user, string $phase, ?string $cursor, mixed $limit): array
    {
        $now   = now();
        $query = ClubCompetition::query()->select('club_competitions.*')->where('club_competitions.club_id', $club->id)
            ->withCount('competitionPacks');

        [$scope, $orders] = match ($phase) {
            'upcoming'  => ['upcoming', [['club_competitions.starts_at', 'asc'], ['club_competitions.id', 'asc']]],
            'completed' => ['completed', [['club_competitions.ends_at', 'desc'], ['club_competitions.id', 'desc']]],
            default     => ['live', [['club_competitions.ends_at', 'asc'], ['club_competitions.id', 'asc']]],
        };
        $query->{$scope}($now);

        $page  = ClubCursor::page($query, $orders, $cursor, ClubCursor::perPage($limit));
        $items = $page['items'];
        $me    = $this->standings->cardStandings($items, $user);

        return [
            'data' => $items->map(fn (ClubCompetition $c) => (new ClubCompetitionCardResource($c, $me[$c->id] ?? null))->resolve())->values()->all(),
            'meta' => ['next_cursor' => $page['next_cursor'], 'per_page' => $page['per_page'], 'phase' => $phase],
        ];
    }

    /** CompetitionCard of one competition with the viewer's standing. */
    public function card(ClubCompetition $competition, User $user): array
    {
        $competition->loadCount('competitionPacks');
        $me = $this->standings->cardStandings([$competition], $user);

        return (new ClubCompetitionCardResource($competition, $me[$competition->id] ?? null))->resolve();
    }

    /** GET /api/clubs/{club}/competitions/{competition}. */
    public function detail(Club $club, ClubCompetition $competition, User $user): array
    {
        $links    = ClubCompetitionPack::with(['pack' => fn ($q) => $q->withCount('challenges')->with('challenges')])
            ->where('club_competition_id', $competition->id)
            ->orderBy('sort_order')->orderBy('id')
            ->get();
        $attempts = ClubCompetitionAttempt::where('club_competition_id', $competition->id)->where('user_id', $user->id)->get()
            ->groupBy('challenge_pack_id');
        $results  = ClubCompetitionPackResult::where('club_competition_id', $competition->id)->where('user_id', $user->id)->get()
            ->keyBy('challenge_pack_id');
        $now      = now();

        $packs = $links->filter(fn (ClubCompetitionPack $l) => $l->pack !== null)->map(function (ClubCompetitionPack $link) use ($competition, $club, $user, $attempts, $results, $now) {
            /** @var ChallengePack $pack */
            $pack     = $link->pack;
            $snapshot = $link->challenge_ids;
            $count    = $snapshot !== null ? count($snapshot) : $pack->readyChallenges()->count();
            $playable = $snapshot !== null ? $count > 0 : ($pack->status === ChallengePack::STATUS_ACTIVE && $count > 0);

            return [
                'id'              => (int) $pack->id,
                'slug'            => $pack->slug,
                'name'            => $pack->name,
                'cover_image_url' => $pack->coverImageUrl(),
                'challenge_count' => $count,
                'state'           => $this->eligibility->packState(
                    $competition, $club, (int) $pack->id, $user,
                    $attempts->get($pack->id, collect()), $results->get($pack->id), $playable, $now,
                ),
            ];
        })->values()->all();

        return $this->card($competition, $user) + [
            'packs'               => $packs,
            'my_entry'            => $this->standings->myEntry($competition, $user),
            'leaderboard_preview' => $this->standings->preview($competition, $user),
        ];
    }

    /** GET …/competitions/{competition}/me/attempts — pack order, then attempt number. */
    public function myAttempts(ClubCompetition $competition, User $user): array
    {
        $order = ClubCompetitionPack::where('club_competition_id', $competition->id)->orderBy('sort_order')->orderBy('id')
            ->pluck('challenge_pack_id')->flip();

        return ClubCompetitionAttempt::with(['pack:id,slug'])
            ->where('club_competition_id', $competition->id)->where('user_id', $user->id)
            ->get()
            ->each(fn ($a) => $a->setRelation('competition', $competition))
            ->sortBy(fn ($a) => [$order[$a->challenge_pack_id] ?? PHP_INT_MAX, $a->attempt_number])
            ->values()
            ->map(fn ($a) => (new CompetitionAttemptResource($a))->resolve())
            ->all();
    }

    /**
     * GET /api/me/club-finishes — the player's own placements in finalized
     * competitions of non-archived clubs, newest first.
     */
    public function myFinishes(User $user): array
    {
        return ClubCompetitionFinish::query()
            ->with(['competition.club'])
            ->where('user_id', $user->id)
            ->whereHas('competition', fn ($q) => $q->where('state', CompetitionState::Finalized->value)
                ->whereHas('club', fn ($c) => $c->where('status', '!=', \App\Enums\ClubStatus::Archived->value)))
            ->get()
            ->sortByDesc(fn (ClubCompetitionFinish $f) => [$f->competition->finalized_at?->getTimestamp() ?? 0, $f->id])
            ->values()
            ->map(fn (ClubCompetitionFinish $f) => [
                'competition'   => ['id' => $f->competition->public_id, 'name' => $f->competition->name],
                'club'          => [
                    'id'       => $f->competition->club->public_id,
                    'name'     => $f->competition->club->name,
                    'logo_url' => $f->competition->club->logoUrl(),
                ],
                'placement'     => (int) $f->placement,
                'total_score'   => (int) $f->total_score,
                'total_players' => (int) $f->total_players,
                'finalized_at'  => $f->competition->finalized_at?->toISOString(),
            ])
            ->all();
    }
}
