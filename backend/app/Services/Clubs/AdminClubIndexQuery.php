<?php

namespace App\Services\Clubs;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Enums\ClubType;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubInviteCode;
use App\Models\ClubMember;
use App\Models\ClubPhotoSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Central admin Clubs index (plan §8.2): server-side search, filters, sort and
 * page size. Everything is query-string driven so a filtered view can be
 * bookmarked and paging keeps the filters.
 *
 * Search `q` matches (OR):
 *  - name / city LIKE %q%            - slug prefix (q%)
 *  - exact numeric id                - exact public_id (ULID)
 *  - exact external_ref              - a normalised invite code (any state)
 *  - exact username / email of an active owner
 *
 * The page query is bounded: counters are denormalised on `clubs`, the sport
 * and active owners are eager loaded, the open-photo count is a single
 * correlated sub-select. No per-row queries.
 */
class AdminClubIndexQuery
{
    public const SORTS     = ['activity', 'name', 'created', 'members'];
    public const PER_PAGES = [25, 50, 100];

    public const FILTER_DEFAULTS = [
        'q'                      => '',
        'status'                 => [],
        'sport'                  => '',
        'club_type'              => '',
        'country'                => '',
        'has_active_competition' => false,
        'has_pending_photos'     => false,
        'needs_owner'            => false,
        'created_from'           => '',
        'created_to'             => '',
        'sort'                   => 'activity',
        'per_page'               => 25,
    ];

    public function __construct(private ClubInviteCodeService $codes) {}

