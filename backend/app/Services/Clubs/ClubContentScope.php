<?php

namespace App\Services\Clubs;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\Club;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Club scope of the existing admin content screens (plan §6.5 G6, §4.8).
 *
 * The `club` query parameter of the challenge, pack and media-export screens:
 *   ''     (absent) → BallPicker content only (club_id NULL) — the default
 *   'all'           → content of every club
 *   '<id>'          → content of that one club
 *
 * Pack invariants (enforced server-side on every save):
 *   - a public pack contains BallPicker challenges only;
 *   - a club pack contains its own club's challenges plus BallPicker
 *     challenges (D4) — never another club's. Club challenges never become
 *     public.
 */
class ClubContentScope
{
    public const BALLPICKER = '';
    public const ALL_CLUBS  = 'all';

    /** '' | 'all' | numeric club id (as string). Anything else → ''. */
    public function normalize(mixed $value): string
    {
        if (!is_scalar($value)) {
            return self::BALLPICKER;
        }
        $value = trim((string) $value);

        return match (true) {
            $value === self::ALL_CLUBS                    => self::ALL_CLUBS,
            ctype_digit($value) && strlen($value) <= 18   => $value,
            default                                       => self::BALLPICKER,
        };
    }

    /** Apply the scope to a challenges or challenge_packs query. */
    public function apply(Builder $query, string $scope, string $column = 'club_id'): Builder
    {
        $column = $query->qualifyColumn($column);

        return match (true) {
            $scope === self::ALL_CLUBS => $query->whereNotNull($column),
            $scope !== self::BALLPICKER => $query->where($column, (int) $scope),
            default                     => $query->whereNull($column),
        };
    }

    /** The selected club for a numeric scope, else null. */
    public function club(string $scope): ?Club
    {
        return ctype_digit($scope) ? Club::find((int) $scope) : null;
    }

    /** Options for the scope selector (id, name, status), name order. */
    public function clubOptions(): Collection
    {
        return Club::query()->orderBy('name')->get(['id', 'name', 'status']);
    }

    /**
     * Challenges a pack may contain: public pack → BallPicker only; club pack
     * → its club's challenges first, then BallPicker challenges (D4).
     */
    public function eligibleChallenges(ChallengePack $pack): Builder
    {
        $query = Challenge::query();

        if ($pack->club_id === null) {
            return $query->whereNull('club_id');
        }

        return $query->where(fn (Builder $q) => $q->where('club_id', $pack->club_id)->orWhereNull('club_id'));
    }

    /**
     * Refuse challenge ids that break the pack invariant (or do not exist)
     * with a validation error on `challenges`.
     *
     * @param array<int, int|string> $ids
     */
    public function assertPackChallenges(ChallengePack $pack, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn ($id) => is_scalar($id) && ctype_digit((string) $id)))));
        if ($ids === []) {
            return;
        }

        $allowed = $this->eligibleChallenges($pack)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $refused = array_diff($ids, $allowed);

        if ($refused !== []) {
            throw ValidationException::withMessages([
                'challenges' => $pack->club_id === null
                    ? 'A BallPicker pack can only contain BallPicker challenges — club challenges stay private to their club.'
                    : 'A club pack can only contain challenges of its own club or BallPicker challenges.',
            ]);
        }
    }
}
