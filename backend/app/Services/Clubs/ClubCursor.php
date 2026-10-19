<?php

namespace App\Services\Clubs;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Keyset ("cursor") pagination for the member API lists (contracts §5:
 * deterministic order = sort key(s) + id, opaque `next_cursor`, `limit`
 * clamped to ballspot.clubs.pagination.max).
 *
 * Laravel's cursorPaginate() cannot page on SQL expressions (COALESCE,
 * LOWER, CASE), which My Clubs' "recent" sort and the member list need, so
 * this helper implements the same keyset idea for raw order expressions:
 * every order expression is also selected as `_k{i}` and the cursor stores
 * the last row's values. Pages never duplicate or skip rows while the set is
 * stable, and stay cheap at any depth (no OFFSET).
 *
 * Order expressions must be trusted SQL (never user input), must never be
 * NULL, and must end with a unique column (the row id) so the order is
 * total. Select the row's columns before calling page() — it adds the
 * `_k{i}` key columns with selectRaw().
 */
final class ClubCursor
{
    /** Clamp `limit` to [1, pagination.max]; default pagination.default. */
    public static function perPage(mixed $limit): int
    {
        $default = (int) config('ballspot.clubs.pagination.default', 20);
        $max     = (int) config('ballspot.clubs.pagination.max', 50);

        $value = $limit === null || $limit === '' ? $default : (int) $limit;

        return max(1, min($max, $value));
    }

    /**
     * Fetch one page.
     *
     * @param  list<array{0: string, 1: 'asc'|'desc'}>  $orders  [sqlExpression, direction]
     * @return array{items: Collection, next_cursor: ?string, per_page: int}
     */
    public static function page(BuilderContract $query, array $orders, ?string $cursor, int $perPage): array
    {
        $after = self::decode($cursor, count($orders));

        foreach ($orders as $i => [$expr, $dir]) {
            $query->selectRaw("{$expr} as _k{$i}");
        }

        if ($after !== null) {
            $query->where(function ($w) use ($orders, $after) {
                foreach ($orders as $i => [$expr, $dir]) {
                    $w->orWhere(function ($branch) use ($orders, $after, $i, $expr, $dir) {
                        for ($j = 0; $j < $i; $j++) {
                            $branch->whereRaw("{$orders[$j][0]} = ?", [$after[$j]]);
                        }
                        $branch->whereRaw("{$expr} " . ($dir === 'desc' ? '<' : '>') . ' ?', [$after[$i]]);
                    });
                }
            });
        }

        foreach ($orders as [$expr, $dir]) {
            $query->orderByRaw("{$expr} " . ($dir === 'desc' ? 'desc' : 'asc'));
        }

        $rows = collect($query->limit($perPage + 1)->get());
        $more = $rows->count() > $perPage;
        $items = $rows->take($perPage)->values();

        $next = null;
        if ($more && ($last = $items->last()) !== null) {
            $values = [];
            foreach (array_keys($orders) as $i) {
                $values[] = data_get($last, "_k{$i}");
            }
            $next = self::encode($values);
        }

        return ['items' => $items, 'next_cursor' => $next, 'per_page' => $perPage];
    }

    /** Opaque cursor for an offset into an in-memory ranking (leaderboards). */
    public static function encodeOffset(int $offset): string
    {
        return self::encode(['o' => $offset]);
    }

    public static function decodeOffset(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        $data = self::decodeRaw($cursor);
        if (!is_array($data) || !isset($data['o']) || !is_int($data['o']) || $data['o'] < 0) {
            throw self::invalid();
        }

        return $data['o'];
    }

    public static function encode(array $values): string
    {
        return rtrim(strtr(base64_encode(json_encode($values)), '+/', '-_'), '=');
    }

    /** @return list<int|string|float|null>|null */
    private static function decode(?string $cursor, int $count): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $data = self::decodeRaw($cursor);
        if (!is_array($data) || !array_is_list($data) || count($data) !== $count) {
            throw self::invalid();
        }
        foreach ($data as $value) {
            if (!is_scalar($value) && $value !== null) {
                throw self::invalid();
            }
        }

        return $data;
    }

    private static function decodeRaw(string $cursor): mixed
    {
        if (strlen($cursor) > 512) {
            throw self::invalid();
        }
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);

        return $json === false ? null : json_decode($json, true);
    }

    private static function invalid(): ValidationException
    {
        return ValidationException::withMessages(['cursor' => 'The cursor is invalid or expired. Reload the list.']);
    }
}
