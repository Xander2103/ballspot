<?php

namespace App\Services\Clubs;

use App\Enums\ClubMemberStatus;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\User;
use App\Services\ClubPortal\ClubPortalMemberRules;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/clubs/{club}/members: the club's ACTIVE members, owners first,
 * then admins, then members, each group by username. Usernames + avatars +
 * role + joined_at only (D12) — never emails, removal reasons or codes.
 * Visibility (D9) is checked by the controller (`viewMembers`).
 *
 * Wave F: every item also carries `actions: {change_role, remove, ban}` for
 * the caller — ClubPortalMemberRules (the portal's rules) AND the ClubPolicy
 * ability (manageMembers / transferOwnership, which also require an ACTIVE
 * club), so a suspended club or a plain member gets all false.
 */
class ClubMemberListQuery
{
    private const ORDER = [
        ["CASE club_members.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END", 'asc'],
        ['LOWER(users.username)', 'asc'],
        ['club_members.id', 'asc'],
    ];

    /** @return array{items: list<array>, next_cursor: ?string, per_page: int, total: int} */
    public function page(Club $club, ?string $q, ?string $cursor, mixed $limit, ?User $viewer = null): array
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
            $base->select('club_members.id', 'club_members.club_id', 'club_members.user_id', 'club_members.role', 'club_members.status', 'club_members.joined_at', 'users.username', 'users.avatar_path'),
            self::ORDER,
            $cursor,
            ClubCursor::perPage($limit),
        );

        $abilities = self::callerAbilities($club, $viewer);

        $items = $page['items']->map(fn (ClubMember $m) => self::item(
            $m, $m->getAttribute('username'), $m->getAttribute('avatar_path'), $abilities,
        ))->values()->all();

        return ['items' => $items, 'next_cursor' => $page['next_cursor'], 'per_page' => $page['per_page'], 'total' => $total];
    }

    /**
     * The caller's membership + policy abilities, resolved once per list.
     *
     * @return array{actor: ?ClubMember, manage_members: bool, change_roles: bool}
     */
    public static function callerAbilities(Club $club, ?User $viewer): array
    {
        if ($viewer === null) {
            return ['actor' => null, 'manage_members' => false, 'change_roles' => false];
        }
        $gate = Gate::forUser($viewer);

        return [
            'actor'          => app(ClubMembershipService::class)->membership($club, $viewer),
            'manage_members' => $gate->allows('manageMembers', $club),
            'change_roles'   => $gate->allows('transferOwnership', $club),
        ];
    }

    /** One Member item (list + PATCH response). */
    public static function item(ClubMember $m, ?string $username, ?string $avatarPath, array $abilities): array
    {
        return [
            'user_id'    => (int) $m->user_id,
            'username'   => $username,
            'avatar_url' => $avatarPath ? asset('storage/' . $avatarPath) : null,
            'role'       => $m->role->value,
            'joined_at'  => $m->joined_at?->toISOString(),
            'actions'    => self::actions($m, $abilities),
        ];
    }

    /** @return array{change_role: bool, remove: bool, ban: bool} */
    public static function actions(ClubMember $target, array $abilities): array
    {
        $actor = $abilities['actor'] ?? null;
        if (!$actor instanceof ClubMember) {
            return ['change_role' => false, 'remove' => false, 'ban' => false];
        }

        return [
            'change_role' => ($abilities['change_roles'] ?? false) && ClubPortalMemberRules::allows($actor, $target, ClubPortalMemberRules::ROLE),
            'remove'      => ($abilities['manage_members'] ?? false) && ClubPortalMemberRules::allows($actor, $target, ClubPortalMemberRules::REMOVE),
            'ban'         => ($abilities['manage_members'] ?? false) && ClubPortalMemberRules::allows($actor, $target, ClubPortalMemberRules::BAN),
        ];
    }
}
