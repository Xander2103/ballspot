<?php

namespace App\Services\Clubs;

use App\Models\ClubAuditEvent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Audit trail reads for the central admin: the club Audit tab and the global
 * audit log (plan §7.2 `GET /admin/club-audit`). Filters: action (allow-listed),
 * actor (exact username, email or numeric user id), date range, club (global
 * page only). Newest first, page-paginated, actor + club eager loaded.
 */
class AdminClubAuditQuery
{
    public const PER_PAGE = 25;

    public function normalize(array $input): array
    {
        $action = (string) (is_scalar($input['action'] ?? null) ? $input['action'] : '');
        $actor  = is_scalar($input['actor'] ?? null) ? mb_substr(trim((string) $input['actor']), 0, 120) : '';
        $club   = is_scalar($input['club'] ?? null) && ctype_digit((string) $input['club']) ? (string) $input['club'] : '';
        $kind   = (string) (is_scalar($input['actor_kind'] ?? null) ? $input['actor_kind'] : '');

        $f = [
            'action'     => in_array($action, ClubAuditEvent::ACTIONS, true) ? $action : '',
            'actor'      => $actor,
            'actor_kind' => in_array($kind, ClubAuditEvent::ACTOR_KINDS, true) ? $kind : '',
            'club'       => $club,
            'from'       => '',
            'to'         => '',
        ];
        foreach (['from', 'to'] as $d) {
            $v = $input[$d] ?? '';
            $f[$d] = is_scalar($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
        }

        return $f;
    }

    public function isFiltered(array $f): bool
    {
        return $f['action'] !== '' || $f['actor'] !== '' || $f['actor_kind'] !== '' || $f['club'] !== '' || $f['from'] !== '' || $f['to'] !== '';
    }

    public function query(array $filters, ?int $clubId = null): Builder
    {
        $f = $this->normalize($filters);

        return ClubAuditEvent::query()
            ->with(['actor:id,username,name', 'club:id,name,slug'])
            ->when($clubId !== null, fn ($q) => $q->where('club_id', $clubId))
            ->when($clubId === null && $f['club'] !== '', fn ($q) => $q->where('club_id', (int) $f['club']))
            ->when($f['action'] !== '', fn ($q) => $q->where('action', $f['action']))
            ->when($f['actor_kind'] !== '', fn ($q) => $q->where('actor_kind', $f['actor_kind']))
            ->when($f['actor'] !== '', function ($q) use ($f) {
                $actor = $f['actor'];
                if (ctype_digit($actor)) {
                    return $q->where('actor_user_id', (int) $actor);
                }
                $values = array_values(array_unique([$actor, mb_strtolower($actor)]));

                return $q->whereIn('actor_user_id', User::query()->select('id')
                    ->where(fn ($u) => $u->whereIn('username', $values)->orWhereIn('email', $values)));
            })
            ->when($f['from'] !== '', fn ($q) => $q->where('created_at', '>=', $f['from'] . ' 00:00:00'))
            ->when($f['to'] !== '', fn ($q) => $q->where('created_at', '<=', $f['to'] . ' 23:59:59'))
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    public function paginate(array $filters, ?int $clubId = null, string $pageName = 'page'): LengthAwarePaginator
    {
        return $this->query($filters, $clubId)->paginate(self::PER_PAGE, ['*'], $pageName)->withQueryString();
    }
}
