<?php

namespace App\Http\Resources\Clubs;

use App\Models\Club;
use App\Models\ClubMember;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ClubCard (contracts §5): an item of My Clubs, the join response and the
 * base of the club detail. The club is addressed by its public ULID only;
 * contact fields, internal ids and invite codes are never part of it.
 *
 * Expects the club with `sport` and the MyClubsQuery card counts loaded
 * (card_packs_count, card_active_competitions_count,
 * card_upcoming_competitions_count) and the caller's membership.
 *
 * @property Club $resource
 */
class ClubCardResource extends JsonResource
{
    public function __construct(Club $club, private ClubMember $membership)
    {
        parent::__construct($club);
    }

    public function toArray($request): array
    {
        $club = $this->resource;

        return [
            'id'           => $club->public_id,
            'name'         => $club->name,
            'slug'         => $club->slug,
            'logo_url'     => $club->logoUrl(),
            'city'         => $club->city,
            'country_code' => $club->country_code,
            'club_type'    => $club->club_type?->value,
            'sport'        => $club->sport ? [
                'slug'  => $club->sport->slug,
                'name'  => $club->sport->name,
                'emoji' => $club->sport->emoji,
            ] : null,
            'role'         => $this->membership->role->value,
            'status'       => $club->status->value,
            'packs_count'  => (int) ($club->card_packs_count ?? 0),
            'competitions' => [
                'active'   => (int) ($club->card_active_competitions_count ?? 0),
                'upcoming' => (int) ($club->card_upcoming_competitions_count ?? 0),
            ],
            'joined_at'    => $this->membership->joined_at?->toISOString(),
        ];
    }
}