    /** Only known keys with valid values; everything else falls back to the default. */
    public function normalize(array $input): array
    {
        $f = self::FILTER_DEFAULTS;

        $q = $input['q'] ?? '';
        $f['q'] = is_scalar($q) ? mb_substr(trim((string) $q), 0, 100) : '';

        $status = $input['status'] ?? [];
        $status = is_array($status) ? $status : [$status];
        $f['status'] = array_values(array_intersect(ClubStatus::values(), array_map('strval', array_filter($status, 'is_scalar'))));

        $sport = $input['sport'] ?? '';
        $f['sport'] = is_scalar($sport) && ctype_digit((string) $sport) ? (string) $sport : '';

        $type = $input['club_type'] ?? '';
        $f['club_type'] = is_scalar($type) && ClubType::tryFrom((string) $type) ? (string) $type : '';

        $country = $input['country'] ?? '';
        $f['country'] = is_scalar($country) && preg_match('/^[A-Za-z]{2}$/', (string) $country) ? strtoupper((string) $country) : '';

        foreach (['has_active_competition', 'has_pending_photos', 'needs_owner'] as $flag) {
            $f[$flag] = in_array((string) ($input[$flag] ?? ''), ['1', 'true', 'on', 'yes'], true);
        }

        foreach (['created_from', 'created_to'] as $d) {
            $v = $input[$d] ?? '';
            $f[$d] = is_scalar($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
        }

        $sort = $input['sort'] ?? '';
        $f['sort'] = in_array($sort, self::SORTS, true) ? $sort : self::FILTER_DEFAULTS['sort'];

        $per = (int) ($input['per_page'] ?? 0);
        $f['per_page'] = in_array($per, self::PER_PAGES, true) ? $per : self::FILTER_DEFAULTS['per_page'];

        return $f;
    }

    /** True when any filter other than sort/page size narrows the list. */
    public function isFiltered(array $f): bool
    {
        return $f['q'] !== '' || $f['status'] !== [] || $f['sport'] !== '' || $f['club_type'] !== ''
            || $f['country'] !== '' || $f['has_active_competition'] || $f['has_pending_photos'] || $f['needs_owner']
            || $f['created_from'] !== '' || $f['created_to'] !== '';
    }

    public function query(array $filters): Builder
    {
        $f = $this->normalize($filters);
        $now = now();

        $query = Club::query()
            ->with([
                'sport:id,name,emoji',
                'owners' => fn ($q) => $q->select('id', 'club_id', 'user_id', 'role', 'status')->orderBy('id'),
                'owners.user:id,username,name',
            ])
            ->withCount(['photoSubmissions as open_photos_count' => fn ($q) => $q->open()]);

        if ($f['q'] !== '') {
            $this->applySearch($query, $f['q']);
        }

        $query
            ->when($f['status'] !== [], fn ($q) => $q->whereIn('status', $f['status']))
            ->when($f['sport'] !== '', fn ($q) => $q->where('sport_id', (int) $f['sport']))
            ->when($f['club_type'] !== '', fn ($q) => $q->where('club_type', $f['club_type']))
            ->when($f['country'] !== '', fn ($q) => $q->where('country_code', $f['country']))
            ->when($f['needs_owner'], fn ($q) => $q->needsOwner())
            ->when($f['has_active_competition'], fn ($q) => $q->whereExists(
                ClubCompetition::query()->live($now)->select(DB::raw(1))->whereColumn('club_competitions.club_id', 'clubs.id')->getQuery()
            ))
            ->when($f['has_pending_photos'], fn ($q) => $q->whereExists(
                ClubPhotoSubmission::query()->open()->select(DB::raw(1))->whereColumn('club_photo_submissions.club_id', 'clubs.id')->getQuery()
            ))
            ->when($f['created_from'] !== '', fn ($q) => $q->whereDate('created_at', '>=', $f['created_from']))
            ->when($f['created_to'] !== '', fn ($q) => $q->whereDate('created_at', '<=', $f['created_to']));

        match ($f['sort']) {
            'name'    => $query->orderBy('name')->orderBy('id'),
            'created' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'members' => $query->orderByDesc('members_count')->orderBy('name')->orderBy('id'),
            // Most recently active first; never-active clubs last, newest first.
            default   => $query->orderByRaw('CASE WHEN last_activity_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('last_activity_at')->orderByDesc('created_at')->orderByDesc('id'),
        };

        return $query;
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $f = $this->normalize($filters);

        return $this->query($f)->paginate($f['per_page'])->withQueryString();
    }

    /**
     * Counts for the status segments (one grouped query + one needs_owner count).
     *
     * @return array{all:int, active:int, suspended:int, archived:int, needs_owner:int}
     */
    public function statusCounts(): array
    {
        $by = Club::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'all'         => (int) $by->sum(),
            'active'      => (int) ($by[ClubStatus::Active->value] ?? 0),
            'suspended'   => (int) ($by[ClubStatus::Suspended->value] ?? 0),
            'archived'    => (int) ($by[ClubStatus::Archived->value] ?? 0),
            'needs_owner' => Club::query()->needsOwner()->count(),
        ];
    }

    /** Country codes present in the data (filter options). */
    public function countryOptions(): array
    {
        return Club::query()->whereNotNull('country_code')->distinct()->orderBy('country_code')->pluck('country_code')->all();
    }

    // ------------------------------------------------------------------

    private function applySearch(Builder $query, string $q): void
    {
        $like       = '%' . self::escapeLike($q) . '%';
        $prefix     = self::escapeLike(mb_strtolower($q)) . '%';
        $normalised = $this->codes->normalise($q);
        $isCode     = strlen($normalised) >= 6 && strlen($normalised) <= 16 && preg_match('/^[A-Z0-9]+$/', $normalised);
        $identities = array_values(array_unique([$q, mb_strtolower($q)]));

        $query->where(function (Builder $w) use ($q, $like, $prefix, $normalised, $isCode, $identities) {
            // ESCAPE '!' works the same on SQLite and MySQL.
            $w->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("city LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$prefix])
                ->orWhere('external_ref', $q);

            if (ctype_digit($q) && strlen($q) <= 18) {
                $w->orWhere('id', (int) $q);
            }
            if (preg_match('/^[0-9A-Za-z]{26}$/', $q)) {
                $w->orWhere('public_id', strtoupper($q));
            }
            if ($isCode) {
                $w->orWhereIn('id', ClubInviteCode::query()->select('club_id')->where('code', $normalised));
            }

            // Active owners by exact username / email.
            $w->orWhereIn('id', ClubMember::query()
                ->select('club_members.club_id')
                ->join('users', 'users.id', '=', 'club_members.user_id')
                ->where('club_members.status', ClubMemberStatus::Active->value)
                ->where('club_members.role', ClubRole::Owner->value)
                ->where(fn ($u) => $u->whereIn('users.username', $identities)->orWhereIn('users.email', $identities)));
        });
    }

    /** Escape LIKE wildcards with `!` (used with ESCAPE '!'). */
    public static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
