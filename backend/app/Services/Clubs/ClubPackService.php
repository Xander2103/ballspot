<?php

namespace App\Services\Clubs;

use App\Enums\CompetitionState;
use App\Exceptions\ClubDomainException;
use App\Http\Resources\ChallengePackResource;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\PackAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Club packs on the member API (plan §5.1 normal track, contracts §5
 * ClubPackCard): which packs a member sees, their cards with the member's
 * normal-track progress, best score across both tracks and the competition
 * reservation flag, and the pack detail.
 *
 * Members see ACTIVE + PUBLIC-visibility packs of their own club only; a
 * draft/hidden/archived pack — or another club's pack — answers the uniform
 * 404 club_not_found. Cards are built in a fixed number of queries per page
 * (no N+1).
 */
class ClubPackService
{
    public const PREVIEW_SIZE = 3;

    public function __construct(private PackAccessResolver $access) {}

    /** Packs a member may see and play (normal track). */
    public function visiblePacks(Club $club): HasMany
    {
        return $club->packs()
            ->where('challenge_packs.status', ChallengePack::STATUS_ACTIVE)
            ->where('challenge_packs.visibility', ChallengePack::VISIBILITY_PUBLIC);
    }

    /** A pack bound by slug within the club must also be member-visible, else 404. */
    public function assertVisible(Club $club, ChallengePack $pack): ChallengePack
    {
        if ($pack->club_id !== $club->id
            || $pack->status !== ChallengePack::STATUS_ACTIVE
            || $pack->visibility !== ChallengePack::VISIBILITY_PUBLIC) {
            throw ClubDomainException::notFound();
        }
        $pack->setRelation('club', $club);

        return $pack;
    }

    /** @return array{items: list<array>, next_cursor: ?string, per_page: int} */
    public function page(Club $club, User $user, ?string $cursor, mixed $limit): array
    {
        $query = $this->visiblePacks($club)->getQuery()
            ->select('challenge_packs.*')
            ->with('sport')
            ->withCount('challenges');

        $page = ClubCursor::page($query, self::ORDER, $cursor, ClubCursor::perPage($limit));

        return ['items' => $this->cards($club, $user, $page['items'])] + $page;
    }

    /** The first packs of the list (club detail). */
    public function preview(Club $club, User $user): array
    {
        $packs = $this->visiblePacks($club)
            ->with('sport')
            ->withCount('challenges')
            ->orderByDesc('is_featured')->orderBy('sort_order')->orderByRaw('LOWER(name)')->orderBy('id')
            ->limit(self::PREVIEW_SIZE)
            ->get();

        return $this->cards($club, $user, $packs);
    }

    /** Count of member-visible packs. */
    public function visibleCount(Club $club): int
    {
        return $this->visiblePacks($club)->count();
    }

    private const ORDER = [
        ['challenge_packs.is_featured', 'desc'],
        ['challenge_packs.sort_order', 'asc'],
        ['LOWER(challenge_packs.name)', 'asc'],
        ['challenge_packs.id', 'asc'],
    ];

    /**
     * ClubPackCards: the public pack card fields + progress (normal track),
     * best_score (best attempt total over both tracks, voided competition
     * attempts excluded), reserved_for_competition and the linked
     * competitions that are currently published.
     *
     * @param Collection<int, ChallengePack> $packs with sport + challenges_count
     * @return list<array>
     */
    public function cards(Club $club, User $user, Collection $packs): array
    {
        if ($packs->isEmpty()) {
            return [];
        }
        $ids = $packs->pluck('id')->all();

        $latest = PackAttempt::where('user_id', $user->id)
            ->whereIn('challenge_pack_id', $ids)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get()
            ->unique('challenge_pack_id')
            ->keyBy('challenge_pack_id');

        $best   = $this->bestScores($user, $ids);
        $linked = $this->linkedCompetitions($club, $ids);

        return $packs->map(function (ChallengePack $pack) use ($latest, $best, $linked) {
            $row     = (new ChallengePackResource($pack))->resolve();
            $attempt = $latest->get($pack->id);
            $comps   = $linked->get($pack->id, collect());

            $row['progress'] = $attempt ? [
                'status'           => $attempt->status,
                'completed_count'  => min($attempt->current_index, $attempt->totalChallenges()),
                'total_challenges' => $attempt->totalChallenges(),
                'total_score'      => (int) $attempt->total_score,
            ] : null;
            $row['best_score']               = $best[$pack->id] ?? null;
            $row['reserved_for_competition'] = $comps->contains(fn (ClubCompetition $c) => $c->blocksNormalPlay());
            $row['competitions']             = $comps->map(fn (ClubCompetition $c) => self::competitionRef($c))->values()->all();

            return $row;
        })->values()->all();
    }

