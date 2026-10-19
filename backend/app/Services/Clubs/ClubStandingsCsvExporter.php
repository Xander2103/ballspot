<?php

namespace App\Services\Clubs;

use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV download of a club's all-time leaderboard or of one competition's
 * standings (Wave F, plan §10 "CSV export (usernames only)"), for the club
 * portal and the central admin.
 *
 *  - Columns: rank, username, total_score, packs_completed, attempts_used —
 *    the rows the boards already show (ClubLeaderboardService::standings /
 *    ClubStandingsService::ranked: active, non-anonymized members only).
 *    Never an email, user id or any other personal field.
 *  - Spreadsheet formula injection: a cell starting with = + - @ (or a tab /
 *    carriage return) is prefixed with an apostrophe.
 *  - Filename: ASCII slug + date, so Content-Disposition is always safe.
 *  - Audit `export.downloaded` (ids + counts only) before streaming.
 */
class ClubStandingsCsvExporter
{
    public const COLUMNS = ['rank', 'username', 'total_score', 'packs_completed', 'attempts_used'];

    public const SOURCE_LEADERBOARD = 'leaderboard';
    public const SOURCE_COMPETITION = 'competition';

    public function __construct(
        private ClubLeaderboardService $leaderboard,
        private ClubStandingsService $standings,
        private ClubAuditLogger $audit,
    ) {}

    public function leaderboard(Club $club, User $actor, string $actorKind): StreamedResponse
    {
        $rows = $this->leaderboard->standings($club);

        $this->audit->log($club, 'export.downloaded', $actor, null, [
            'export' => self::SOURCE_LEADERBOARD,
            'count'  => $rows->count(),
        ], $actorKind);

        return $this->stream($rows, $this->filename([$club->slug, 'leaderboard']));
    }

    public function competition(ClubCompetition $competition, User $actor, string $actorKind): StreamedResponse
    {
        $rows = $this->standings->ranked($competition);
        $club = $competition->club;

        $this->audit->log($club, 'export.downloaded', $actor, $competition, [
            'export'         => self::SOURCE_COMPETITION,
            'competition_id' => $competition->id,
            'count'          => $rows->count(),
        ], $actorKind);

        return $this->stream($rows, $this->filename([$club->slug, $competition->name, 'standings']));
    }

    /** The CSV body (also used by tests). */
    public static function toCsv(iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(
                fn (string $column) => self::cell($row[$column] ?? ''),
                self::COLUMNS,
            ), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** Neutralise spreadsheet formulas (OWASP CSV injection). */
    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    private function stream(Collection $rows, string $filename): StreamedResponse
    {
        $csv = self::toCsv($rows);

        return response()->streamDownload(function () use ($csv) {
            // UTF-8 BOM so spreadsheet apps read non-ASCII usernames correctly.
            echo "\xEF\xBB\xBF" . $csv;
        }, $filename, [
            'Content-Type'           => 'text/csv; charset=UTF-8',
            'Cache-Control'          => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param list<?string> $parts */
    private function filename(array $parts): string
    {
        $slug = collect($parts)
            ->map(fn (?string $p) => Str::slug(Str::ascii((string) $p)))
            ->filter()
            ->implode('-');
        $slug = trim(Str::limit($slug, 80, ''), '-') ?: 'club';

        return $slug . '-' . now()->format('Ymd') . '.csv';
    }
}
