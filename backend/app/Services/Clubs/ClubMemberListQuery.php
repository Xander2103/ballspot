<?php

namespace App\Services\Clubs;

use App\Enums\ClubMemberStatus;
use App\Models\Club;
use App\Models\ClubMember;

/**
 * GET /api/clubs/{club}/members: the club's ACTIVE members, owners first,
 * then admins, then members, each group by username. Usernames + avatars +
 * role + joined_at only (D12) — never emails, removal reasons or codes.
 * Visibility (D9) is checked by the controller (`viewMembers`).
 */
class ClubMemberListQuery
{
    private const ORDER = [
        ["CASE club_members.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END", 'asc'],
        ['LOWER(users.username)', 'asc'],
        ['club_members.id', 'asc'],
    ];

    /** @return array{items: list<array>, next_cursor: ?string, per_page: int, total: int} */
    public function page(Club $club, ?string $q, ?string $cursor, mixed $limit): array
    {
        $base = ClubMember::query()
            ->join('users', 'users.id', '=', 'club_members.user_id')
            ->where('club_members.club_id', $club->id)
            ->where('club_members.status', ClubMemberStatus::Active->value);

        $total = (clone $base)->count();

        $query = MyClubsQuery::normaliseQuery($q);
        if ($query !== null) {
            $base->whereRaw("users.username LIKE ? ESCAPE '!'", ['%' . MyClubsQuery::escapeLike($query) . '%']);
        }

        $page = ClubCursor::page(
            $base->select('club_members.id', 'club_members.user_id', 'club_members.role', 'club_members.joined_at', 'users.username', 'users.avatar_path'),
            self::ORDER,
            $cursor,
            ClubCursor::perPage($limit),
        );

        $items = $page['items']->map(fn (ClubMember $m) => [
            'user_id'    => (int) $m->user_id,
            'username'   => $m->getAttribute('username'),
            'avatar_url' => $m->getAttribute('avatar_path') ? asset('storage/' . $m->getAttribute('avatar_path')) : null,
            'role'       => $m->role->value,
            'joined_at'  => $m->joined_at?->toISOString(),
        ])->values()->all();

        return ['items' => $items, 'next_cursor' => $page['next_cursor'], 'per_page' => $page['per_page'], 'total' => $total];
    }
}
