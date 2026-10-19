<?php

namespace App\Services\Clubs;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubStatus;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * My Clubs (GET /api/clubs, plan §9.2, contracts §5).
 *
 * Only the caller's ACTIVE memberships of active or suspended clubs (D18:
 * archived clubs are hidden). `q` matches club name/city with LIKE — always
 * inside the memberships, so it can never reveal a club the caller has not
 * joined, and it stays fast however many clubs exist (bounded by the
 * club_members.user_id index). Keyset pagination: `recent` =
 * last_seen_at/joined_at desc, `name` = name asc; both end on the membership
 * id so the order is total.
 */
class MyClubsQuery
{
    public const SORT_RECENT = 'recent';
    public const SORT_NAME   = 'name';
    public const SORTS       = [self::SORT_RECENT, self::SORT_NAME];

    public const MAX_QUERY_LENGTH = 60;

    /**
     * @return array{items: Collection<int, ClubMember>, next_cursor: ?string, per_page: int, total_joined: int, query: ?string}
     *         items have `club` loaded with sport + card counts
     */
    public function page(User $user, ?string $q, ?string $sort, ?string $cursor, mixed $limit): array
    {
        $query = self::normaliseQuery($q);
        $sort  = in_array($sort, self::SORTS, true) ? $sort : self::SORT_RECENT;

        $base = ClubMember::query()
            ->join('clubs', 'clubs.id', '=', 'club_members.club_id')
            ->where('club_members.user_id', $user->id)
            ->where('club_members.status', ClubMemberStatus::Active->value)
            ->whereIn('clubs.status', [ClubStatus::Active->value, ClubStatus::Suspended->value]);

        $totalJoined = (clone $base)->count();

        if ($query !== null) {
            $like = '%' . self::escapeLike($query) . '%';
            $base->where(fn ($w) => $w
                ->whereRaw("clubs.name LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("clubs.city LIKE ? ESCAPE '!'", [$like]));
        }

        $orders = $sort === self::SORT_NAME
            ? [['LOWER(clubs.name)', 'asc'], ['club_members.id', 'asc']]
            : [['COALESCE(club_members.last_seen_at, club_members.joined_at, club_members.created_at)', 'desc'], ['club_members.id', 'desc']];

        $page = ClubCursor::page($base->select('club_members.*'), $orders, $cursor, ClubCursor::perPage($limit));

        $clubs = self::withCardCounts(Club::query()->with('sport'))
            ->whereIn('id', $page['items']->pluck('club_id')->unique()->values())
            ->get()
            ->keyBy('id');

        foreach ($page['items'] as $member) {
            $member->setRelation('club', $clubs->get($member->club_id));
        }

        return $page + ['total_joined' => $totalJoined, 'query' => $query];
    }

    /** Trimmed, ≤ 60 chars, null when empty. */
    public static function normaliseQuery(?string $q): ?string
    {
        $q = trim((string) $q);

        return $q === '' ? null : mb_substr($q, 0, self::MAX_QUERY_LENGTH);
    }

    /** LIKE pattern escaping with `!` as the escape character (portable SQLite/MySQL). */
    public static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * The counts every ClubCard shows (AT-MY-04): active member-visible packs,
     * published competitions in phase active / upcoming. One query per page.
     */
    public static function withCardCounts(Builder $clubs): Builder
    {
        $now = now();

        return $clubs->withCount([
            'packs as card_packs_count' => fn ($q) => $q
                ->where('status', ChallengePack::STATUS_ACTIVE)
                ->where('visibility', ChallengePack::VISIBILITY_PUBLIC),
            'competitions as card_active_competitions_count'   => fn ($q) => $q->live($now),
            'competitions as card_upcoming_competitions_count' => fn ($q) => $q->upcoming($now),
        ]);
    }

    /** Reload one club with its card counts (join / detail). */
    public static function loadCard(Club $club): Club
    {
        $fresh = self::withCardCounts(Club::query()->with('sport'))->whereKey($club->id)->firstOrFail();

        $keys = ['card_packs_count', 'card_active_competitions_count', 'card_upcoming_competitions_count'];
        foreach ($keys as $key) {
            $club->setAttribute($key, $fresh->getAttribute($key));
        }
        // Read-only extras: never dirty, so a later save() ignores them.
        $club->syncOriginalAttributes($keys);
        $club->setRelation('sport', $fresh->sport);

        return $club;
    }
}