    /**
     * GET /api/clubs/{club}/packs/{slug}: exactly the public pack detail
     * payload + reserved_for_competition + competitions. While the pack is
     * reserved for a competition the challenge list is empty, so the photos
     * cannot be previewed outside the competition (plan §5.2).
     */
    public function detail(Club $club, ChallengePack $pack): array
    {
        $pack->loadMissing(['sport', 'challenges' => fn ($q) => $q->with('sport', 'category')]);

        $payload  = ChallengePackResource::detail($pack);
        $reserved = $this->access->isReservedForCompetition($pack);

        if ($reserved) {
            $payload['challenges'] = [];
        }
        $payload['reserved_for_competition'] = $reserved;
        $payload['competitions'] = $this->linkedCompetitions($club, [$pack->id])
            ->get($pack->id, collect())
            ->map(fn (ClubCompetition $c) => self::competitionRef($c))
            ->values()
            ->all();

        return $payload;
    }

    /** @return array<int, int> pack id => best attempt total (null when never scored) */
    public function bestScores(User $user, array $packIds): array
    {
        $normal = PackAttempt::where('user_id', $user->id)
            ->whereIn('challenge_pack_id', $packIds)
            ->where('current_index', '>', 0)
            ->groupBy('challenge_pack_id')
            ->selectRaw('challenge_pack_id, MAX(total_score) as best')
            ->pluck('best', 'challenge_pack_id');

        $competition = ClubCompetitionAttempt::query()
            ->join('club_competitions', 'club_competitions.id', '=', 'club_competition_attempts.club_competition_id')
            ->where('club_competition_attempts.user_id', $user->id)
            ->whereIn('club_competition_attempts.challenge_pack_id', $packIds)
            ->where('club_competition_attempts.status', '!=', \App\Enums\CompetitionAttemptStatus::Voided->value)
            ->where('club_competition_attempts.guesses_count', '>', 0)
            ->where('club_competitions.state', '!=', CompetitionState::Voided->value)
            ->groupBy('club_competition_attempts.challenge_pack_id')
            ->selectRaw('club_competition_attempts.challenge_pack_id as pack_id, MAX(club_competition_attempts.total_score) as best')
            ->pluck('best', 'pack_id');

        $out = [];
        foreach ($packIds as $id) {
            $values = array_filter([$normal[$id] ?? null, $competition[$id] ?? null], fn ($v) => $v !== null);
            if ($values !== []) {
                $out[$id] = (int) max($values);
            }
        }

        return $out;
    }

    /**
     * Published competitions (phase upcoming / active / ended) linking the
     * packs, grouped by pack id. Drafts, cancelled, voided and finalized
     * competitions are never listed.
     *
     * @return Collection<int, Collection<int, ClubCompetition>>
     */
    public function linkedCompetitions(Club $club, array $packIds): Collection
    {
        return ClubCompetition::query()
            ->join('club_competition_packs', 'club_competition_packs.club_competition_id', '=', 'club_competitions.id')
            ->where('club_competitions.club_id', $club->id)
            ->where('club_competitions.state', CompetitionState::Published->value)
            ->whereIn('club_competition_packs.challenge_pack_id', $packIds)
            ->orderBy('club_competitions.starts_at')
            ->orderBy('club_competitions.id')
            ->select('club_competitions.*', 'club_competition_packs.challenge_pack_id as linked_pack_id')
            ->get()
            ->groupBy('linked_pack_id');
    }

    /** @return array{id: string, name: string, phase: string} */
    public static function competitionRef(ClubCompetition $competition): array
    {
        return [
            'id'    => $competition->public_id,
            'name'  => $competition->name,
            'phase' => $competition->phase()->value,
        ];
    }
}
